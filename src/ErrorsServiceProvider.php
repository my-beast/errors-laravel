<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MyBeast\Errors\Laravel\Capture\Breadcrumbs;
use MyBeast\Errors\Laravel\Capture\ErrorReporter;
use MyBeast\Errors\Laravel\Capture\EventSink;
use MyBeast\Errors\Laravel\Capture\IdentityResolver;
use MyBeast\Errors\Laravel\Capture\Spool;
use MyBeast\Errors\Laravel\Capture\SpoolSink;
use MyBeast\Errors\Laravel\Console\FlushCommand;
use MyBeast\Errors\Laravel\Console\ReleaseCommand;
use MyBeast\Errors\Laravel\Http\WebhookController;
use MyBeast\Errors\Laravel\Transport\Flusher;
use MyBeast\Errors\Laravel\Transport\ServiceClient;
use Throwable;

/**
 * Plugs MyBeast errors in without the host writing code: every reported exception is captured (the exception handler's
 * reportable callbacks - Laravel's dontReport list still applies), breadcrumbs are collected, the spool is sent
 * after each response and job and every minute, and the webhook route answers when a webhook secret is set.
 */
final class ErrorsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mybeast-errors.php', 'mybeast-errors');

        // The host's scrub rules live in a static list (Scrubber runs everywhere, even where the container is gone):
        // each app starts it empty, so a test suite booting thousands of apps does not pile them up.
        Capture\Scrubber::forget();

        $config = fn (): array => (array) $this->app['config']->get('mybeast-errors', []);

        $this->app->singleton(Errors::class, fn ($app): Errors => new Errors($app, $config()));
        $this->app->singleton(Breadcrumbs::class, fn (): Breadcrumbs => new Breadcrumbs((int) ($config()['breadcrumbs'] ?? 30)));
        $this->app->singleton(IdentityResolver::class);
        $this->app->singleton(Spool::class, fn (): Spool => new Spool((string) $config()['spool_path']));
        $this->app->singleton(SpoolSink::class);
        $this->app->singleton(EventSink::class, fn ($app): EventSink => $app->make(SpoolSink::class));
        $this->app->singleton(ErrorReporter::class, fn ($app): ErrorReporter => new ErrorReporter(
            $app,
            $config(),
            $app->make(Breadcrumbs::class),
            $app->make(EventSink::class),
            $app->make(IdentityResolver::class),
        ));
        $this->app->singleton(Flusher::class, fn ($app): Flusher => new Flusher(
            $app->make(Spool::class),
            $app->make(SpoolSink::class),
            $app->make(Errors::class)->capturing()
                ? new ServiceClient(rtrim((string) $config()['url'], '/'), (string) $config()['secret'])
                : null,
        ));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/mybeast-errors.php' => config_path('mybeast-errors.php')], 'mybeast-errors-config');

        if ($this->app->runningInConsole()) {
            $this->commands([FlushCommand::class, ReleaseCommand::class]);
        }

        $this->bootWebhookRoute();

        $handler = $this->app->make(ExceptionHandler::class);

        // Always hooked - capture() asks whether it is on at the time, so Errors::fake() in a test sees reports even
        // where the SDK is not configured. Returns nothing: the host's own logging still runs. Not an instanceof
        // check: in artisan, Collision wraps the handler and forwards reportable() to it.
        if (method_exists($handler, 'reportable')) {
            $handler->reportable(function (Throwable $e): void {
                $this->app->make(Errors::class)->capture($e);
            });
        }

        if (! $this->app->make(Errors::class)->capturing()) {
            return;
        }

        $this->bootBreadcrumbs();

        // Sent right after the response went out (PHP-FPM has already answered), and after each queued job.
        $this->app->terminating(fn () => $this->flushQuietly());
        Event::listen([JobProcessed::class, JobFailed::class], fn () => $this->flushQuietly());

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('mybeast:errors-flush')->everyMinute()->withoutOverlapping();
        });
    }

    private function flushQuietly(): void
    {
        try {
            $this->app->make(Flusher::class)->flushIfPending();
        } catch (Throwable) {
            // The minutely flush will get it.
        }
    }

    private function bootWebhookRoute(): void
    {
        if (! is_string(config('mybeast-errors.webhook_secret')) || config('mybeast-errors.webhook_secret') === '') {
            return;
        }

        // Outside the web group: no session, no CSRF - the signature is the check.
        Route::post((string) config('mybeast-errors.webhook_path', 'mybeast/errors/webhook'), WebhookController::class)->name('mybeast.errors.webhook');
    }

    /** What happened just before an error - the last queries, log lines and steps. */
    private function bootBreadcrumbs(): void
    {
        // Resolved on first use, then kept: the query listener runs on every query.
        $breadcrumbs = null;
        $crumbs = function () use (&$breadcrumbs): Breadcrumbs {
            return $breadcrumbs ??= $this->app->make(Breadcrumbs::class);
        };

        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($crumbs): void {
            // SQL with placeholders only - the bindings are customers' data.
            $crumbs()->add('query', $query->sql, ['ms' => round($query->time, 1)], scrub: false);
        });

        Event::listen(MessageLogged::class, function (MessageLogged $log) use ($crumbs): void {
            if (! str_starts_with($log->message, 'MyBeast errors:')) {
                $crumbs()->add('log', $log->message, ['level' => $log->level]);
            }
        });

        Event::listen(RouteMatched::class, function (RouteMatched $event) use ($crumbs): void {
            $crumbs()->setTransaction($event->route->getName() ?? $event->route->uri());
            $crumbs()->add('http', $event->request->method().' /'.ltrim($event->route->uri(), '/'));
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event) use ($crumbs): void {
            // Workers live long: each job starts with a clean trail.
            $crumbs()->reset();
            $crumbs()->setTransaction('job '.$event->job->resolveName());
            $crumbs()->setContext('job', [
                'name' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'attempt' => $event->job->attempts(),
                'max_tries' => $event->job->maxTries(),
            ]);
            $crumbs()->add('queue', 'Started '.$event->job->resolveName());
        });

        Event::listen(CommandStarting::class, function (CommandStarting $event) use ($crumbs): void {
            if ($event->command !== null && $crumbs()->transaction() === null) {
                $crumbs()->setTransaction('artisan '.$event->command);
            }
        });
    }
}
