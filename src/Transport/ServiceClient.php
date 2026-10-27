<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Transport;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/** The host server talking to the service with its server secret (MYBEAST_ERRORS_SECRET). */
final readonly class ServiceClient
{
    public function __construct(
        private string $url,
        private string $secret,
    ) {}

    /**
     * One batch to POST /v1/errors, gzip. The service answers per event: refused ones are named (they never fit
     * and are not sent again).
     *
     * @param  list<array<string, mixed>>  $events
     * @return array{accepted: int, duplicates: int, refused: list<array{event_id: ?string, reason: string}>}
     */
    public function sendErrors(array $events, int $timeout): array
    {
        $body = (string) gzencode((string) json_encode(['events' => $events], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));

        return $this->call(fn (PendingRequest $http) => $http
            ->withHeaders(['Content-Encoding' => 'gzip'])
            ->withBody($body, 'application/json')
            ->post($this->url.'/v1/errors'), $timeout);
    }

    /**
     * One batch of performance samples to POST /v1/transactions, gzip; answered like errors, per transaction.
     *
     * @param  list<array<string, mixed>>  $transactions
     * @return array{accepted: int, duplicates: int, refused: list<array{id: ?string, reason: string}>}
     */
    public function sendTransactions(array $transactions, int $timeout): array
    {
        $body = (string) gzencode((string) json_encode(['transactions' => $transactions], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));

        return $this->call(fn (PendingRequest $http) => $http
            ->withHeaders(['Content-Encoding' => 'gzip'])
            ->withBody($body, 'application/json')
            ->post($this->url.'/v1/transactions'), $timeout);
    }

    /** @return array<string, mixed> */
    public function deployed(string $version, string $environment): array
    {
        return $this->call(fn (PendingRequest $http) => $http->post($this->url.'/api/v1/releases', ['version' => $version, 'environment' => $environment]), 30);
    }

    /** @return array<string, mixed> */
    public function putReleaseFile(string $version, string $kind, string $name, string $bytes): array
    {
        return $this->call(fn (PendingRequest $http) => $http
            ->withBody($bytes, $kind === 'sourcemap' ? 'application/json' : 'font/woff2')
            ->put($this->url.'/api/v1/releases/'.rawurlencode($version).'/files/'.$kind.'/'.rawurlencode($name)), 60);
    }

    /**
     * DELETE /api/v1/subjects/{id} or /api/v1/accounts/{id}: everything the service keeps of one of the host's people
     * or accounts, erased. It answers {"erased": n}.
     *
     * @param  'subjects'|'accounts'  $what
     * @return array<string, mixed>
     */
    public function erase(string $what, string $id): array
    {
        return $this->call(fn (PendingRequest $http) => $http->delete($this->url.'/api/v1/'.$what.'/'.rawurlencode($id)), 30);
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     * @return array<string, mixed>
     */
    private function call(callable $request, int $timeout): array
    {
        try {
            $response = $request(Http::withToken($this->secret)->acceptJson()->timeout($timeout)->connectTimeout(min(5, $timeout)));
        } catch (Throwable $e) {
            throw new TransportFailed('The service could not be reached: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new TransportFailed('The service answered '.$response->status().': '.mb_substr($response->body(), 0, 300));
        }

        return (array) $response->json();
    }
}
