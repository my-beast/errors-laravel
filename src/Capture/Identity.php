<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Capture;

/** Who an error happened to, as ids only: the person, the account they work in, and whether they are the host's own. */
final readonly class Identity
{
    public function __construct(
        public ?string $subject,
        public ?string $account = null,
        public bool $internal = false,
    ) {}
}
