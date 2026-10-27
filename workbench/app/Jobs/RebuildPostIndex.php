<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Workbench\App\Models\Post;

/** A queued job with an N+1 of its own: each post's comments counted one by one. */
final class RebuildPostIndex implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        foreach (Post::query()->get() as $post) {
            $post->comments()->count();
        }
    }
}
