<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Performance;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MyBeast\Errors\Laravel\Capture\Release;
use MyBeast\Errors\Laravel\Capture\Scrubber;
use MyBeast\Errors\Laravel\Capture\Spool;

/**
 * Measures requests and queued jobs (schemas/v1/transaction.schema.json), one per process. What is open is a stack: a
 * job run on the sync queue inside a request is measured on its own and also counts in the request around it.
 *
 * When one ends it is kept or dropped: every failed and every slow one, and a drawn share (sample_rate) of the rest.
 * A kept request waits in memory until its response has gone out, then goes to the spool (a file, never the database);
 * Transport\TransactionFlusher sends the spool. Nothing here touches the network.
 */
final class Recorder
{
    /** @var list<Trace> */
    private array $open = [];

    /** @var list<array<string, mixed>> kept, waiting for the response to be out */
    private array $kept = [];

    private bool $warned = false;

    /** @param  array<string, mixed>  $config  config('mybeast-errors') */
    public function __construct(
        private readonly Spool $spool,
        private readonly array $config,
        private readonly string $basePath,
    ) {}

    public function start(string $kind, ?string $name = null, ?float $at = null): void
    {
        // A worker's job that never said it ended (killed mid-way) must not swallow the next one's queries.
        if ($kind === 'job') {
            $this->open = array_values(array_filter($this->open, fn (Trace $trace): bool => $trace->kind !== 'job'));
        }

        $this->open[] = new Trace($kind, $name, $at ?? microtime(true), $this->nPlusOne(), new Origin($this->basePath));
    }

    public function query(string $sql, float $ms): void
    {
        foreach ($this->open as $trace) {
            $trace->query($sql, $ms);
        }
    }

    public function http(string $host, float $ms): void
    {
        foreach ($this->open as $trace) {
            $trace->http($host, $ms);
        }
    }

    public function measuring(): bool
    {
        return $this->open !== [];
    }

    /**
     * Ends the newest open trace of $kind. $name null (a request no route answered) drops it.
     *
     * @param  ?string  $name  a request's "GET /posts/{post}" or a job's class; null for the one it started with
     */
    public function finish(string $kind, ?string $name, ?int $status = null, bool $failed = false): void
    {
        $trace = null;

        for ($i = count($this->open) - 1; $i >= 0; $i--) {
            if ($this->open[$i]->kind === $kind) {
                $trace = $this->open[$i];
                array_splice($this->open, $i, 1);
                break;
            }
        }

        $name ??= $trace?->name;

        if ($trace === null || $name === null || $name === '') {
            return;
        }

        $ms = max(0.0, (microtime(true) - $trace->startedAt) * 1000);
        $kept = $this->keep($ms, $failed);

        if ($kept !== null) {
            $this->kept[] = $this->payload($trace, $name, $ms, $status, $failed, $kept);
        }
    }

    /** To the spool: after the response has gone out, or after a job outside a request. */
    public function store(): void
    {
        $kept = $this->kept;
        $this->kept = [];

        foreach ($kept as $transaction) {
            if (! $this->spool->write($transaction) && ! $this->warned) {
                $this->warned = true;
                Log::warning('My Beast errors: performance samples are dropped (their spool is full or not writable).');
            }
        }
    }

    /** Why it is sent - or null, it is not. */
    private function keep(float $ms, bool $failed): ?string
    {
        return match (true) {
            $failed => 'failed',
            $ms >= (int) ($this->config['performance']['slow_ms'] ?? 1000) => 'slow',
            random_int(1, 1_000_000) <= (int) round($this->sampleRate() * 1_000_000) => 'sample',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function payload(Trace $trace, string $name, float $ms, ?int $status, bool $failed, string $kept): array
    {
        $sql = fn (array $rows): array => array_map(fn (array $row): array => ['sql' => Scrubber::text($row['sql'], 2000)] + $row, $rows);

        return [
            'id' => (string) Str::uuid7(),
            'kind' => $trace->kind,
            'name' => mb_substr($name, 0, 255),
            'occurred_at' => Date::createFromTimestamp($trace->startedAt)->format('Y-m-d\TH:i:s.vP'),
            'duration_ms' => round($ms, 2),
            'status' => $status,
            'failed' => $failed,
            'environment' => strtolower((string) ($this->config['environment'] ?? 'production')),
            'release' => Release::current($this->config['release'] ?? null, $this->basePath),
            'kept' => $kept,
            'sample_rate' => $this->sampleRate(),
            'db' => ['count' => $trace->queries, 'ms' => round($trace->queryMs, 2)],
            'queries' => $sql($trace->heaviestQueries()),
            'n_plus_one' => $sql($trace->nPlusOnes()),
            'http' => $trace->calls(),
        ];
    }

    private function sampleRate(): float
    {
        return max(0.0, min(1.0, (float) ($this->config['performance']['sample_rate'] ?? 0.1)));
    }

    private function nPlusOne(): int
    {
        return max(2, (int) ($this->config['performance']['n_plus_one'] ?? 5));
    }
}
