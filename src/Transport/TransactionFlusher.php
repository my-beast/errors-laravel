<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Transport;

use Illuminate\Support\Facades\Log;
use MyBeast\Errors\Laravel\Capture\Spool;

/**
 * Sends the performance spool to POST /v1/transactions, every minute (mybeast:errors-flush) - never from a request or
 * a job: measuring must not hold a PHP worker for a call to the service.
 */
final readonly class TransactionFlusher
{
    private const BATCH = 100;

    public function __construct(
        private Spool $spool,
        private ?ServiceClient $client,
    ) {}

    /** @return int how many transactions went out */
    public function flush(int $timeout = 15, int $maxBatches = 20): int
    {
        if ($this->client === null) {
            return 0;
        }

        $client = $this->client;

        return $this->spool->drain(function (array $transactions) use ($client, $timeout): bool {
            try {
                $result = $client->sendTransactions($transactions, $timeout);
            } catch (TransportFailed $e) {
                Log::warning('MyBeast errors: performance samples wait in the spool', ['reason' => $e->getMessage()]);

                return false;
            }

            foreach ($result['refused'] ?? [] as $refused) {
                Log::warning('MyBeast errors: the service refused a performance sample', $refused);
            }

            return true;
        }, self::BATCH, $maxBatches);
    }
}
