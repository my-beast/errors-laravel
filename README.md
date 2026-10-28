# mybeast/errors-laravel

The Laravel SDK of **Errors** (services.my-beast.com/errors). It works on PHP ≥ 8.2 with Laravel 11, 12 and 13.

It does six things:
- captures every reported exception, scrubbed, and sends it to the service;
- measures requests and queued jobs for the Performance tab, once the host turns it on;
- hands browser pages their config and a signed client token;
- tells the service about releases (source maps, "it's live");
- receives the service's webhooks;
- erases a person's or an account's errors when the host asks.

Session recording is a separate product with its own SDK: `mybeast/sessions-laravel`.

## Install

```bash
composer require mybeast/errors-laravel          # from its VCS repository until it is published
php artisan vendor:publish --tag=mybeast-errors-config
```

```dotenv
MYBEAST_ERRORS_URL=https://services.my-beast.com/errors
MYBEAST_ERRORS_PUBLIC_KEY=pk_…
MYBEAST_ERRORS_SECRET=sk_….…
MYBEAST_ERRORS_SIGNING_KEY=sig_….…
MYBEAST_ERRORS_WEBHOOK_SECRET=whsec_….…
MYBEAST_ERRORS_ENVIRONMENT=production
# MYBEAST_ERRORS_RELEASE=<build>   default: APP_RELEASE, else the git commit (read from .git)
```

While the URL or the secret is missing or malformed, nothing is captured or sent. The SDK never throws at boot and
never breaks a page. In tests, set `MYBEAST_ERRORS_ENABLED=false` (phpunit.xml) or use `Errors::fake()`.

## Errors: nothing to write

The SDK hooks the exception handler's `reportable()`, so Laravel's `dontReport` list still applies. Every reported
exception becomes an event (`schemas/v1/error-event.schema.json`) with:
- the exception chain, with source lines around your own frames;
- the last 30 breadcrumbs: SQL **without values**, log lines, the route or job;
- the route, the action, and parameter and field **names** (never values);
- the release, the environment, and who it happened to (ids only);
- the request's id as your log lines carry it, so the issue opens exactly those lines in MyBeast logs: the one
  `mybeast/logs-laravel` gives the request (and the jobs it queues) when you have it - neither SDK requires the other -
  else the request's `X-Request-Id` from a proxy (`MYBEAST_ERRORS_REQUEST_ID_HEADER`).

E-mails, IBANs, personal ids, phone numbers and tokens are scrubbed before anything leaves, source lines included. The
service scrubs again.

**Sending:**
- An event goes to a spool file first, never the database (which may be what failed).
- The spool is sent right after the response, after each queued job, and every minute by `mybeast:errors-flush`,
  which the SDK puts on the scheduler.
- While the service is away, events wait in the spool (at most 5 000 files).
- Past the project's monthly limit (its package's), the service refuses the rest of the month's events and samples
  as `monthly-limit`. They are dropped, and the log says so once a month, and once when the service takes them again.

```php
use MyBeast\Errors\Laravel\Capture\Identity;
use MyBeast\Errors\Laravel\Facades\Errors;

// AppServiceProvider::boot() - who it happened to: ids, never a name or an e-mail, never by a query.
Errors::identifyUsing(fn (): ?Identity => auth()->hasUser()
    ? new Identity(subject: (string) auth()->id(), account: (string) auth()->user()->team_id, internal: auth()->user()->is_staff)
    : null);

// Your own rule for personal data, after the built-in ones.
Errors::scrubUsing(fn (string $text): string => preg_replace('/\bCUST-\d+\b/', '[customer]', $text));

// The reference a customer can quote ("mention reference 7F3A9C21") - set before anything is sent.
$reference = Errors::lastReference();
```

## Performance: off until you turn it on

```dotenv
MYBEAST_ERRORS_PERFORMANCE=true
# MYBEAST_ERRORS_PERFORMANCE_SAMPLE_RATE=0.1   the share of ordinary requests and jobs sent
# MYBEAST_ERRORS_PERFORMANCE_SLOW_MS=1000      every one at least this slow is sent too
```

It works only while errors are sent (the URL and the server secret are set). With it on, the SDK measures every request
and queued job (`schemas/v1/transaction.schema.json`) and keeps a share of the ordinary ones plus every slow or failed
one:
- a request is named by its route (`GET /posts/{post}`), never its URL, and a job by its class;
- its duration, its status, and how many database queries it ran and how long they took;
- its ten heaviest queries by **shape**: the SQL with every value taken out, and an IN list cut to `(?)`;
- an **N+1**: one query shape run 5 times or more, with the line of your code that ran it
  (`app/Http/Controllers/PostController.php:24`, or the Blade view);
- its outgoing HTTP calls, by host (the SDK's own calls to the service are left out).

**It never slows a request:**
- The measuring is a few counters per query.
- A kept sample is written to its own spool after the response has gone out.
- `mybeast:errors-flush` sends the spool every minute.

On a page with 26 queries, measuring added about 0.05 ms to the response at the default 10 % (0.15 ms with every
request kept).

A job run on the sync queue inside a request is measured on its own and also counts in the request. With performance off,
nothing is hooked at all.

## The browser's config

```php
// An Inertia shared prop, or a Blade layout.
'errors' => $user
    ? Errors::clientConfig(subject: $user->id, account: $user->team_id, internal: $user->is_staff)
    : Errors::guestConfig(),
```

The result is `{url, key, env, release, token, expires_at}` for a signed-in page, or `{url, key, env, release}` for a
guest's. It is `null` while the SDK is off. Hand it to `@mybeast/errors`' `init()`.

## Releases

Run this in your deploy, once the new release serves requests:

```bash
php artisan mybeast:errors-release   # --release=<build> --environment=<env> --sourcemaps=<dir> --files-only
```

- It uploads the browser bundle's source maps (`*.js.map` under `config('mybeast-errors.sourcemaps_path')`). Build
  with Vite `sourcemap: 'hidden'` and move the maps out of `public/`.
- Then it tells the service the release is live there. "It's fixed" notices waiting for a newer release go out at
  that moment.

## Webhooks: "tell them we're on it / it's fixed"

The service knows people only by id, so it asks you to tell them. With `MYBEAST_ERRORS_WEBHOOK_SECRET` set, the SDK
answers `POST /mybeast/errors/webhook` (`MYBEAST_ERRORS_WEBHOOK_PATH`). It checks `X-MyBeast-Signature` and refuses
anything older than five minutes, then fires an event:

```php
Event::listen(\MyBeast\Errors\Laravel\Events\CustomersShouldBeNotified::class, function ($notice) {
    // $notice->kind: 'working-on-it' | 'fixed'; $notice->people: [{subject, account, last_at, method, path}, …]
    // A delivery not answered 2xx comes again with the same $notice->deliveryId: act once per id.
});
```

Set the project's webhook URL in Errors: Settings → Events, links and webhook.

## Erasing a person or an account

When an account is closed (or a person asks), erase what the service keeps of them - it needs the server secret
(`MYBEAST_ERRORS_SECRET=sk_….…`):

```php
$erased = Errors::eraseSubject($user->id);       // a person: their events, what they were told, their webhook entries, an internal mark
$erased = Errors::eraseAccount($user->team_id);  // an account (a team, a workspace): every event in it, whoever it happened to
// how many rows went or were emptied; null while the SDK is off or has no secret
```

Each throws `MyBeast\Errors\Laravel\ErasureFailed` when the service does not confirm it: call them from a queued job
with retries, so an erasure is never lost to a network blip. Errors still waiting in the spool when it runs (the last
minute's, the closing request's own) reach the service after it and are kept: delay the job a few minutes, or erase
again then - an erasure that finds nothing answers 0.

## The demo app

`workbench/` is a small Laravel app with this SDK and performance on (Orchestra Workbench). It has a page with an N+1
(`/posts/n-plus-one`), the same page eager-loaded (`/posts`), a slow page, an outgoing call, and two queued jobs, one with
an N+1 and one that fails.

1. Open the project in Errors locally (http://localhost:8024/errors; projects come from id) and make a server secret
   in its Settings → Keys.
2. Copy `workbench/.env.example` to `workbench/.env` and paste the secret (git leaves `.env` out).
3. Run the demo:

```bash
composer demo                                   # builds its database, asks for every page, sends what was measured
vendor/bin/testbench serve --port=8022          # or click through it yourself
vendor/bin/testbench mybeast:errors-flush       # then send what it measured
```

Then open the project's **Performance** tab.

`vendor/bin/testbench` leaves a copy of `workbench/.env` in testbench's skeleton (`vendor/orchestra/testbench-core/laravel/`).
The test suite ignores it: `phpunit.xml` forces its own values.

## Tests

```php
$fake = Errors::fake();
report(new RuntimeException('boom'));
$fake->assertCaptured(fn (array $event) => $event['message'] === 'boom');
```

## Contract

`schemas/` is a copy of the Errors module's, `services/schemas/errors`. `SchemaCopyTest` fails when the two differ.

```bash
composer install && vendor/bin/phpunit
```
