<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool capturing()
 * @method static bool measuring()
 * @method static bool enabled()
 * @method static void capture(\Throwable $e)
 * @method static string|null lastReference()
 * @method static void identifyUsing(\Closure $resolver)
 * @method static void scrubUsing(\Closure $rule)
 * @method static int flush()
 * @method static int flushTransactions()
 * @method static \MyBeast\Errors\Laravel\Testing\FakeSink fake()
 * @method static array{url: string, key: string, env: string, release: ?string, token: string, expires_at: int}|null clientConfig(string|int $subject, string|int|null $account = null, bool $internal = false, bool $test = false)
 * @method static array{url: string, key: string, env: string, release: ?string}|null guestConfig()
 * @method static int|null eraseSubject(string|int $subject)
 * @method static int|null eraseAccount(string|int $account)
 * @method static string environment()
 *
 * @see \MyBeast\Errors\Laravel\Errors
 */
final class Errors extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \MyBeast\Errors\Laravel\Errors::class;
    }
}
