<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Http;

/**
 * `X-MyBeast-Signature: t=<unix seconds>,v1=<hex>[,v1=<hex>]` - HMAC-SHA256 of "<t>.<body>" under the secret part
 * of MYBEAST_ERRORS_WEBHOOK_SECRET. Several v1 values come while the service holds two secrets (a rotation); one match
 * is enough. A signature older than five minutes is refused, so a captured request cannot be replayed later.
 */
final class WebhookVerifier
{
    public const TOLERANCE_SECONDS = 300;

    public static function verify(?string $header, string $body, string $secret, int $now): bool
    {
        if ($header === null || $secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($name === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($name === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || abs($now - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
