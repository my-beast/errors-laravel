<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Tokens;

use InvalidArgumentException;

/**
 * What a host says about the person in a browser (schemas/v1/client-token.schema.json). Ids only: never a name or
 * an e-mail - the service shows ids and links back to the host's own console.
 */
final readonly class ClientTokenClaims
{
    private const ID = '/^[A-Za-z0-9._:-]{1,64}$/';

    private const ENVIRONMENT = '/^[a-z0-9_-]{1,32}$/';

    public function __construct(
        public string $subject,
        public ?string $account,
        public string $environment,
        public bool $record,
        public bool $mask,
        /** When the person said yes to recording, unix milliseconds. Required when $record is true. */
        public ?int $consentAt,
        public bool $internal,
        public bool $test,
        public int $issuedAt,
        public int $expiresAt,
    ) {
        if (preg_match(self::ID, $subject) !== 1) {
            throw new InvalidArgumentException('A subject is an id of 1-64 letters, digits and . _ : -');
        }

        if ($account !== null && preg_match(self::ID, $account) !== 1) {
            throw new InvalidArgumentException('An account is an id of 1-64 letters, digits and . _ : -');
        }

        if (preg_match(self::ENVIRONMENT, $environment) !== 1) {
            throw new InvalidArgumentException("Not an environment name: {$environment}");
        }

        if ($record && ($consentAt === null || $consentAt < 1)) {
            throw new InvalidArgumentException('A recording token needs the moment the person said yes (consentAt, unix ms).');
        }

        if ($expiresAt <= $issuedAt || $expiresAt - $issuedAt > 3600) {
            throw new InvalidArgumentException('A client token lives more than 0 and at most 3600 seconds.');
        }
    }

    /**
     * The JWT body, keys in the schema's order (the fixture token depends on it).
     *
     * @return array<string, mixed>
     */
    public function toArray(string $publicKey): array
    {
        return [
            'v' => 1,
            'iss' => $publicKey,
            'sub' => $this->subject,
            'acct' => $this->account,
            'env' => $this->environment,
            'rec' => $this->record,
            'mask' => $this->mask,
            'consent_at' => $this->consentAt,
            'internal' => $this->internal,
            'test' => $this->test,
            'iat' => $this->issuedAt,
            'exp' => $this->expiresAt,
        ];
    }
}
