<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Tests;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use MyBeast\Errors\Laravel\ErrorsServiceProvider;
use MyBeast\Errors\Laravel\Facades\Errors;
use MyBeast\Errors\Laravel\Http\MeasureRequests;
use Orchestra\Testbench\TestCase;

/** Performance stays off until the host turns it on: a host that only sends errors measures nothing. */
final class PerformanceOffTest extends TestCase
{
    private string $spool;

    protected function getPackageProviders($app): array
    {
        return [ErrorsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->spool = sys_get_temp_dir().'/mybeast-performance-off-'.bin2hex(random_bytes(4));
        $app['config']->set('mybeast-errors.url', 'https://rec.example.test');
        $app['config']->set('mybeast-errors.secret', 'sk_abcdefghijklmnop.0123456789abcdefghijABCDEFGHIJ0123456789abc');
        $app['config']->set('mybeast-errors.performance.spool_path', $this->spool);
    }

    protected function defineRoutes($router): void
    {
        $router->get('posts', fn () => (string) DB::select('select 1 as one')[0]->one);
    }

    public function test_it_is_off_by_default(): void
    {
        $this->assertTrue(Errors::capturing());
        $this->assertFalse(Errors::measuring());
        $this->assertFalse($this->app->make(Kernel::class)->hasMiddleware(MeasureRequests::class));

        $this->get('/posts')->assertOk();

        $this->assertDirectoryDoesNotExist($this->spool);
        $this->assertSame(0, Errors::flushTransactions());
    }
}
