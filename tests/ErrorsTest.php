<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Tests;

use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;
use MyBeast\Errors\Laravel\ErrorsServiceProvider;
use MyBeast\Errors\Laravel\Facades\Errors;
use Orchestra\Testbench\TestCase;

final class ErrorsTest extends TestCase
{
    private const SIGNING_KEY = 'sig_abcdefghijklmnop.0123456789abcdef0123456789abcdef0123456';

    protected function getPackageProviders($app): array
    {
        return [ErrorsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mybeast-errors', [
            'url' => 'https://rec.example.test/',
            'public_key' => 'pk_'.str_repeat('a', 24),
            'signing_key' => self::SIGNING_KEY,
            'environment' => 'Staging',
            'token_ttl' => 1800,
        ]);
    }

    public function test_a_page_gets_its_config_and_a_signed_token_that_records_nothing(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));

        $config = Errors::clientConfig(subject: 42, account: 'ws-1', internal: true);

        $this->assertSame('https://rec.example.test', $config['url']);
        $this->assertSame('pk_'.str_repeat('a', 24), $config['key']);
        $this->assertSame('staging', $config['env']);
        $this->assertSame(1_800_001_800, $config['expires_at']);
        $this->assertArrayNotHasKey('record', $config);

        [, $body] = explode('.', $config['token']);
        $claims = (array) JWT::jsonDecode(JWT::urlsafeB64Decode($body));
        $this->assertSame('42', $claims['sub']);
        $this->assertSame('ws-1', $claims['acct']);
        $this->assertTrue($claims['internal']);
        $this->assertFalse($claims['rec']);
        $this->assertNull($claims['consent_at']);
        $this->assertSame([], Fixtures::violations($claims, 'client-token.schema.json'));
    }

    public function test_it_is_off_without_complete_config(): void
    {
        foreach ([
            ['url' => null],
            ['url' => 'not a url'],
            ['public_key' => 'pk_short'],
            ['signing_key' => null],
            ['signing_key' => 'sig_abcdefghijklmnop.too-short'],
        ] as $broken) {
            $this->app->forgetInstance(\MyBeast\Errors\Laravel\Errors::class);
            Errors::clearResolvedInstances();
            config()->set('mybeast-errors', array_merge(config('mybeast-errors'), $broken));

            $this->assertFalse(Errors::enabled(), json_encode($broken));
            $this->assertNull(Errors::clientConfig(subject: 1), json_encode($broken));
        }
    }
}
