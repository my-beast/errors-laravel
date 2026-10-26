<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Tokens;

use Firebase\JWT\JWT;

/** Signs client tokens: HS256 JWTs with the signing key's kid in the header (schemas/v1). No network involved. */
final readonly class TokenSigner
{
    public function __construct(
        private SigningKey $key,
        private string $publicKey,
    ) {}

    public function sign(ClientTokenClaims $claims): string
    {
        return JWT::encode($claims->toArray($this->publicKey), $this->key->secret, 'HS256', $this->key->kid);
    }
}
