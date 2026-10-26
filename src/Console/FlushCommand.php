<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Console;

use Illuminate\Console\Command;
use MyBeast\Errors\Laravel\Errors;

/** Every minute (registered on the scheduler): whatever a request or job could not send in its short try. */
final class FlushCommand extends Command
{
    protected $signature = 'mybeast:errors-flush';

    protected $description = 'Send the captured errors that wait in the spool to errors.my-beast.com';

    public function handle(Errors $errors): int
    {
        if (! $errors->capturing()) {
            $this->line('MyBeast errors is off (no URL or server secret): nothing to send.');

            return self::SUCCESS;
        }

        $this->info('Sent '.$errors->flush().' error event(s).');

        return self::SUCCESS;
    }
}
