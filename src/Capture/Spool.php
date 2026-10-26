<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Capture;

use Illuminate\Support\Str;

/**
 * The outbox: every captured event is written here first - one JSON file each, atomically, never touching the
 * host's database (the error may BE the database) - and sent to the service from here (Transport\Flusher). While
 * the service is away the files wait; a cap keeps a crash loop from filling the disk.
 */
final class Spool
{
    private const MAX_FILES = 5000;

    public function __construct(
        private readonly string $directory,
    ) {}

    /** @param  array<string, mixed>  $event */
    public function write(array $event): bool
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0750, true) && ! is_dir($this->directory)) {
            return false;
        }

        if ($this->count() >= self::MAX_FILES) {
            return false;
        }

        $name = $this->directory.'/'.Str::ulid().'.json';
        $tmp = $name.'.tmp';

        if (@file_put_contents($tmp, json_encode($event, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) === false) {
            return false;
        }

        return @rename($tmp, $name);
    }

    public function count(): int
    {
        return is_dir($this->directory) ? count(glob($this->directory.'/*.json') ?: []) : 0;
    }

    /**
     * Hands the oldest files to $send in batches; a batch's files are removed only when $send says it went through.
     * One drain at a time (a file lock): the request that just captured and the minutely command may both try.
     *
     * @param  callable(list<array<string, mixed>>): bool  $send
     * @return int how many events went out
     */
    public function drain(callable $send, int $batchSize = 50, int $maxBatches = 20): int
    {
        if (! is_dir($this->directory)) {
            return 0;
        }

        $lock = @fopen($this->directory.'/.drain.lock', 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return 0;
        }

        try {
            $sent = 0;

            for ($batch = 0; $batch < $maxBatches; $batch++) {
                $files = glob($this->directory.'/*.json') ?: [];
                sort($files);
                $files = array_slice($files, 0, $batchSize);

                if ($files === []) {
                    break;
                }

                $events = [];

                foreach ($files as $file) {
                    $event = json_decode((string) @file_get_contents($file), true);

                    if (is_array($event)) {
                        $events[] = $event;
                    }
                }

                if ($events !== [] && ! $send($events)) {
                    break; // The service is away: the files wait for the next try.
                }

                foreach ($files as $file) {
                    @unlink($file);
                }

                $sent += count($events);
            }

            return $sent;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
