<?php

declare(strict_types=1);

/*
 * MyBeast errors: this app's errors, sent to services.my-beast.com/errors. Every key and URL comes from the project's
 * page there (Overview → Connect a host shows the .env block; Settings shows the secret lines to admins).
 *
 * Missing or malformed values switch the SDK off - it never throws at boot and never breaks a page.
 */
return [
    // false switches everything off (tests, a local checkout without the service).
    'enabled' => (bool) env('MYBEAST_ERRORS_ENABLED', true),

    // The service, e.g. https://services.my-beast.com/errors (no trailing slash needed).
    'url' => env('MYBEAST_ERRORS_URL'),

    // pk_…: names the project in the browser. Not a secret.
    'public_key' => env('MYBEAST_ERRORS_PUBLIC_KEY'),

    // sk_….…: this server's secret for sending errors and calling the service's API.
    'secret' => env('MYBEAST_ERRORS_SECRET'),

    // sig_….…: signs the client tokens browser pages carry. Keep it on the server only.
    'signing_key' => env('MYBEAST_ERRORS_SIGNING_KEY'),

    // whsec_….…: checks what the service sends back (POST {webhook_path}). Unset: no webhook route.
    'webhook_secret' => env('MYBEAST_ERRORS_WEBHOOK_SECRET'),
    'webhook_path' => env('MYBEAST_ERRORS_WEBHOOK_PATH', 'mybeast/errors/webhook'),

    // Sent with everything: production, staging, local, …
    'environment' => env('MYBEAST_ERRORS_ENVIRONMENT', env('APP_ENV', 'production')),

    // The release (build) this process runs; unset = the git commit, read from .git (no shell).
    'release' => env('MYBEAST_ERRORS_RELEASE', env('APP_RELEASE')),

    // A server error names its request as the app's log lines do, so its issue opens exactly them in Logs: the id
    // mybeast/logs-laravel gives it (nothing to set), else this header of the request, when a proxy sets one.
    'request_id_header' => env('MYBEAST_ERRORS_REQUEST_ID_HEADER', 'X-Request-Id'),

    // How long a client token lives, in seconds (at most 3600; the page gets a fresh one on every response, and a page
    // left open asks GET {token_path} for one before it runs out - false: no such route, the host renews it itself).
    'token_ttl' => (int) env('MYBEAST_ERRORS_TOKEN_TTL', 1800),
    'token_path' => env('MYBEAST_ERRORS_TOKEN_PATH', 'mybeast/errors/token'),

    // Where captured errors wait to be sent (files - never the database, which may be what failed).
    'spool_path' => env('MYBEAST_ERRORS_SPOOL_PATH', storage_path('app/private/mybeast-errors-spool')),

    // Breadcrumbs kept per request or job, and lines of source around each in-app frame.
    'breadcrumbs' => 30,
    'context_lines' => 5,

    /*
     * Performance (the service's Performance tab): how long requests and queued jobs take, their database queries
     * (shapes - never values), queries run once per row (N+1) and outgoing HTTP calls. Off until
     * MYBEAST_ERRORS_PERFORMANCE=true, and only while errors are sent (URL and server secret). Then a share of the
     * ordinary ones and every slow or failed one is measured into a spool of its own after the response has gone out,
     * and sent every minute by mybeast:errors-flush - never while a request waits.
     */
    'performance' => [
        'enabled' => (bool) env('MYBEAST_ERRORS_PERFORMANCE', false),

        // The share of ordinary requests and jobs sent: 0.1 = one in ten.
        'sample_rate' => (float) env('MYBEAST_ERRORS_PERFORMANCE_SAMPLE_RATE', 0.1),

        // Every request or job at least this slow is sent, in milliseconds.
        'slow_ms' => (int) env('MYBEAST_ERRORS_PERFORMANCE_SLOW_MS', 1000),

        // A query of one shape run this many times in one request or job is reported as an N+1.
        'n_plus_one' => 5,

        'spool_path' => env('MYBEAST_ERRORS_PERFORMANCE_SPOOL_PATH', storage_path('app/private/mybeast-errors-performance')),
    ],

    // mybeast:errors-release uploads these: the browser bundle's private source maps (Vite `sourcemap: 'hidden'`, moved out
    // of public/ after the build) and the fonts a page uses (globs; a leftover of the joint Recorder - replay now
    // belongs to Sessions, services.my-beast.com/sessions, so this moves there).
    'sourcemaps_path' => env('MYBEAST_ERRORS_SOURCEMAPS_PATH', storage_path('app/private/sourcemaps')),
    'fonts' => [],
];
