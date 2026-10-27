<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Performance;

/**
 * The host's own line that ran a query - "app/Http/Controllers/PostController.php:24" - so an N+1 says where to look.
 * Read from the call stack (without arguments: no values), once per N+1 shape per request. A query run from a Blade
 * loop sits in a compiled view: its source view is named instead, read from the compiled file's PATH comment.
 */
final readonly class Origin
{
    private string $base;

    private string $sdk;

    public function __construct(string $basePath)
    {
        $this->base = rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $this->sdk = dirname(__DIR__).DIRECTORY_SEPARATOR;
    }

    public function find(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 80) as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null || str_starts_with($file, $this->sdk) || str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            if (str_contains($file, DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR)) {
                return $this->view($file);
            }

            return mb_substr($this->relative($file).':'.($frame['line'] ?? 0), 0, 300);
        }

        return null;
    }

    private function view(string $compiled): ?string
    {
        $size = @filesize($compiled);
        $tail = $size === false ? false : @file_get_contents($compiled, false, null, max(0, $size - 600));

        return is_string($tail) && preg_match('/\/\*\*PATH (.+?) ENDPATH\*\*\//', $tail, $m) === 1
            ? mb_substr($this->relative($m[1]), 0, 300)
            : null;
    }

    /** Inside the app: from its root. Elsewhere (a package's workbench): the last three parts - never the server's layout. */
    private function relative(string $file): string
    {
        if (str_starts_with($file, $this->base)) {
            return substr($file, strlen($this->base));
        }

        return '…/'.implode('/', array_slice(explode(DIRECTORY_SEPARATOR, $file), -3));
    }
}
