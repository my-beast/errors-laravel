<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Performance;

/**
 * One request or job being measured: its queries by shape and its outgoing calls by host. Runs on every query, so it
 * stays cheap - a shape is worked out once per distinct SQL string, and a request with an unusual number of distinct
 * statements stops being itemised (its totals still count).
 */
final class Trace
{
    private const MAX_STATEMENTS = 500;

    public int $queries = 0;

    public float $queryMs = 0.0;

    /** @var array<string, string> SQL as run => its shape */
    private array $shapeOf = [];

    /** @var array<string, array{count: int, ms: float, max_ms: float, origin: ?string}> shape => its runs */
    private array $shapes = [];

    /** @var array<string, array{count: int, ms: float}> host => its calls */
    private array $hosts = [];

    public function __construct(
        public readonly string $kind,
        public readonly ?string $name,
        public readonly float $startedAt,
        private readonly int $nPlusOne,
        private readonly Origin $origin,
    ) {}

    public function query(string $sql, float $ms): void
    {
        $this->queries++;
        $this->queryMs += $ms;

        $shape = $this->shapeOf[$sql] ?? null;

        if ($shape === null) {
            if (count($this->shapeOf) >= self::MAX_STATEMENTS) {
                return;
            }

            $shape = $this->shapeOf[$sql] = QueryShape::of($sql);
        }

        $runs = $this->shapes[$shape] ?? ['count' => 0, 'ms' => 0.0, 'max_ms' => 0.0, 'origin' => null];
        $runs['count']++;
        $runs['ms'] += $ms;
        $runs['max_ms'] = max($runs['max_ms'], $ms);

        // Where in the host's code it runs: looked up once, when the shape becomes an N+1.
        if ($runs['count'] === $this->nPlusOne) {
            $runs['origin'] = $this->origin->find();
        }

        $this->shapes[$shape] = $runs;
    }

    public function http(string $host, float $ms): void
    {
        $calls = $this->hosts[$host] ?? ['count' => 0, 'ms' => 0.0];
        $calls['count']++;
        $calls['ms'] += $ms;
        $this->hosts[$host] = $calls;
    }

    /** @return list<array{sql: string, count: int, ms: float, max_ms: float}> the heaviest shapes by their time */
    public function heaviestQueries(int $limit = 10): array
    {
        $shapes = $this->shapes;
        uasort($shapes, fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

        return array_map(
            fn (string $sql, array $runs): array => ['sql' => $sql, 'count' => $runs['count'], 'ms' => round($runs['ms'], 2), 'max_ms' => round($runs['max_ms'], 2)],
            array_keys(array_slice($shapes, 0, $limit, true)),
            array_slice($shapes, 0, $limit, true),
        );
    }

    /** @return list<array{sql: string, count: int, ms: float, origin: ?string}> shapes run N+1 times or more, most runs first */
    public function nPlusOnes(int $limit = 5): array
    {
        $repeated = array_filter($this->shapes, fn (array $runs): bool => $runs['count'] >= $this->nPlusOne);
        uasort($repeated, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_map(
            fn (string $sql, array $runs): array => ['sql' => $sql, 'count' => $runs['count'], 'ms' => round($runs['ms'], 2), 'origin' => $runs['origin']],
            array_keys(array_slice($repeated, 0, $limit, true)),
            array_slice($repeated, 0, $limit, true),
        );
    }

    /** @return list<array{host: string, count: int, ms: float}> */
    public function calls(int $limit = 10): array
    {
        $hosts = $this->hosts;
        uasort($hosts, fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

        return array_map(
            fn (string $host, array $calls): array => ['host' => $host, 'count' => $calls['count'], 'ms' => round($calls['ms'], 2)],
            array_keys(array_slice($hosts, 0, $limit, true)),
            array_slice($hosts, 0, $limit, true),
        );
    }
}
