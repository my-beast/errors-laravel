<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use MyBeast\Errors\Laravel\Performance\Recorder;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The first global middleware while performance is on (the SDK puts it there): measures the request from the moment
 * PHP took it, names it by its route ("GET /posts/{post}" - never the URL, which holds ids), and after the response
 * has gone out puts a kept one in the spool - a file write, nothing more. mybeast:errors-flush sends the spool every
 * minute.
 */
final readonly class MeasureRequests
{
    public function __construct(
        private Recorder $recorder,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->recorder->start('request', at: self::startedAt($request));
        $response = null;

        try {
            return $response = $next($request);
        } finally {
            $this->recorder->finish('request', self::name($request), $response?->getStatusCode(), $response === null || $response->getStatusCode() >= 500);
        }
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $this->recorder->store();
        } catch (Throwable) {
            // Measuring never breaks a request.
        }
    }

    /** When PHP took the request (REQUEST_TIME_FLOAT), or now when that is missing or not this request's. */
    private static function startedAt(Request $request): float
    {
        $now = microtime(true);
        $at = $request->server('REQUEST_TIME_FLOAT');

        return is_numeric($at) && (float) $at <= $now && (float) $at > $now - 3600 ? (float) $at : $now;
    }

    /** "GET /posts/{post}": the method and the route's template. Null when no route answered (a 404 is not a page). */
    private static function name(Request $request): ?string
    {
        $route = $request->route();

        return $route instanceof Route ? $request->method().' /'.ltrim($route->uri(), '/') : null;
    }
}
