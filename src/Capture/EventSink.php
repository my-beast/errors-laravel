<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Capture;

/** Where a captured event goes: the spool (and on to the service), or a fake's memory in tests. */
interface EventSink
{
    /** @param  array<string, mixed>  $event  schemas/v1/error-event.schema.json */
    public function put(array $event): void;
}
