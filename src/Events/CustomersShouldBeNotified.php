<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Events;

/**
 * The service asks this host to tell people an issue hit "we're on it" (kind `working-on-it`) or "it's fixed"
 * (`fixed`) - in the host's own words and language: the service knows them only by id. Each person comes with what
 * they were doing the last time it happened to them ($person['method'], $person['path']), so the message can say
 * where. The same $deliveryId may come again after a failed answer: act once per id.
 */
final readonly class CustomersShouldBeNotified
{
    /**
     * @param  array{id: string, title: string, environment: string, url: string}  $issue
     * @param  list<array{subject: string, account: ?string, last_at: string, method: ?string, path: ?string}>  $people
     */
    public function __construct(
        public string $deliveryId,
        public array $issue,
        public string $kind,
        public ?string $release,
        public array $people,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function from(string $deliveryId, array $data): self
    {
        return new self(
            deliveryId: $deliveryId,
            issue: (array) ($data['issue'] ?? []),
            kind: (string) ($data['kind'] ?? ''),
            release: isset($data['release']) ? (string) $data['release'] : null,
            people: array_values(array_filter((array) ($data['people'] ?? []), 'is_array')),
        );
    }
}
