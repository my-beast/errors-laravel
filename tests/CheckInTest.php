<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MyBeast\Errors\Laravel\ErrorsServiceProvider;
use Orchestra\Testbench\TestCase;

/** Whether Errors hears this app: an hourly check-in, and mybeast:errors-test saying so in words. */
final class CheckInTest extends TestCase
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
        $app['config']->set('mybeast-errors.environment', 'Local');
        $app['config']->set('mybeast-errors.release', 'r-42');
    }

    protected function tearDown(): void
    {
        array_map('unlink', [...(glob($this->spool.'/*') ?: []), ...array_filter([$this->spool.'/.checkin', $this->spool.'/.drain.lock'], 'is_file')]);
        @rmdir($this->spool);
        parent::tearDown();
    }

    public function test_the_test_command_checks_in_and_says_who_was_heard(): void
    {
        Http::fake(['rec.example.test/v1/checkins' => Http::response(['heard' => true, 'project' => 'asuvaan', 'environment' => 'local'], 202)]);

        $this->artisan('mybeast:errors-test')
            ->expectsOutputToContain('Environment       local')
            ->expectsOutput('Errors heard asuvaan (local). Its overview says so under "What arrives".')
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === 'https://rec.example.test/v1/checkins'
                && $request->hasHeader('Authorization', 'Bearer sk_abcdefghijklmnop.0123456789abcdefghijABCDEFGHIJ0123456789abc')
                && $body['environment'] === 'local' && $body['sdk'] === 'laravel' && $body['release'] === 'r-42' && $body['test'] === true
                && str_starts_with($body['runtime'], 'php '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.' · laravel ')
                && Fixtures::violations($body, 'checkin.schema.json') === [];
        });
    }

    public function test_a_refused_secret_is_said_in_words(): void
    {
        Http::fake(['rec.example.test/v1/checkins' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->artisan('mybeast:errors-test')
            ->expectsOutputToContain('Errors refused this server secret')
            ->assertFailed();
    }

    public function test_the_scheduler_s_flush_checks_in_once_an_hour(): void
    {
        Http::fake(['rec.example.test/v1/checkins' => Http::response(['heard' => true], 202)]);

        $this->artisan('mybeast:errors-flush')->assertSuccessful();
        $this->artisan('mybeast:errors-flush')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertArrayNotHasKey('test', Http::recorded()[0][0]->data(), 'the scheduler\'s is no test');
    }
}
