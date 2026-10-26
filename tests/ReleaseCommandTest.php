<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MyBeast\Errors\Laravel\ErrorsServiceProvider;
use Orchestra\Testbench\TestCase;

final class ReleaseCommandTest extends TestCase
{
    private string $maps;

    protected function getPackageProviders($app): array
    {
        return [ErrorsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->maps = sys_get_temp_dir().'/errors-maps-'.bin2hex(random_bytes(4));
        @mkdir($this->maps.'/assets', 0755, true);
        file_put_contents($this->maps.'/assets/app-3fA9xQ1z.js.map', '{"version":3,"sources":[],"mappings":""}');

        $app['config']->set('mybeast-errors.url', 'https://rec.example.test');
        $app['config']->set('mybeast-errors.secret', 'sk_abcdefghijklmnop.0123456789abcdefghijABCDEFGHIJ0123456789abc');
        $app['config']->set('mybeast-errors.sourcemaps_path', $this->maps);
    }

    protected function tearDown(): void
    {
        @unlink($this->maps.'/assets/app-3fA9xQ1z.js.map');
        @rmdir($this->maps.'/assets');
        @rmdir($this->maps);
        parent::tearDown();
    }

    public function test_it_uploads_the_maps_then_says_the_release_is_live(): void
    {
        Http::fake([
            'rec.example.test/api/v1/releases/r-7/files/*' => Http::response(['name' => 'x'], 201),
            'rec.example.test/api/v1/releases' => Http::response(['version' => 'r-7', 'environment' => 'production', 'notified' => 2]),
        ]);

        $this->artisan('mybeast:errors-release', ['--release' => 'r-7', '--environment' => 'production'])
            ->expectsOutputToContain('1 source map(s)')
            ->expectsOutputToContain('2 person(s)')
            ->assertSuccessful();

        Http::assertSentInOrder([
            fn (Request $r): bool => $r->method() === 'PUT' && str_ends_with($r->url(), '/api/v1/releases/r-7/files/sourcemap/app-3fA9xQ1z.js.map'),
            fn (Request $r): bool => $r->method() === 'POST' && $r['version'] === 'r-7' && $r['environment'] === 'production',
        ]);
    }

    public function test_a_failed_upload_fails_the_command(): void
    {
        Http::fake(['*' => Http::response('nope', 401)]);

        $this->artisan('mybeast:errors-release', ['--release' => 'r-7'])->assertFailed();
    }
}
