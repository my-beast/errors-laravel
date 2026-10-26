<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Capture;

use Closure;

/**
 * Takes personal data out of everything an error report carries, before it leaves the app. The service runs the
 * same patterns again on arrival (service/app/Errors/Support/Scrubber.php - keep the two the same).
 *
 * A host adds its own rules with Errors::scrubUsing(fn (string $text): string => …) - Asuvaan masks Finnish
 * personal identity codes in every spelling with its own PersonalIdentityCode::maskIn.
 */
final class Scrubber
{
    private const PATTERNS = [
        // E-mails first - they hold dots and digits the later rules would chew up.
        '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i' => '[email]',
        // IBAN, with or without grouping spaces.
        '/\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]{4}){2,7}(?:\s?[A-Z0-9]{1,4})?\b/' => '[iban]',
        // Finnish personal identity code (henkilötunnus), incl. the 2023 separators.
        '/\b\d{6}[-+A-FU-Y]\d{3}[0-9A-Y]\b/' => '[hetu]',
        // Bearer / API tokens and long secrets.
        '/\b(?:Bearer\s+)[A-Za-z0-9\-._~+\/]+=*/' => 'Bearer [token]',
        '/\b[A-Za-z0-9]{40,}\b/' => '[token]',
        // Phone numbers: +358 40 123 4567, 040-1234567. A leading + or 0 is required, so "134217728 bytes" survives.
        '/(?<![\w+])(?:\+\d{1,3}[\s-]?\d{1,3}|0\d{1,3})[\s-]?\d{3}[\s-]?\d{3,4}(?!\w)/' => '[phone]',
    ];

    /** @var list<Closure(string): string> */
    private static array $extra = [];

    /** @param  Closure(string): string  $rule */
    public static function extend(Closure $rule): void
    {
        self::$extra[] = $rule;
    }

    public static function forget(): void
    {
        self::$extra = [];
    }

    public static function text(?string $text, int $max = 2000): string
    {
        $text = (string) $text;

        foreach (self::PATTERNS as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        $text = self::host($text);

        // Again after the cut: truncating can leave the start of a value at the end.
        return self::host(mb_strimwidth($text, 0, $max, '…'));
    }

    /**
     * SQL with its values removed: quoted strings and numbers become "?" - for messages that embed a statement
     * (MySQL "Duplicate entry 'x'").
     */
    public static function sql(string $sql): string
    {
        $sql = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $sql);
        $sql = (string) preg_replace('/"(?:[^"\\\\]|\\\\.)*"(?=\s*(?:,|\)|$))/', '?', $sql);

        return (string) preg_replace('/(?<![\w.])-?\d+(?:\.\d+)?(?![\w.])/', '?', $sql);
    }

    /** Path plus query KEYS - "/properties?page=&sort=". Values never leave the request. */
    public static function url(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        $path = $parts === false ? '/' : ($parts['path'] ?? '/');

        if ($parts === false || ! isset($parts['query']) || $parts['query'] === '') {
            return mb_substr($path, 0, 2000);
        }

        parse_str($parts['query'], $query);

        return mb_substr($path.'?'.implode('&', array_map(fn ($key): string => $key.'=', array_keys($query))), 0, 2000);
    }

    private static function host(string $text): string
    {
        foreach (self::$extra as $rule) {
            $text = $rule($text);
        }

        return $text;
    }
}
