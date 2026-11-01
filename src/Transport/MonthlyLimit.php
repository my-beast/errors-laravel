<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Transport;

use Illuminate\Support\Facades\Log;
use MyBeast\Errors\Laravel\Capture\Spool;

/**
 * Past the project's monthly limit (its package's) the service refuses the rest of the month's events, or samples,
 * as `monthly-limit`. They are dropped like any refused one - sent again they would be refused again until the 1st,
 * UTC - but said once a month, and once more when the service takes them again: a warning for each would fill the
 * host's log just when the host sends the most. The spool keeps the month it was said for (its `monthly-limit` note).
 */
final readonly class MonthlyLimit
{
    public const REASON = 'monthly-limit';

    public function __construct(
        private Spool $spool,
        private string $what,
    ) {}

    /**
     * @param  array<int, mixed>  $refused  an answer's refused items
     * @return list<mixed> those refused for a reason of their own, each worth its warning
     */
    public function sort(array $refused, int $taken): array
    {
        $others = array_values(array_filter($refused, fn (mixed $item): bool => ! is_array($item) || ($item['reason'] ?? null) !== self::REASON));
        $over = count($refused) - count($others);
        $noted = $this->spool->note('monthly-limit');

        if ($over > 0 && $noted !== gmdate('Y-m')) {
            $this->spool->setNote('monthly-limit', gmdate('Y-m'));
            Log::warning("My Beast errors: the project has used up this month's {$this->what}; the service drops the rest until the 1st (UTC).", ['dropped' => $over]);
        } elseif ($over === 0 && $taken > 0 && $noted !== null) {
            $this->spool->setNote('monthly-limit', null);
            Log::info("My Beast errors: the service takes {$this->what} again.");
        }

        return $others;
    }
}
