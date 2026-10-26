<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MyBeast\Errors\Laravel\ErasureFailed;
use MyBeast\Errors\Laravel\ErrorsServiceProvider;
use MyBeast\Errors\Laravel\Facades\Errors;
use MyBeast\Errors\Laravel\Transport\TransportFailed;
use Orchestra\Testbench\TestCase;

/** A person or an account erased on the host's request: the server secret asks, the service answers how many went. */
final class ErasureTest extends TestCase
{
    private const SECRET = 'sk_abcdefghijklmnop.0123456789abcdefghijABCDEFGHIJ0123456789abc';

    protected function getPackageProviders($app): array
    {
        return [ErrorsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mybeast-errors.url', 'https://rec.example.test/');
        $app['config']->set('mybeast-errors.secret', self::SECRET);
    }

    public function test_a_person_and_an_account_are_erased_with_the_server_secret(): void
    {
        Http::fake([
            'rec.example.test/api/v1/subjects/*' => Http::response(['erased' => 3]),
            'rec.example.test/api/v1/accounts/*' => Http::response(['erased' => 0]),
        ]);

        $this->assertSame(3, Errors::eraseSubject(42));
        $this->assertSame(0, Errors::eraseAccount('auth0:ws.1_a-b'), 'Nothing kept is no error.');

        Http::assertSentInOrder([
            fn (Request $r): bool => $r->method() === 'DELETE'
                && $r->url() === 'https://rec.example.test/api/v1/subjects/42'
                && $r->hasHeader('Authorization', 'Bearer '.self::SECRET),
            fn (Request $r): bool => $r->method() === 'DELETE'
                && $r->url() === 'https://rec.example.test/api/v1/accounts/auth0%3Aws.1_a-b'
                && $r->hasHeader('Authorization', 'Bearer '.self::SECRET),
        ]);
    }

    public function test_off_or_without_a_server_secret_nothing_is_asked(): void
    {
        Http::fake();
        $base = config('mybeast-errors');

        foreach ([['enabled' => false], ['url' => null], ['url' => 'not a url'], ['secret' => null], ['secret' => 'sk_short.x'], ['secret' => 'pk_'.str_repeat('a', 24)]] as $broken) {
            $this->app->forgetInstance(\MyBeast\Errors\Laravel\Errors::class);
            Errors::clearResolvedInstances();
            config()->set('mybeast-errors', array_merge($base, $broken));

            $this->assertNull(Errors::eraseSubject('u-1'), json_encode($broken));
            $this->assertNull(Errors::eraseAccount('ws-1'), json_encode($broken));
        }

        Http::assertNothingSent();
    }

    public function test_an_id_the_service_never_takes_has_nothing_to_erase(): void
    {
        Http::fake();

        $this->assertSame(0, Errors::eraseSubject('jane@example.com'));
        $this->assertSame(0, Errors::eraseAccount(str_repeat('a', 65)));
        $this->assertSame(0, Errors::eraseAccount(''));

        Http::assertNothingSent();
    }

    public function test_a_failing_service_is_an_erasure_to_try_again(): void
    {
        Http::fake(['*' => Http::response(['message' => 'down'], 503)]);

        $this->assertErasureFails(fn () => Errors::eraseSubject('u-1'), '503');
    }

    public function test_an_unreachable_service_is_an_erasure_to_try_again(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $failure = $this->assertErasureFails(fn () => Errors::eraseAccount('ws-1'), 'Connection timed out');
        $this->assertInstanceOf(TransportFailed::class, $failure->getPrevious());
    }

    public function test_an_answer_without_a_count_is_not_a_confirmation(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('<html>Maintenance</html>')
            ->push(['deleted' => 2])
            ->push(['erased' => '2'])
            ->push([])]);

        foreach (range(1, 4) as $answer) {
            $this->assertErasureFails(fn () => Errors::eraseSubject('u-1'), 'did not say how many');
        }

        Http::assertSentCount(4);
    }

    private function assertErasureFails(callable $erase, string $why): ErasureFailed
    {
        try {
            $erase();
        } catch (ErasureFailed $e) {
            $this->assertStringContainsString($why, $e->getMessage());

            return $e;
        }

        $this->fail('The erasure was taken as confirmed.');
    }
}
