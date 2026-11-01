<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Transport;

use Illuminate\Support\Facades\Log;
use MyBeast\Errors\Laravel\Capture\Spool;
use MyBeast\Errors\Laravel\Capture\SpoolSink;

/**
 * Sends the spool to the service: right after a response went out (the request's terminating callbacks), after each
 * queued job, and every minute (mybeast:errors-flush) for whatever is left. A request's flush is short - it must never
 * hold a PHP worker for long; the minutely run takes its time.
 */
final readonly class Flusher
{
    public function __construct(
        private Spool $spool,
        private SpoolSink $sink,
        private ?ServiceClient $client,
    ) {}

    /** After a response or a job: only when this process captured something. */
    public function flushIfPending(): void
    {
        if ($this->sink->pending()) {
            $this->flush(timeout: 3, maxBatches: 2);
        }
    }

    /** @return int how many events went out */
    public function flush(int $timeout = 15, int $maxBatches = 20): int
    {
        if ($this->client === null) {
            return 0;
        }

        $client = $this->client;
        $limit = new MonthlyLimit($this->spool, 'error events');
        $sent = $this->spool->drain(function (array $events) use ($client, $timeout, $limit): bool {
            try {
                $result = $client->sendErrors($events, $timeout);
            } catch (TransportFailed $e) {
                Log::warning('My Beast errors: errors wait in the spool', ['reason' => $e->getMessage()]);

                return false;
            }

            foreach ($limit->sort($result['refused'] ?? [], ($result['accepted'] ?? 0) + ($result['duplicates'] ?? 0)) as $refused) {
                Log::warning('My Beast errors: the service refused an error event', is_array($refused) ? $refused : []);
            }

            return true;
        }, maxBatches: $maxBatches);

        if ($this->spool->count() === 0) {
            $this->sink->sent();
        }

        return $sent;
    }
}
