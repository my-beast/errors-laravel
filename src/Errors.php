<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use MyBeast\Errors\Laravel\Capture\ErrorReporter;
use MyBeast\Errors\Laravel\Capture\EventSink;
use MyBeast\Errors\Laravel\Capture\Identity;
use MyBeast\Errors\Laravel\Capture\IdentityResolver;
use MyBeast\Errors\Laravel\Capture\Scrubber;
use MyBeast\Errors\Laravel\Testing\FakeSink;
use MyBeast\Errors\Laravel\Tokens\ClientTokenClaims;
use MyBeast\Errors\Laravel\Tokens\SigningKey;
use MyBeast\Errors\Laravel\Tokens\TokenSigner;
use MyBeast\Errors\Laravel\Transport\Flusher;
use MyBeast\Errors\Laravel\Transport\ServiceClient;
use MyBeast\Errors\Laravel\Transport\TransportFailed;
use Throwable;

/**
 * The SDK's front door (facade `MyBeast\Errors\Laravel\Facades\Errors`): capture, the browser's config and token, flushing, the host's hooks, and a person's or an account's erasure.
 *
 * Off while the config is incomplete or malformed (or MYBEAST_ERRORS_ENABLED=false): capture() keeps nothing,
 * clientConfig() returns null - a host without the service, or with a typo in .env, keeps working.
 */
final class Errors
{
    private const PUBLIC_KEY = '/^pk_[a-z0-9]{24}$/';

    private const SERVER_SECRET = '/^sk_[a-z0-9]{16}\.[A-Za-z0-9_-]{20,}$/';

    /** A person's or an account's id as the service takes it (schemas/v1: `subject`, `account`, `sub`, `acct`). */
    private const ID = '/^[A-Za-z0-9._:-]{1,64}$/';

    private ?TokenSigner $signer = null;

    private bool $resolved = false;

    private bool $faked = false;

    /** @param  array<string, mixed>  $config  config('mybeast-errors') */
    public function __construct(
        private readonly Application $app,
        private readonly array $config,
    ) {}

    /** Errors are captured and sent: on, with the service's URL and this server's secret. */
    public function capturing(): bool
    {
        return $this->faked || ($this->on() && preg_match(self::SERVER_SECRET, (string) ($this->config['secret'] ?? '')) === 1);
    }

    /** Browser pages get a config and a token: on, with the URL, the public key and the signing key. */
    public function enabled(): bool
    {
        return $this->signer() !== null;
    }

    public function capture(Throwable $e): void
    {
        if ($this->capturing()) {
            $this->app->make(ErrorReporter::class)->capture($e);
        }
    }

    /** The reference of the last error captured in this request ("7F3A9C21"), for the page that says sorry. */
    public function lastReference(): ?string
    {
        return $this->capturing() ? $this->app->make(ErrorReporter::class)->lastReference() : null;
    }

    /**
     * Who an error happened to - ids only, never a name or an e-mail, and never by a query (the error may be the
     * database). Default: the signed-in user's id.
     *
     * @param  Closure(): ?Identity  $resolver
     */
    public function identifyUsing(Closure $resolver): void
    {
        $this->app->make(IdentityResolver::class)->using($resolver);
    }

    /**
     * A rule of the host's own for taking personal data out of reports, after the built-in ones.
     *
     * @param  Closure(string): string  $rule
     */
    public function scrubUsing(Closure $rule): void
    {
        Scrubber::extend($rule);
    }

    /** Sends what waits in the spool now. @return int how many events went out */
    public function flush(): int
    {
        return $this->capturing() && ! $this->faked ? $this->app->make(Flusher::class)->flush() : 0;
    }

    /** Tests: capture into memory instead of the spool; assert on what was captured. */
    public function fake(): FakeSink
    {
        $fake = new FakeSink;
        $this->app->instance(EventSink::class, $fake);
        $this->app->forgetInstance(ErrorReporter::class);
        $this->faked = true;

        return $fake;
    }

    /**
     * What a signed-in page's browser needs - for an Inertia shared prop or a Blade layout: where to send its errors
     * and a signed token saying whose they are (ids only). Null while the SDK is off: the page then sends nothing.
     * The token's recording claims stay off - recording is the sessions product's (mybeast/sessions-laravel).
     *
     * @return array{url: string, key: string, env: string, release: ?string, token: string, expires_at: int}|null
     */
    public function clientConfig(string|int $subject, string|int|null $account = null, bool $internal = false, bool $test = false): ?array
    {
        $signer = $this->signer();

        if ($signer === null) {
            return null;
        }

        $now = Date::now()->getTimestamp();
        $claims = new ClientTokenClaims(
            subject: (string) $subject,
            account: $account === null ? null : (string) $account,
            environment: $this->environment(),
            record: false,
            mask: true,
            consentAt: null,
            internal: $internal,
            test: $test,
            issuedAt: $now,
            expiresAt: $now + $this->ttl(),
        );

        return [
            'url' => rtrim((string) $this->config['url'], '/'),
            'key' => (string) $this->config['public_key'],
            'env' => $this->environment(),
            'release' => Capture\Release::current($this->config['release'] ?? null, $this->app->basePath()),
            'token' => $signer->sign($claims),
            'expires_at' => $claims->expiresAt,
        ];
    }

    /** For a guest page: errors only, no token. Null while off. @return array{url: string, key: string, env: string, release: ?string}|null */
    public function guestConfig(): ?array
    {
        if (! $this->on() || preg_match(self::PUBLIC_KEY, (string) ($this->config['public_key'] ?? '')) !== 1) {
            return null;
        }

        return [
            'url' => rtrim((string) $this->config['url'], '/'),
            'key' => (string) $this->config['public_key'],
            'env' => $this->environment(),
            'release' => Capture\Release::current($this->config['release'] ?? null, $this->app->basePath()),
        ];
    }

    /**
     * Erases everything the service keeps of one of the host's people (their account was closed, or they asked): their
     * error events, what they were told, their place in the webhook deliveries, an internal mark on them. The service
     * answers how many rows went or were emptied. Null while the SDK is off or has no server secret
     * (MYBEAST_ERRORS_SECRET) - nothing can be asked then.
     *
     * @throws ErasureFailed when the service does not confirm it: try again (a queued job with retries does)
     */
    public function eraseSubject(string|int $subject): ?int
    {
        return $this->erase('subjects', $subject);
    }

    /**
     * Erases everything the service keeps of one of the host's accounts (a team, a workspace - it was closed): every
     * error event in it, whoever it happened to, its people's place in the webhook deliveries, an internal mark on it.
     * Null while the SDK is off or has no server secret.
     *
     * @throws ErasureFailed when the service does not confirm it: try again (a queued job with retries does)
     */
    public function eraseAccount(string|int $account): ?int
    {
        return $this->erase('accounts', $account);
    }

    public function environment(): string
    {
        return strtolower((string) ($this->config['environment'] ?? 'production'));
    }

    private function on(): bool
    {
        return ($this->config['enabled'] ?? true) === true
            && filter_var((string) ($this->config['url'] ?? ''), FILTER_VALIDATE_URL) !== false;
    }

    /**
     * One of the service's erasing calls: its answer says how many rows went. An id the service never takes (outside
     * the contract's pattern) has nothing kept there: 0, and nothing is asked - the service would answer 404 forever.
     *
     * @param  'subjects'|'accounts'  $what
     *
     * @throws ErasureFailed
     */
    private function erase(string $what, string|int $id): ?int
    {
        $client = $this->client();

        if ($client === null) {
            return null;
        }

        if (preg_match(self::ID, (string) $id) !== 1) {
            return 0;
        }

        try {
            $erased = $client->erase($what, (string) $id)['erased'] ?? null;
        } catch (TransportFailed $e) {
            throw new ErasureFailed($e->getMessage(), previous: $e);
        }

        return is_int($erased) ? $erased : throw new ErasureFailed('The errors service did not say how many it erased.');
    }

    /** The service, asked with the server secret: null while off or without one. */
    private function client(): ?ServiceClient
    {
        $secret = (string) ($this->config['secret'] ?? '');

        return $this->on() && preg_match(self::SERVER_SECRET, $secret) === 1
            ? new ServiceClient(rtrim((string) $this->config['url'], '/'), $secret)
            : null;
    }

    private function ttl(): int
    {
        return max(60, min(3600, (int) ($this->config['token_ttl'] ?? 1800)));
    }

    private function signer(): ?TokenSigner
    {
        if ($this->resolved) {
            return $this->signer;
        }

        $this->resolved = true;
        $publicKey = (string) ($this->config['public_key'] ?? '');
        $signingKey = (string) ($this->config['signing_key'] ?? '');

        if (! $this->on() || preg_match(self::PUBLIC_KEY, $publicKey) !== 1 || $signingKey === '') {
            return null;
        }

        try {
            $this->signer = new TokenSigner(SigningKey::parse($signingKey), $publicKey);
        } catch (InvalidArgumentException) {
            $this->signer = null;
        }

        return $this->signer;
    }
}
