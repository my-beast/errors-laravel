<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Capture;

/**
 * What happened just before the error: the last N queries (SQL with placeholders - never the values), log lines and
 * lifecycle steps, plus the "transaction" - the route, job or command that was running. A bounded ring buffer, one
 * per process; queue workers live long, so it is reset at the start of every job.
 */
final class Breadcrumbs
{
    /** @var array<int, array{at: float, category: string, message: string, data?: array<string, mixed>}> */
    private array $items = [];

    private int $added = 0;

    private ?string $transaction = null;

    /** @var array<string, array<string, mixed>> */
    private array $context = [];

    public function __construct(private readonly int $max = 30) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  bool  $scrub  false only for text that cannot hold values (SQL with placeholders) - it runs on every
     *                       query, so it must be cheap
     */
    public function add(string $category, string $message, array $data = [], bool $scrub = true): void
    {
        if ($this->max < 1) {
            return;
        }

        $crumb = [
            'at' => microtime(true),
            'category' => $category,
            'message' => $scrub ? Scrubber::text($message, 400) : mb_strimwidth($message, 0, 400, '…'),
        ];

        if ($data !== []) {
            $crumb['data'] = $data;
        }

        // Slot = crumb number modulo $max: the oldest is overwritten in place.
        $this->items[$this->added % $this->max] = $crumb;
        $this->added++;
    }

    /** @return list<array<string, mixed>> oldest first, `at` as H:i:s.v */
    public function all(): array
    {
        $oldest = $this->added > $this->max ? $this->added % $this->max : 0;
        $ordered = $oldest === 0
            ? array_values($this->items)
            : [...array_slice($this->items, $oldest), ...array_slice($this->items, 0, $oldest)];

        return array_map(fn (array $crumb): array => ['at' => self::clock($crumb['at'])] + $crumb, $ordered);
    }

    public function setTransaction(?string $transaction): void
    {
        $this->transaction = $transaction === null ? null : mb_substr($transaction, 0, 255);
    }

    public function transaction(): ?string
    {
        return $this->transaction;
    }

    /** @param  array<string, mixed>  $context */
    public function setContext(string $key, array $context): void
    {
        $this->context[$key] = $context;
    }

    /** @return array<string, array<string, mixed>> */
    public function context(): array
    {
        return $this->context;
    }

    public function reset(): void
    {
        $this->items = [];
        $this->added = 0;
        $this->transaction = null;
        $this->context = [];
    }

    private static function clock(float $time): string
    {
        return date('H:i:s', (int) $time).sprintf('.%03d', (int) (($time - floor($time)) * 1000));
    }
}
