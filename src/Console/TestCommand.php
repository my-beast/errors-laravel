<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Console;

use Illuminate\Console\Command;
use MyBeast\Errors\Laravel\Errors;
use MyBeast\Errors\Laravel\Transport\TransportFailed;

/**
 * `php artisan mybeast:errors-test`: whether My Beast Errors hears this app, said in words - what is set, a check-in sent
 * at once (marked as a test on the project's overview), and the service's answer: who it heard, or why it refused.
 * No error is sent, so nobody is alerted.
 */
final class TestCommand extends Command
{
    protected $signature = 'mybeast:errors-test';

    protected $description = 'Check that My Beast Errors hears this app, and say why not';

    public function handle(Errors $errors): int
    {
        $config = (array) config('mybeast-errors', []);
        $set = fn (string $key): string => is_string($config[$key] ?? null) && $config[$key] !== '' ? 'set' : 'MISSING';

        $this->line('URL               '.(is_string($config['url'] ?? null) && $config['url'] !== '' ? $config['url'] : 'MISSING (MYBEAST_ERRORS_URL)'));
        $this->line('Server secret     '.$set('secret').' (MYBEAST_ERRORS_SECRET) - server errors');
        $this->line('Public key        '.$set('public_key').' (MYBEAST_ERRORS_PUBLIC_KEY) - browser errors');
        $this->line('Signing key       '.$set('signing_key').' (MYBEAST_ERRORS_SIGNING_KEY) - who a browser error hit');
        $this->line('Environment       '.$errors->environment());

        if (! $errors->capturing()) {
            $this->error('Errors is off here: it needs the URL and a server secret (sk_…). Nothing is sent - not even this test.');

            return self::FAILURE;
        }

        try {
            $answer = $errors->checkIn(test: true);
        } catch (TransportFailed $e) {
            $this->error(str_contains($e->getMessage(), 'answered 401') || str_contains($e->getMessage(), 'answered 403')
                ? 'Errors refused this server secret: it is not one of the project\'s Errors keys (revoked, another product\'s, or from before the project was sent again). Take MYBEAST_ERRORS_SECRET from Secrets or the project\'s Errors settings.'
                : 'Errors did not hear this app: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Errors heard '.($answer['project'] ?? 'the project').' ('.($answer['environment'] ?? $errors->environment()).'). Its overview says so under "What arrives".');

        return self::SUCCESS;
    }
}
