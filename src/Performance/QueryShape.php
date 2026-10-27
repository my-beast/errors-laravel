<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Performance;

/**
 * A query's shape: its SQL with every value taken out, so the runs of one statement group together and nothing a
 * customer typed leaves the app. Laravel's SQL already holds `?` for bound values; this also takes out what the host
 * wrote into the SQL itself (quoted strings, numbers, the ids of an eager load's `in (1, 2, 3)`) and cuts every IN list
 * to `(?)`. The service shapes it again on arrival (service/app/Performance/Support/QueryShape.php - keep the two the
 * same).
 */
final class QueryShape
{
    public static function of(string $sql): string
    {
        // Quoted strings (with '' and \' escapes), then numbers that are not part of a name (t1, "2fa", $1).
        $sql = (string) preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", '?', $sql);
        $sql = (string) preg_replace('/(?<![\w."`$])-?\d+(?:\.\d+)?(?![\w."`])/', '?', $sql);
        $sql = (string) preg_replace('/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?)', $sql);

        return mb_substr((string) preg_replace('/\s+/', ' ', trim($sql)), 0, 2000);
    }
}
