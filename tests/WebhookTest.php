<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use MyBeast\Errors\Laravel\ErrorsServiceProvider;
use MyBeast\Errors\Laravel\Events\CustomersShouldBeNotified;
use MyBeast\Errors\Laravel\Events\WebhookReceived;
use Orchestra\Testbench\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'webhook-secret-for-tests-only-0123456789abcdef';

    protected function getPackageProviders($app): array
    {
        return [ErrorsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mybeast-errors.webhook_secret', 'whsec_abcdefghijklmnop.'.self::SECRET);
    }

    public function test_a_signed_notify_reaches_the_host_s_listener(): void
    {
        Event::fake([CustomersShouldBeNotified::class]);
        $body = $this->body();

        $this->signed($body, time())->assertNoContent();

        Event::assertDispatched(CustomersShouldBeNotified::class, fn (CustomersShouldBeNotified $e): bool => $e->deliveryId === 'd-1'
            && $e->kind === 'fixed'
            && $e->people[0]['subject'] === 'u-1'
            && $e->issue['title'] === 'TypeError: x');
        $this->assertSame([], Fixtures::violations(json_decode($body, true), 'webhook.schema.json'));
    }

    public function test_a_wrong_or_old_signature_is_refused(): void
    {
        Event::fake([WebhookReceived::class, CustomersShouldBeNotified::class]);
        $body = $this->body();

        $this->call('POST', '/mybeast/errors/webhook', [], [], [], ['HTTP_X_MYBEAST_SIGNATURE' => 't='.time().',v1=deadbeef', 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);
        $this->signed($body, time() - 600)->assertStatus(401);
        $this->signed($body.' ', time())->assertStatus(401);

        Event::assertNothingDispatched();
    }

    public function test_either_of_two_signatures_will_do(): void
    {
        Event::fake([WebhookReceived::class, CustomersShouldBeNotified::class]);
        $body = $this->body();
        $t = time();
        $header = "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", 'an-older-secret').',v1='.hash_hmac('sha256', "{$t}.{$body}", self::SECRET);

        $this->call('POST', '/mybeast/errors/webhook', [], [], [], ['HTTP_X_MYBEAST_SIGNATURE' => $header, 'CONTENT_TYPE' => 'application/json'], $body)->assertNoContent();
    }

    private function signed(string $body, int $t, ?string $sentBody = null): TestResponse
    {
        $header = "t={$t},v1=".hash_hmac('sha256', "{$t}.".rtrim($body), self::SECRET);

        return $this->call('POST', '/mybeast/errors/webhook', [], [], [], ['HTTP_X_MYBEAST_SIGNATURE' => $header, 'CONTENT_TYPE' => 'application/json'], $sentBody ?? $body);
    }

    private function body(): string
    {
        return (string) json_encode([
            'id' => 'd-1',
            'event' => 'issue.notify',
            'created_at' => Carbon::now()->toIso8601String(),
            'data' => [
                'issue' => ['id' => 'i-1', 'title' => 'TypeError: x', 'environment' => 'production', 'url' => 'https://rec.example/projects/p/issues/i-1'],
                'kind' => 'fixed',
                'release' => 'r-2',
                'people' => [['subject' => 'u-1', 'account' => 'ws-1', 'last_at' => '2026-10-26 10:00:00.000', 'method' => 'POST', 'path' => '/leases/77']],
            ],
        ]);
    }
}
