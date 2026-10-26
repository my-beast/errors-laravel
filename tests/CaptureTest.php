<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Tests;

use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use MyBeast\Errors\Laravel\Capture\Identity;
use MyBeast\Errors\Laravel\Capture\Scrubber;
use MyBeast\Errors\Laravel\ErrorsServiceProvider;
use MyBeast\Errors\Laravel\Facades\Errors;
use Orchestra\Testbench\TestCase;
use RuntimeException;

final class CaptureTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ErrorsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mybeast-errors.environment', 'Staging');
        $app['config']->set('mybeast-errors.release', 'r-42');
    }

    protected function tearDown(): void
    {
        Scrubber::forget();
        parent::tearDown();
    }

    public function test_a_reported_exception_becomes_an_event_that_fits_the_contract(): void
    {
        $fake = Errors::fake();

        report(new RuntimeException('User jane.doe@example.com has no lease FI2112345600000785', previous: new RuntimeException('inner')));

        $event = $fake->last();
        $this->assertSame('User [email] has no lease [iban]', $event['message']);
        $this->assertSame(RuntimeException::class, $event['exception_class']);
        $this->assertCount(2, $event['exceptions']);
        $this->assertSame('staging', $event['environment']);
        $this->assertSame('r-42', $event['release']);
        $this->assertSame('cli', $event['context']['tags']['handled_by']);
        $this->assertSame(Errors::lastReference(), strtoupper(substr(str_replace('-', '', $event['event_id']), -8)));
        $this->assertSame([], Fixtures::violations($event, 'error-event.schema.json'));
    }

    public function test_laravel_s_dont_report_list_still_applies(): void
    {
        $fake = Errors::fake();

        report(ValidationException::withMessages(['email' => 'x']));

        $fake->assertNothingCaptured();
    }

    public function test_the_host_says_who_and_adds_its_own_scrubbing(): void
    {
        $fake = Errors::fake();
        Errors::identifyUsing(fn (): Identity => new Identity('u-9', 'ws-1', internal: true));
        Errors::scrubUsing(fn (string $text): string => str_replace('Virtanen', '[name]', $text));

        report(new RuntimeException('Tenant Virtanen missing'));

        $event = $fake->last();
        $this->assertSame(['u-9', 'ws-1', true], [$event['subject'], $event['account'], $event['internal']]);
        $this->assertSame('Tenant [name] missing', $event['message']);
    }

    public function test_a_request_s_error_names_the_route_and_fields_never_values(): void
    {
        $fake = Errors::fake();
        Route::post('leases/{lease}', fn () => throw new RuntimeException('boom'))->name('leases.update');

        $this->withHeader('X-Recording-Id', '01A17862-0000-7000-8000-000000000001')
            ->post('leases/77?tab=money&q=Virtanen', ['rent' => '850', 'note' => 'call jane@example.com'])
            ->assertStatus(500);

        $event = $fake->last();
        $this->assertSame('/leases/77?tab=&q=', $event['request_url']);
        $this->assertSame('POST', $event['request_method']);
        $this->assertSame('leases.update', $event['context']['request']['route']);
        // Names of what was sent, body and query alike - never a value.
        $this->assertSame(['rent', 'note', 'tab', 'q'], $event['context']['request']['fields']);
        $this->assertSame(['id' => '01a17862-0000-7000-8000-000000000001'], $event['context']['recording']);
        $this->assertStringNotContainsString('850', (string) json_encode($event));
        $this->assertStringNotContainsString('jane', (string) json_encode($event));
    }

    public function test_off_without_config_and_no_reference(): void
    {
        report(new RuntimeException('nobody hears this'));

        $this->assertFalse(Errors::capturing());
        $this->assertNull(Errors::lastReference());
    }
}
