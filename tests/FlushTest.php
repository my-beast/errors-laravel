<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MyBeast\Errors\Laravel\Capture\Spool;
use MyBeast\Errors\Laravel\ErrorsServiceProvider;
use MyBeast\Errors\Laravel\Facades\Errors;
use Orchestra\Testbench\TestCase;
use RuntimeException;

final class FlushTest extends TestCase
{
    private const SECRET = 'sk_abcdefghijklmnop.0123456789abcdefghijABCDEFGHIJ0123456789abc';

    private string $spool;

    protected function getPackageProviders($app): array
    {
        return [ErrorsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->spool = sys_get_temp_dir().'/mybeast-errors-spool-'.bin2hex(random_bytes(4));
        $app['config']->set('mybeast-errors.url', 'https://rec.example.test/');
        $app['config']->set('mybeast-errors.secret', self::SECRET);
        $app['config']->set('mybeast-errors.spool_path', $this->spool);
    }

    protected function tearDown(): void
    {
        array_map('unlink', [...(glob($this->spool.'/*.json') ?: []), ...array_filter([$this->spool.'/.drain.lock', $this->spool.'/.monthly-limit'], 'is_file')]);
        @rmdir($this->spool);
        parent::tearDown();
    }

    public function test_a_captured_error_waits_in_the_spool_and_goes_out_gzip_with_the_secret(): void
    {
        Http::fake(['rec.example.test/v1/errors' => Http::response(['accepted' => 1, 'duplicates' => 0, 'refused' => []], 202)]);

        report(new RuntimeException('boom'));
        $this->assertSame(1, app(Spool::class)->count());

        $this->assertSame(1, Errors::flush());
        $this->assertSame(0, app(Spool::class)->count());

        Http::assertSent(function (Request $request): bool {
            $events = json_decode((string) gzdecode($request->body()), true)['events'];

            return $request->url() === 'https://rec.example.test/v1/errors'
                && $request->hasHeader('Authorization', 'Bearer '.self::SECRET)
                && $request->hasHeader('Content-Encoding', 'gzip')
                && $events[0]['message'] === 'boom';
        });
    }

    public function test_while_the_service_is_away_the_events_wait(): void
    {
        Http::fake(['rec.example.test/*' => Http::response('down', 503)]);

        report(new RuntimeException('boom'));

        $this->assertSame(0, Errors::flush());
        $this->assertSame(1, app(Spool::class)->count());
    }

    public function test_past_the_months_limit_events_are_dropped_and_said_once_a_month(): void
    {
        Http::fakeSequence('rec.example.test/v1/errors')
            ->push(['accepted' => 0, 'duplicates' => 0, 'refused' => [['event_id' => 'a', 'reason' => 'monthly-limit']]], 202)
            ->push(['accepted' => 0, 'duplicates' => 0, 'refused' => [['event_id' => 'b', 'reason' => 'monthly-limit'], ['event_id' => 'c', 'reason' => 'too old']]], 202)
            ->push(['accepted' => 1, 'duplicates' => 0, 'refused' => []], 202);
        Log::spy();

        report(new RuntimeException('one'));
        Errors::flush();
        report(new RuntimeException('two'));
        report(new RuntimeException('three'));
        Errors::flush();

        // Dropped, not kept: the service would refuse them again until the 1st. Said once, not once each; a refusal
        // for a reason of its own still has its warning.
        $this->assertSame(0, app(Spool::class)->count());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, "used up this month's error events"))->once();
        Log::shouldHaveReceived('warning')->with('My Beast errors: the service refused an error event', ['event_id' => 'c', 'reason' => 'too old'])->once();
        Log::shouldNotHaveReceived('warning', ['My Beast errors: the service refused an error event', ['event_id' => 'a', 'reason' => 'monthly-limit']]);

        // Taken again (the 1st, or a bigger package): said once, and the next month's limit would be said again.
        report(new RuntimeException('four'));
        Errors::flush();
        Log::shouldHaveReceived('info')->with('My Beast errors: the service takes error events again.')->once();
        $this->assertNull(app(Spool::class)->note('monthly-limit'));
    }

    public function test_the_request_s_end_sends_what_it_captured(): void
    {
        Http::fake(['rec.example.test/v1/errors' => Http::response(['accepted' => 1, 'refused' => []], 202)]);

        report(new RuntimeException('boom'));
        $this->app->terminate();

        Http::assertSentCount(1);
        $this->assertSame(0, app(Spool::class)->count());
    }
}
