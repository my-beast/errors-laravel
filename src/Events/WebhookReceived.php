<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Events;

/** Any signed request from the service, after its signature held. */
final readonly class WebhookReceived
{
    /** @param  array<string, mixed>  $data */
    public function __construct(
        public string $deliveryId,
        public string $event,
        public array $data,
    ) {}
}
