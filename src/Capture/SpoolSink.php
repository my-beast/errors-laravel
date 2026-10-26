<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Capture;

use Illuminate\Support\Facades\Log;

/** The real sink: into the spool, and remember that something waits (the request's end and each job flush then). */
final class SpoolSink implements EventSink
{
    private bool $pending = false;

    public function __construct(
        private readonly Spool $spool,
    ) {}

    public function put(array $event): void
    {
        if ($this->spool->write($event)) {
            $this->pending = true;

            return;
        }

        Log::warning('MyBeast errors: an error event was dropped (the spool is full or not writable).');
    }

    public function pending(): bool
    {
        return $this->pending;
    }

    public function sent(): void
    {
        $this->pending = false;
    }
}
