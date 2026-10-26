<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Http;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use MyBeast\Errors\Laravel\Events\CustomersShouldBeNotified;
use MyBeast\Errors\Laravel\Events\WebhookReceived;

/**
 * POST {mybeast.errors.webhook_path}: what the service asks this host to do, signed. Answered 204 once the host's
 * listeners ran; a listener that throws makes it 500, and the service tries again later (the same delivery id -
 * a listener must not act twice on one id).
 */
final class WebhookController
{
    public function __invoke(Request $request, Dispatcher $events): Response
    {
        $secret = (string) (explode('.', (string) config('mybeast-errors.webhook_secret'), 2)[1] ?? '');
        $body = $request->getContent();

        if (! WebhookVerifier::verify($request->header('X-MyBeast-Signature'), $body, $secret, Date::now()->getTimestamp())) {
            return response('', 401);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload) || ! is_string($payload['id'] ?? null) || ! is_string($payload['event'] ?? null)) {
            return response('', 400);
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $events->dispatch(new WebhookReceived($payload['id'], $payload['event'], $data));

        if ($payload['event'] === 'issue.notify') {
            $events->dispatch(CustomersShouldBeNotified::from($payload['id'], $data));
        }

        return response('', 204);
    }
}
