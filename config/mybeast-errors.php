<?php

declare(strict_types=1);

/*
 * MyBeast errors: this app's errors, sent to errors.my-beast.com. Every key and URL comes from the project's page
 * there (Overview → Connect a host shows the .env block; Settings shows the secret lines to admins).
 *
 * Missing or malformed values switch the SDK off - it never throws at boot and never breaks a page.
 */
return [
    // false switches everything off (tests, a local checkout without the service).
    'enabled' => (bool) env('MYBEAST_ERRORS_ENABLED', true),

    // The service, e.g. https://errors.my-beast.com (no trailing slash needed).
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

    // How long a client token lives, in seconds (at most 3600; the page gets a fresh one on every response).
    'token_ttl' => (int) env('MYBEAST_ERRORS_TOKEN_TTL', 1800),

    // Where captured errors wait to be sent (files - never the database, which may be what failed).
    'spool_path' => env('MYBEAST_ERRORS_SPOOL_PATH', storage_path('app/private/mybeast-errors-spool')),

    // Breadcrumbs kept per request or job, and lines of source around each in-app frame.
    'breadcrumbs' => 30,
    'context_lines' => 5,

    // mybeast:errors-release uploads these: the browser bundle's private source maps (Vite `sourcemap: 'hidden'`, moved out
    // of public/ after the build) and the fonts a page uses (globs; a leftover of the joint Recorder - replay now
    // belongs to sessions.my-beast.com, so this moves there).
    'sourcemaps_path' => env('MYBEAST_ERRORS_SOURCEMAPS_PATH', storage_path('app/private/sourcemaps')),
    'fonts' => [],
];
