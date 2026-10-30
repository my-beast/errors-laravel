<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use MyBeast\Errors\Laravel\ErrorsServiceProvider;
use MyBeast\Errors\Laravel\Facades\Errors;
use Orchestra\Testbench\TestCase;
use RuntimeException;

/** Octane keeps the app between requests: nothing of one request may show in the next one's error. */
final class OctaneTest extends TestCase
{
    private string $spool;

    protected function getPackageProviders($app): array
    {
        return [ErrorsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->spool = sys_get_temp_dir().'/mybeast-errors-spool-'.bin2hex(random_bytes(4));
        $app['config']->set('mybeast-errors.url', 'https://rec.example.test/');
        $app['config']->set('mybeast-errors.secret', 'sk_abcdefghijklmnop.0123456789abcdefghijABCDEFGHIJ0123456789abc');
        $app['config']->set('mybeast-errors.spool_path', $this->spool);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->spool.'/*') ?: []);
        @rmdir($this->spool);
        parent::tearDown();
    }

    public function test_each_request_starts_with_a_clean_trail_and_no_reference(): void
    {
        $fake = Errors::fake();
        Log::info('the last request said this');
        report(new RuntimeException('Before'));
        $this->assertNotNull(Errors::lastReference());

        Event::dispatch('Laravel\Octane\Events\RequestReceived');
        $this->assertNull(Errors::lastReference());

        report(new RuntimeException('After'));
        $said = array_filter($fake->last()['context']['breadcrumbs'] ?? [], fn (array $crumb): bool => str_contains((string) $crumb['message'], 'the last request'));
        $this->assertSame([], array_values($said));
    }
}
