<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Capture;

use Illuminate\Support\Facades\Log;

/**
 * What the service takes (schemas/v1), checked before anything is sent or signed: an id the service would refuse
 * loses the whole event there, and a token's claims throw while a page renders. So an environment name is made one,
 * and an id that is not one is left off - the error still goes, as a guest's - with one line in the log, once per
 * process, so the host can find out why its people show as guests.
 */
final class Contract
{
    private const ID = '/^[A-Za-z0-9._:-]{1,64}$/';

    /** @var array<string, true> */
    private static array $said = [];

    /** Tests: say it again. */
    public static function forget(): void
    {
        self::$said = [];
    }

    /** "Staging", "eu.prod", "QA 2" → "staging", "eu-prod", "qa-2"; nothing usable → "production". */
    public static function environment(?string $name): string
    {
        $name = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', strtolower((string) $name)), '-');

        return $name === '' ? 'production' : substr($name, 0, 32);
    }

    /** The id as the service takes it (1-64 of letters, digits and . _ : -), or null - said once in the log. */
    public static function id(string|int|null $id, string $what): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        $id = (string) $id;

        if (preg_match(self::ID, $id) === 1) {
            return $id;
        }

        if (! isset(self::$said[$what])) {
            self::$said[$what] = true;
            Log::warning("MyBeast errors: a {$what} must be an id of 1-64 letters, digits and . _ : - (an e-mail or a name is not one); its errors are sent as a guest's");
        }

        return null;
    }
}
