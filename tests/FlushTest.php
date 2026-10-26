<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
        array_map('unlink', [...(glob($this->spool.'/*.json') ?: []), ...(is_file($this->spool.'/.drain.lock') ? [$this->spool.'/.drain.lock'] : [])]);
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

    public function test_the_request_s_end_sends_what_it_captured(): void
    {
        Http::fake(['rec.example.test/v1/errors' => Http::response(['accepted' => 1, 'refused' => []], 202)]);

        report(new RuntimeException('boom'));
        $this->app->terminate();

        Http::assertSentCount(1);
        $this->assertSame(0, app(Spool::class)->count());
    }
}
