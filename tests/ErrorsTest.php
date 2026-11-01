<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Tests;

use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use MyBeast\Errors\Laravel\Capture\Contract;
use MyBeast\Errors\Laravel\Capture\Identity;
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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32))); // The web group encrypts cookies.
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

    public function test_a_page_never_breaks_on_an_id_that_is_not_one(): void
    {
        Log::spy();
        Contract::forget();

        $guest = Errors::clientConfig(subject: 'jane.doe@example.com', account: 'ws-1');
        $this->assertArrayNotHasKey('token', $guest, 'an e-mail is no subject: the page reports as a guest');
        $this->assertSame('staging', $guest['env']);
        $this->assertSame('eu-prod-2', Contract::environment('EU.prod 2'));
        $this->assertSame('production', Contract::environment(' . '));

        $signed = Errors::clientConfig(subject: 42, account: 'Acme Oy');
        [, $body] = explode('.', $signed['token']);
        $this->assertNull(((array) JWT::jsonDecode(JWT::urlsafeB64Decode($body)))['acct'] ?? null, 'an account that is not an id is left off');

        Errors::clientConfig(subject: 'jane.doe@example.com');
        Log::shouldHaveReceived('warning')->twice(); // once for the subject, once for the account - not on every page
    }

    public function test_a_page_left_open_gets_a_fresh_token_for_whoever_is_signed_in(): void
    {
        $this->assertSame('/mybeast/errors/token', Errors::clientConfig(subject: 42)['token_url']);

        $this->get('/mybeast/errors/token')->assertNoContent();

        // The route has no auth middleware, so nothing has loaded the user: the session's id says who it is for.
        $token = $this->withSession([Auth::guard()->getName() => 42])->get('/mybeast/errors/token')->assertOk()->json('token');
        $this->assertSame('42', ((array) JWT::jsonDecode(JWT::urlsafeB64Decode(explode('.', $token)[1])))['sub']);
        $this->flushSession();

        Errors::identifyUsing(fn (): Identity => new Identity('u-9', 'ws-1'));
        $answer = $this->get('/mybeast/errors/token')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        [, $body] = explode('.', $answer->json('token'));
        $claims = (array) JWT::jsonDecode(JWT::urlsafeB64Decode($body));
        $this->assertSame(['u-9', 'ws-1'], [$claims['sub'], $claims['acct']]);
        $this->assertSame($claims['exp'], $answer->json('expires_at'));
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
