<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Capture;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * An exception and its getPrevious() chain as report-ready data: frames newest first, each marked in-app or not,
 * with source lines around the in-app ones - what makes a report debuggable without opening the IDE. Arguments are
 * never captured: they are the customers' data.
 */
final class StackTraceBuilder
{
    private const MAX_FRAMES = 80;

    private const MAX_CONTEXT_FRAMES = 25;

    private const MAX_CHAIN = 5;

    /** @var array<string, list<string>> */
    private array $files = [];

    public function __construct(
        private readonly string $basePath,
        private readonly int $contextLines = 5,
    ) {}

    /** @return list<array{class: string, message: string, frames: list<array<string, mixed>>}> */
    public function exceptions(Throwable $e): array
    {
        $chain = [];

        for ($current = $e, $i = 0; $current !== null && $i < self::MAX_CHAIN; $current = $current->getPrevious(), $i++) {
            $chain[] = [
                'class' => $current::class,
                'message' => self::message($current),
                'frames' => $this->frames($current),
            ];
        }

        return $chain;
    }

    /** Scrubbed; a QueryException keeps its SQL but loses the bound values. */
    public static function message(Throwable $e): string
    {
        $message = $e->getMessage();

        if ($e instanceof QueryException) {
            $head = trim((string) preg_replace('/\s*\(Connection: .*$/s', '', $message));
            $message = Scrubber::sql($head).' (SQL: '.$e->getSql().')';
        }

        return Scrubber::text($message);
    }

    /**
     * The host's own code - not vendor, not compiled views, and not the entry scripts: "artisan:11" as the culprit
     * of a queue failure says nothing.
     */
    public function inApp(string $file): bool
    {
        if (in_array($this->relative($file), ['artisan', 'public/index.php', 'server.php'], true)) {
            return false;
        }

        return str_starts_with($file, $this->basePath)
            && ! str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)
            && ! str_contains($file, DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR)
            && ! str_contains($file, DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework');
    }

    public function relative(string $file): string
    {
        $base = rtrim($this->basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    /** @return list<array<string, mixed>> */
    private function frames(Throwable $e): array
    {
        $trace = $e->getTrace();
        // Frame 0 is where it was thrown; each later frame's location comes from the previous trace entry.
        $frames = [$this->frame($e->getFile(), $e->getLine(), $trace[0] ?? null)];

        foreach ($trace as $i => $entry) {
            if (! isset($entry['file'])) {
                continue;
            }

            $frames[] = $this->frame($entry['file'], (int) ($entry['line'] ?? 0), $trace[$i + 1] ?? null);

            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }
        }

        $withContext = 0;

        foreach ($frames as &$frame) {
            if ($frame['in_app'] && $withContext < self::MAX_CONTEXT_FRAMES) {
                $frame['context'] = $this->context($frame['abs_file'], $frame['line']);
                $withContext++;
            }

            unset($frame['abs_file']);
        }

        return $frames;
    }

    /**
     * @param  array<string, mixed>|null  $callee  the function executing at this location
     * @return array<string, mixed>
     */
    private function frame(string $file, int $line, ?array $callee): array
    {
        $function = $callee === null
            ? null
            : (isset($callee['class']) ? class_basename($callee['class']).($callee['type'] ?? '::').$callee['function'] : $callee['function']);

        return [
            'file' => $this->relative($file),
            'abs_file' => $file,
            'line' => $line,
            'function' => $function,
            'in_app' => $this->inApp($file),
        ];
    }

    /** @return array{start: int, pre: list<string>, line: string, post: list<string>}|null */
    private function context(string $file, int $line): ?array
    {
        if ($line < 1 || ! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $lines = $this->files[$file] ??= (array) @file($file, FILE_IGNORE_NEW_LINES);

        if (! isset($lines[$line - 1])) {
            return null;
        }

        $start = max(0, $line - 1 - $this->contextLines);
        // Source lines are scrubbed like messages: code can hold a literal e-mail or personal id (a fixture, a test).
        $cut = fn (string $l): string => Scrubber::text($l, 200);

        return [
            'start' => $start + 1,
            'pre' => array_map($cut, array_slice($lines, $start, $line - 1 - $start)),
            'line' => $cut($lines[$line - 1]),
            'post' => array_map($cut, array_slice($lines, $line, $this->contextLines)),
        ];
    }
}
