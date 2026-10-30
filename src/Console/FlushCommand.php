<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use MyBeast\Errors\Laravel\Errors;
use MyBeast\Errors\Laravel\Transport\TransportFailed;

/**
 * Every minute (registered on the scheduler): whatever a request or job could not send in its short try, the
 * performance samples (only ever sent from here, never from a request or a job), and once an hour a check-in.
 */
final class FlushCommand extends Command
{
    protected $signature = 'mybeast:errors-flush';

    protected $description = 'Send the captured errors that wait in the spool to MyBeast Errors';

    public function handle(Errors $errors): int
    {
        if (! $errors->capturing()) {
            $this->line('MyBeast errors is off (no URL or server secret): nothing to send.');

            return self::SUCCESS;
        }

        $this->info('Sent '.$errors->flush().' error event(s).');

        // Once an hour: "this environment can reach Errors" - so silence is not mistaken for health.
        if ($errors->checkInDue()) {
            try {
                $errors->checkIn();
            } catch (TransportFailed $e) {
                Log::warning('MyBeast errors: could not check in - its errors may not be arriving either. php artisan mybeast:errors-test says why.', ['reason' => $e->getMessage()]);
            }
        }

        if ($errors->measuring()) {
            $this->info('Sent '.$errors->flushTransactions().' performance sample(s).');
        }

        return self::SUCCESS;
    }
}
