<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/** A queued job that fails: measured as failed (and reported as an error). */
final class ImportFeed implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        usleep(30_000);

        throw new RuntimeException('The feed answered 502.');
    }
}
