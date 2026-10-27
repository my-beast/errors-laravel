<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Workbench\App\Jobs\ImportFeed;
use Workbench\App\Jobs\RebuildPostIndex;
use Workbench\App\Models\Post;

/*
 * The demo's pages. With performance on (workbench/.env) each request is measured and, when kept, sent to the errors
 * service's Performance tab by `mybeast:errors-flush`.
 */
Route::get('/', fn () => response(implode("\n", [
    'GET /posts              the posts with their comments, eager-loaded: 2 queries',
    'GET /posts/n-plus-one   the same page with an N+1: one more query per post',
    'GET /posts/{post}       one post',
    'GET /report             slow: over the SDK\'s slow threshold, so always sent',
    'GET /status             an outgoing HTTP call',
    'GET /jobs/rebuild       a queued job with an N+1 of its own',
    'GET /jobs/import        a queued job that fails',
]), 200, ['Content-Type' => 'text/plain; charset=UTF-8']));

Route::get('posts', fn () => Post::query()->with('comments')->get()
    ->map(fn (Post $post): string => $post->title.' ('.$post->comments->count().' comments)')
    ->implode("\n"));

// The N+1: the comments are loaded post by post, inside the loop.
Route::get('posts/n-plus-one', fn () => Post::query()->get()
    ->map(fn (Post $post): string => $post->title.' ('.$post->comments->count().' comments)')
    ->implode("\n"));

Route::get('posts/{post}', fn (string $post) => Post::query()->findOrFail($post)->title)->whereNumber('post');

Route::get('report', function () {
    usleep(1_200_000);

    return 'The monthly report.';
});

// Errors' health check (services/' /up): the SDK leaves its own calls to the service (by its URL's host) out.
Route::get('status', fn () => 'Errors answered '.Http::timeout(3)->get('http://127.0.0.1:8024/up')->status().'.');

// The sync queue runs a job right here: it is measured on its own, and counts in the request too.
Route::get('jobs/rebuild', function () {
    RebuildPostIndex::dispatch();

    return 'Rebuilt.';
});

Route::get('jobs/import', function () {
    try {
        ImportFeed::dispatch();
    } catch (RuntimeException $e) {
        report($e);
    }

    return 'The import failed (on purpose).';
});
