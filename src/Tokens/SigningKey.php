<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Tokens;

use InvalidArgumentException;

/**
 * MYBEAST_ERRORS_SIGNING_KEY: `{kid}.{secret}`. The kid (`sig_` + 16) goes in the token header; the secret, as the UTF-8
 * string it is (never base64-decoded), is the HS256 key - at least 32 bytes.
 */
final readonly class SigningKey
{
    private const KID = '/^sig_[a-z0-9]{16}$/';

    private function __construct(
        public string $kid,
        public string $secret,
    ) {}

    public static function parse(string $value): self
    {
        $parts = explode('.', trim($value), 2);

        if (count($parts) !== 2 || preg_match(self::KID, $parts[0]) !== 1 || strlen($parts[1]) < 32) {
            throw new InvalidArgumentException('MYBEAST_ERRORS_SIGNING_KEY is not `sig_….secret` (copy it from the project\'s settings).');
        }

        return new self($parts[0], $parts[1]);
    }
}
