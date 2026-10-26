<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Capture;

use ArrayObject;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Throwable;
use WeakMap;

/**
 * The capture side (ported from Asuvaan's ErrorReporter, where it ran for months): every REPORTED exception - a
 * request, a queued job, a scheduled task, artisan - becomes an event (schemas/v1/error-event.schema.json) in the
 * sink. Laravel's dontReport list never reaches it.
 *
 * The one rule: reporting must never make things worse. Nothing here throws, a failure while reporting is logged
 * and dropped, a report cannot trigger another report, and nothing touches the database or the network here - the
 * spool is a file, and Transport\Flusher sends it after the response.
 */
final class ErrorReporter
{
    /** @var WeakMap<Throwable, true> */
    private WeakMap $seen;

    /** The last reference handed out in this request, e.g. "7F3A9C21". */
    private ArrayObject $state;

    private bool $capturing = false;

    /** @param  array<string, mixed>  $config  config('mybeast-errors') */
    public function __construct(
        private readonly Application $app,
        private readonly array $config,
        private readonly Breadcrumbs $breadcrumbs,
        private readonly EventSink $sink,
        private readonly IdentityResolver $identity,
    ) {
        $this->seen = new WeakMap;
        $this->state = new ArrayObject;
    }

    public static function referenceFor(string $eventId): string
    {
        return strtoupper(substr(str_replace('-', '', $eventId), -8));
    }

    /** What the customer can quote ("mention reference 7F3A9C21") - set before anything is sent. */
    public function lastReference(): ?string
    {
        return $this->state['reference'] ?? null;
    }

    public function capture(Throwable $e): void
    {
        if ($this->capturing || isset($this->seen[$e])) {
            return;
        }

        $this->capturing = true;
        $this->seen[$e] = true;

        try {
            $event = $this->payload($e);
            $this->state['reference'] = self::referenceFor($event['event_id']);
            $this->sink->put($event);
        } catch (Throwable $failure) {
            Log::warning('MyBeast errors: could not capture an exception', ['reason' => $failure->getMessage()]);
        } finally {
            $this->capturing = false;
        }
    }

    /** @return array<string, mixed> */
    private function payload(Throwable $e): array
    {
        $exceptions = (new StackTraceBuilder($this->app->basePath(), (int) ($this->config['context_lines'] ?? 5)))->exceptions($e);
        $request = $this->app->bound('request') ? $this->app->make('request') : null;
        // "In a request" = a route matched; runningInConsole() alone is also true for HTTP tests.
        $console = $this->app->runningInConsole() && $request?->route() === null;
        $http = $console ? null : $this->requestContext();
        $job = $this->breadcrumbs->context()['job'] ?? null;
        $transaction = $this->breadcrumbs->transaction() ?? ($console ? 'artisan '.implode(' ', array_slice($_SERVER['argv'] ?? [], 1, 2)) : null);
        $recording = $console ? null : $request?->header('X-Recording-Id');
        $who = $this->identity->resolve();
        $release = Release::current($this->config['release'] ?? null, $this->app->basePath());
        $environment = strtolower((string) ($this->config['environment'] ?? $this->app->environment()));

        return [
            'event_id' => (string) Str::uuid7(),
            'occurred_at' => now()->format('Y-m-d\TH:i:s.vP'),
            'platform' => 'php',
            'level' => $e instanceof FatalError ? 'fatal' : 'error',
            'exception_class' => $e::class,
            'message' => $exceptions[0]['message'],
            'exceptions' => $exceptions,
            'transaction' => $transaction,
            'request_method' => $console ? null : $request?->method(),
            'request_url' => $console ? null : Scrubber::url($request?->getRequestUri()),
            'subject' => $who?->subject,
            'account' => $who?->account,
            'internal' => $who?->internal ?? false,
            'environment' => $environment,
            'release' => $release,
            'context' => array_filter([
                'runtime' => [
                    'php' => PHP_VERSION,
                    'laravel' => $this->app->version(),
                    'sapi' => PHP_SAPI,
                    'os' => PHP_OS_FAMILY,
                    'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                ],
                'breadcrumbs' => $this->breadcrumbs->all(),
                'request' => $http,
                'job' => $job,
                'recording' => is_string($recording) && Str::isUuid($recording) ? ['id' => strtolower($recording)] : null,
                'tags' => array_filter([
                    'environment' => $environment,
                    'release' => $release,
                    'transaction' => $transaction,
                    'runtime' => 'php '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
                    'handled_by' => $job ? 'queue' : ($console ? 'cli' : 'http'),
                    'route' => $http['route'] ?? null,
                    'action' => $http['action'] ?? null,
                    'queue' => $job['queue'] ?? null,
                ]),
            ], fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * What the request was doing - by NAME only: route, controller action, parameter names and the submitted field
     * names. Never values.
     *
     * @return array<string, mixed>|null
     */
    private function requestContext(): ?array
    {
        try {
            $request = $this->app->make('request');
            $route = $request->route();

            return [
                'route' => $route?->getName(),
                'uri' => $route ? '/'.ltrim($route->uri(), '/') : null,
                'action' => $route ? self::shortAction($route->getActionName()) : null,
                'params' => $route ? array_keys($route->parameters()) : [],
                'fields' => array_slice(array_map('strval', array_keys($request->except(['_token', '_method']))), 0, 40),
                'files' => array_slice(array_keys($request->allFiles()), 0, 10),
                'kind' => match (true) {
                    $request->hasHeader('X-Inertia') => 'inertia',
                    $request->expectsJson() => 'json',
                    default => 'page',
                },
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** "App\Http\Controllers\PropertyController@store" → "PropertyController@store". */
    private static function shortAction(string $action): string
    {
        return str_contains($action, '@')
            ? class_basename((string) strtok($action, '@')).'@'.substr($action, strpos($action, '@') + 1)
            : class_basename($action);
    }
}
