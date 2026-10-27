<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Workbench\App\Jobs\RebuildPostIndex;

/*
 * Traffic for the demo without a web server: each page asked for through the app's HTTP kernel - the same middleware,
 * the same "after the response" step as under PHP-FPM - and a few jobs off the queue, then the spool sent.
 */
Artisan::command('demo:traffic {--rounds=20 : How many times each page is asked for}', function (Kernel $http): void {
    $pages = ['/posts', '/posts/n-plus-one', '/posts/3', '/posts/7', '/status', '/jobs/rebuild', '/jobs/import'];
    $rounds = max(1, (int) $this->option('rounds'));

    for ($round = 0; $round < $rounds; $round++) {
        foreach ($pages as $page) {
            $request = Request::create($page);
            $response = $http->handle($request);
            $http->terminate($request, $response);
        }

        RebuildPostIndex::dispatch();
    }

    foreach (range(1, 2) as $slow) {
        $request = Request::create('/report');
        $http->terminate($request, $http->handle($request));
    }

    $this->info(($rounds * count($pages) + 2).' requests and '.$rounds.' jobs handled.');
    $this->call('mybeast:errors-flush');
})->purpose('Ask the demo app for its pages and run its jobs, then send what was measured');
