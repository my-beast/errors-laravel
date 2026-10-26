<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Tests;

use InvalidArgumentException;
use MyBeast\Errors\Laravel\Tokens\ClientTokenClaims;
use MyBeast\Errors\Laravel\Tokens\SigningKey;
use MyBeast\Errors\Laravel\Tokens\TokenSigner;
use PHPUnit\Framework\TestCase;

final class TokenSignerTest extends TestCase
{
    public function test_it_mints_the_fixture_token_byte_for_byte(): void
    {
        $fixture = Fixtures::clientToken();
        $c = $fixture['claims'];

        $token = (new TokenSigner(SigningKey::parse($fixture['signing_key']), $fixture['public_key']))->sign(new ClientTokenClaims(
            subject: $c['sub'],
            account: $c['acct'],
            environment: $c['env'],
            record: $c['rec'],
            mask: $c['mask'],
            consentAt: $c['consent_at'],
            internal: $c['internal'],
            test: $c['test'],
            issuedAt: $c['iat'],
            expiresAt: $c['exp'],
        ));

        $this->assertSame($fixture['token'], $token);
    }

    public function test_the_fixture_fits_the_schemas(): void
    {
        $fixture = Fixtures::clientToken();

        $this->assertSame([], Fixtures::violations($fixture['claims'], 'client-token.schema.json'));
        $this->assertSame([], Fixtures::violations($fixture['header'], 'client-token-header.schema.json'));
    }

    public function test_the_schema_refuses_a_recording_token_without_its_yes(): void
    {
        $claims = Fixtures::clientToken()['claims'];
        $claims['consent_at'] = null;

        $this->assertNotSame([], Fixtures::violations($claims, 'client-token.schema.json'));
    }

    public function test_claims_refuse_what_the_schema_refuses(): void
    {
        $make = fn (array $over): ClientTokenClaims => new ClientTokenClaims(...array_merge([
            'subject' => 'u1', 'account' => null, 'environment' => 'local', 'record' => false, 'mask' => true,
            'consentAt' => null, 'internal' => false, 'test' => false, 'issuedAt' => 1000, 'expiresAt' => 2800,
        ], $over));

        foreach ([
            ['subject' => 'jane@example.com'],
            ['subject' => ''],
            ['account' => 'with space'],
            ['environment' => 'Production'],
            ['record' => true],
            ['expiresAt' => 1000],
            ['expiresAt' => 4601],
        ] as $over) {
            try {
                $make($over);
                $this->fail('Accepted: '.json_encode($over));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_signing_key_is_a_kid_and_a_long_secret(): void
    {
        foreach (['', 'sig_short.'.str_repeat('x', 40), 'sig_abcdefghijklmnop.too-short', 'pk_abcdefghijklmnop.'.str_repeat('x', 40)] as $value) {
            try {
                SigningKey::parse($value);
                $this->fail("Accepted: {$value}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $key = SigningKey::parse('sig_abcdefghijklmnop.'.str_repeat('x', 43));
        $this->assertSame('sig_abcdefghijklmnop', $key->kid);
    }
}
