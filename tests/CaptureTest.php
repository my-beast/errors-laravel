<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use MyBeast\Errors\Laravel\Capture\Identity;
use MyBeast\Errors\Laravel\Capture\Scrubber;
use MyBeast\Errors\Laravel\ErrorsServiceProvider;
use MyBeast\Errors\Laravel\Facades\Errors;
use Orchestra\Testbench\TestCase;
use PDOException;
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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32))); // The web group encrypts cookies.
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

    public function test_a_database_error_keeps_the_driver_s_code_and_loses_what_it_quotes(): void
    {
        $fake = Errors::fake();

        report(new QueryException('sqlite', 'select * from "cache" where "key" in (?)', ['k'], new PDOException('SQLSTATE[HY000]: General error: 1 no such table: cache')));
        report(new QueryException('mysql', 'insert into users (email) values (?)', ['a@b.fi'], new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'a@b.fi' for key 'users_email_unique'")));

        [$sqlite, $mysql] = array_slice($fake->events, -2);
        $this->assertStringStartsWith('SQLSTATE[HY000]: General error: 1 no such table: cache (SQL: select', $sqlite['message']);
        $this->assertStringStartsWith('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry ? for key ?', $mysql['message']);
        $this->assertStringNotContainsString('a@b.fi', $mysql['message']);
    }

    public function test_who_is_left_off_when_it_is_not_an_id_and_the_error_still_goes(): void
    {
        $fake = Errors::fake();
        Errors::identifyUsing(fn (): Identity => new Identity('jane.doe@example.com', 'ws-1'));

        report(new RuntimeException('Boom'));

        $this->assertSame([null, 'ws-1'], [$fake->last()['subject'], $fake->last()['account']]);
    }

    public function test_a_path_loses_tokens_and_addresses_and_keeps_its_ids(): void
    {
        $this->assertSame('/reset-password/[token]?email=', Scrubber::url('/reset-password/9f8e7d6c5b4a39281706f5e4d3c2b1a0?email=jane%40example.com'));
        $this->assertSame('/users/[email]/edit', Scrubber::url('/users/jane.doe%40example.com/edit'));
        $this->assertSame('/leases/01a17862-0000-7000-8000-000000000123', Scrubber::url('/leases/01a17862-0000-7000-8000-000000000123'));
        $this->assertSame('/orders/42/items/01ARZ3NDEKTSV4RRFFQ69G5FAV', Scrubber::url('/orders/42/items/01ARZ3NDEKTSV4RRFFQ69G5FAV'));
        $this->assertSame('/properties/kallio-studio', Scrubber::url('/properties/kallio-studio'));
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

    public function test_a_public_page_s_error_names_who_is_signed_in_without_looking_them_up(): void
    {
        $fake = Errors::fake();
        // No auth middleware: nothing has loaded the user when the error is reported, only the session knows them.
        // There is no users table, so a lookup would fail and leave the person off.
        Route::middleware('web')->get('boom', fn () => throw new RuntimeException('boom'));

        $this->withSession([Auth::guard()->getName() => 2])->get('boom')->assertStatus(500);
        $this->assertSame('2', $fake->last()['subject']);

        $this->flushSession();
        $this->get('boom')->assertStatus(500);
        $this->assertNull($fake->last()['subject'], 'A guest is nobody.');
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
        // Where a value could leak - not the random event id or a frame's line, which may hold "850" by chance.
        $sent = (string) json_encode([$event['message'], $event['request_url'], $event['context']['request'], $event['context']['breadcrumbs'] ?? []]);
        $this->assertStringNotContainsString('850', $sent);
        $this->assertStringNotContainsString('jane', (string) json_encode($event));
    }

    public function test_a_recorded_request_s_error_names_the_moment_in_the_recording(): void
    {
        $fake = Errors::fake();
        Route::post('leases/{lease}', fn () => throw new RuntimeException('boom'));
        $recording = '01a17862-0000-7000-8000-000000000001';

        $this->withHeaders(['X-Recording-Id' => $recording, 'X-Recording-At' => '49517'])->post('leases/77')->assertStatus(500);
        $this->assertSame(['id' => $recording, 'at_ms' => 49517], $fake->last()['context']['recording']);

        // A moment that is no number of milliseconds within a day is left out; the recording stays named.
        foreach (['-5', '4.9', 'soon', '86400001', '999999999999'] as $odd) {
            $this->withHeaders(['X-Recording-Id' => $recording, 'X-Recording-At' => $odd])->post('leases/77')->assertStatus(500);
            $this->assertSame(['id' => $recording], $fake->last()['context']['recording'], $odd);
        }
    }

    public function test_an_error_names_its_request_as_the_log_lines_do(): void
    {
        $fake = Errors::fake();
        Route::post('leases/{lease}', fn () => throw new RuntimeException('boom'));

        // A job or a command has no request of its own: mybeast/logs-laravel's id names it (a job's is the one of the
        // request that queued it), read from the hidden Context without that SDK being required.
        report(new RuntimeException('in a command'));
        $this->assertNull($fake->last()['request_id']);
        Context::addHidden('mybeast_logs_request_id', '01JBFX3W2KQ7S3VZ0H5M9N2P4R');
        report(new RuntimeException('in a job'));
        $this->assertSame('01JBFX3W2KQ7S3VZ0H5M9N2P4R', $fake->last()['request_id']);

        // In a request it wins over the request's own header: the lines carry that one.
        $this->withHeader('X-Request-Id', 'edge-7f3a9c21')->post('leases/77')->assertStatus(500);
        $this->assertSame('01JBFX3W2KQ7S3VZ0H5M9N2P4R', $fake->last()['request_id']);

        // Without it, a proxy's id; one that is no id is left off.
        Context::forgetHidden('mybeast_logs_request_id');
        $this->withHeader('X-Request-Id', 'edge-7f3a9c21')->post('leases/77')->assertStatus(500);
        $this->assertSame('edge-7f3a9c21', $fake->last()['request_id']);
        $this->assertSame([], Fixtures::violations($fake->last(), 'error-event.schema.json'));
        $this->withHeader('X-Request-Id', 'not an id at all')->post('leases/77')->assertStatus(500);
        $this->assertNull($fake->last()['request_id']);
    }

    public function test_off_without_config_and_no_reference(): void
    {
        report(new RuntimeException('nobody hears this'));

        $this->assertFalse(Errors::capturing());
        $this->assertNull(Errors::lastReference());
    }
}
