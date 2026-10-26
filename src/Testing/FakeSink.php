<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Testing;

use Closure;
use MyBeast\Errors\Laravel\Capture\EventSink;
use PHPUnit\Framework\Assert;

/** Errors::fake(): what would have been sent, kept in memory, with assertions. */
final class FakeSink implements EventSink
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public function put(array $event): void
    {
        $this->events[] = $event;
    }

    /** @param  (Closure(array<string, mixed>): bool)|null  $matching */
    public function assertCaptured(?Closure $matching = null): void
    {
        $found = array_filter($this->events, fn (array $event): bool => $matching === null || $matching($event));

        Assert::assertNotEmpty($found, 'No captured error event matched.');
    }

    public function assertNothingCaptured(): void
    {
        Assert::assertSame([], $this->events, 'Errors were captured.');
    }

    /** @return array<string, mixed>|null */
    public function last(): ?array
    {
        return $this->events === [] ? null : $this->events[array_key_last($this->events)];
    }
}
