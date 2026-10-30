<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Tests;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use MyBeast\Errors\Laravel\ErrorsServiceProvider;
use MyBeast\Errors\Laravel\Facades\Errors;
use MyBeast\Errors\Laravel\Http\MeasureRequests;
use MyBeast\Errors\Laravel\Performance\QueryShape;
use MyBeast\Errors\Laravel\Performance\Recorder;
use Orchestra\Testbench\TestCase;
use RuntimeException;

final class PerformanceTest extends TestCase
{
    private const SECRET = 'sk_abcdefghijklmnop.0123456789abcdefghijABCDEFGHIJ0123456789abc';

    private string $spool;

    private string $errorSpool;

    protected function getPackageProviders($app): array
    {
        return [ErrorsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->spool = sys_get_temp_dir().'/mybeast-performance-spool-'.bin2hex(random_bytes(4));
        $this->errorSpool = sys_get_temp_dir().'/mybeast-errors-spool-'.bin2hex(random_bytes(4));
        // In memory, whatever the workbench demo left in the skeleton's database.sqlite.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('mybeast-errors.url', 'https://rec.example.test');
        $app['config']->set('mybeast-errors.secret', self::SECRET);
        $app['config']->set('mybeast-errors.environment', 'staging');
        $app['config']->set('mybeast-errors.release', 'r-7');
        $app['config']->set('mybeast-errors.spool_path', $this->errorSpool);
        $app['config']->set('mybeast-errors.performance', [
            'enabled' => true,
            'sample_rate' => 1.0,
            'slow_ms' => 60_000,
            'n_plus_one' => 5,
            'spool_path' => $this->spool,
        ]);
    }

    protected function defineRoutes($router): void
    {
        // One query per post: the N+1 a list page falls into.
        $router->get('posts', function () {
            foreach (range(1, 6) as $id) {
                DB::table('posts')->where('id', $id)->first();
            }

            return 'ok';
        });
        $router->get('posts/{post}', fn (string $post) => (string) DB::table('posts')->where('id', $post)->value('title'));
        $router->get('search', function () {
            DB::select("select * from posts where title = 'Jane Doe' and id in (1, 2, 3) and author_id = ?", [7]);

            return 'ok';
        });
        $router->get('rates', fn () => Http::get('https://api.example.test/rates')->body());
        $router->get('broken', fn () => abort(500));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->unsignedInteger('author_id')->nullable();
        });
        DB::table('posts')->insert(array_map(fn (int $i): array => ['title' => "Post {$i}"], range(1, 6)));
    }

    protected function tearDown(): void
    {
        foreach ([$this->spool, $this->errorSpool] as $dir) {
            array_map('unlink', [...(glob($dir.'/*.json') ?: []), ...array_filter([$dir.'/.drain.lock', $dir.'/.monthly-limit'], 'is_file')]);
            @rmdir($dir);
        }

        parent::tearDown();
    }

    public function test_a_request_is_measured_after_its_response_and_fits_the_contract(): void
    {
        $this->assertTrue($this->app->make(Kernel::class)->hasMiddleware(MeasureRequests::class));

        $this->get('/posts')->assertOk();

        $transaction = $this->only();
        $this->assertSame([], Fixtures::violations($transaction, 'transaction.schema.json'));
        $this->assertSame('request', $transaction['kind']);
        $this->assertSame('GET /posts', $transaction['name']);
        $this->assertSame(200, $transaction['status']);
        $this->assertFalse($transaction['failed']);
        $this->assertSame('sample', $transaction['kept']);
        $this->assertSame(['staging', 'r-7'], [$transaction['environment'], $transaction['release']]);
        $this->assertSame(6, $transaction['db']['count']);
        $this->assertSame('select * from "posts" where "id" = ? limit ?', $transaction['queries'][0]['sql']);
        $this->assertSame(6, $transaction['queries'][0]['count']);

        // Six runs of one shape: an N+1, with the line of the host's code that ran it.
        $this->assertCount(1, $transaction['n_plus_one']);
        $this->assertSame(6, $transaction['n_plus_one'][0]['count']);
        // The last three parts of the path: the SDK's folder is `laravel` here and the repository's name in CI.
        $this->assertMatchesRegularExpression('#^…/[^/]+/tests/PerformanceTest\.php:\d+$#', $transaction['n_plus_one'][0]['origin']);
    }

    public function test_a_request_is_named_by_its_route_and_its_queries_keep_no_values(): void
    {
        $this->get('/posts/3')->assertOk();
        $this->assertSame('GET /posts/{post}', $this->only()['name']);
        $this->clearSpool();

        $this->get('/search')->assertOk();
        $this->assertSame('select * from posts where title = ? and id in (?) and author_id = ?', $this->only()['queries'][0]['sql']);
    }

    public function test_when_none_is_drawn_only_slow_and_failed_ones_are_sent(): void
    {
        config(['mybeast-errors.performance.sample_rate' => 0.0]);
        $this->app->forgetInstance(Recorder::class);

        $this->get('/posts/1')->assertOk();
        $this->assertSame([], $this->spooled());

        $this->get('/broken')->assertStatus(500);
        $this->assertSame(['failed', 500, true], [$this->only()['kept'], $this->only()['status'], $this->only()['failed']]);
        $this->clearSpool();

        config(['mybeast-errors.performance.slow_ms' => 0]);
        $this->app->forgetInstance(Recorder::class);
        $this->get('/posts/1')->assertOk();
        $this->assertSame('slow', $this->only()['kept']);
    }

    public function test_a_queued_job_is_measured_on_its_own_and_a_failed_one_says_so(): void
    {
        dispatch(fn () => DB::table('posts')->count());

        $job = $this->only();
        $this->assertSame('job', $job['kind']);
        $this->assertStringStartsWith('Closure', $job['name']);
        $this->assertSame(1, $job['db']['count']);
        $this->assertNull($job['status']);
        $this->assertSame([], Fixtures::violations($job, 'transaction.schema.json'));
        $this->clearSpool();

        try {
            dispatch(fn () => throw new RuntimeException('no'));
        } catch (RuntimeException) {
            // The sync queue throws it on.
        }

        $this->assertSame(['job', true, 'failed'], [$this->only()['kind'], $this->only()['failed'], $this->only()['kept']]);
    }

    public function test_outgoing_calls_are_counted_by_host(): void
    {
        Http::fake(['api.example.test/*' => Http::response('1.08')]);

        $this->get('/rates')->assertOk();

        $this->assertSame('api.example.test', $this->only()['http'][0]['host']);
        $this->assertSame(1, $this->only()['http'][0]['count']);
    }

    public function test_the_spool_goes_out_gzip_to_v1_transactions_and_waits_while_the_service_is_away(): void
    {
        $this->get('/posts/1');
        $this->get('/posts/2');

        Http::fakeSequence('rec.example.test/*')
            ->push('down', 503)
            ->push(['accepted' => 2, 'duplicates' => 0, 'refused' => []], 202);

        $this->assertSame(0, Errors::flushTransactions());
        $this->assertCount(2, $this->spooled());

        $this->assertSame(2, Errors::flushTransactions());
        $this->assertSame([], $this->spooled());

        Http::assertSent(function (Request $request): bool {
            $transactions = json_decode((string) gzdecode($request->body()), true)['transactions'] ?? [];

            return $request->url() === 'https://rec.example.test/v1/transactions'
                && $request->hasHeader('Authorization', 'Bearer '.self::SECRET)
                && $request->hasHeader('Content-Encoding', 'gzip')
                && count($transactions) === 2
                && $transactions[0]['name'] === 'GET /posts/{post}';
        });

        $this->artisan('mybeast:errors-flush')->expectsOutputToContain('Sent 0 performance sample(s).')->assertSuccessful();
    }

    public function test_past_the_months_limit_samples_are_dropped_and_said_once(): void
    {
        $this->get('/posts/1');
        $this->get('/posts/2');
        Http::fake(['rec.example.test/*' => Http::response(['accepted' => 0, 'duplicates' => 0, 'refused' => [['id' => 'x', 'reason' => 'monthly-limit'], ['id' => 'y', 'reason' => 'monthly-limit']]], 202)]);
        Log::spy();

        $this->assertSame(2, Errors::flushTransactions());
        $this->assertSame([], $this->spooled());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => str_contains($message, "used up this month's performance samples") && $context === ['dropped' => 2])->once();
        Log::shouldNotHaveReceived('warning', ['MyBeast errors: the service refused a performance sample', ['id' => 'x', 'reason' => 'monthly-limit']]);
    }

    public function test_a_query_shape_keeps_names_and_drops_every_value(): void
    {
        $this->assertSame('select * from "t1" where "2fa" = ? and `col9` in (?) and x = $1', QueryShape::of("select *\n  from \"t1\" where \"2fa\" = 'it''s' and `col9` in (1, 2,3) and x = \$1"));
        $this->assertSame('update users set score = ? where id = ?', QueryShape::of('update users set score = -1.5 where id = 42'));
        $this->assertSame('select * from a where id in (?)', QueryShape::of('select * from a where id in (?, ?, ?)'));
    }

    /** @return list<array<string, mixed>> */
    private function spooled(): array
    {
        $files = glob($this->spool.'/*.json') ?: [];
        sort($files);

        return array_map(fn (string $file): array => json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR), $files);
    }

    /** @return array<string, mixed> */
    private function only(): array
    {
        $spooled = $this->spooled();
        $this->assertCount(1, $spooled);

        return $spooled[0];
    }

    private function clearSpool(): void
    {
        array_map('unlink', glob($this->spool.'/*.json') ?: []);
    }
}
