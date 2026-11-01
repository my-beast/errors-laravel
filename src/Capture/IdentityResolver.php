<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Capture;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Who an error happened to. The host says how (Errors::identifyUsing); by default the signed-in user's id, no
 * account. Never by a query: the error being reported may BE the database - read what is in memory already.
 */
final class IdentityResolver
{
    /** @var Closure(): ?Identity|null */
    private ?Closure $resolver = null;

    /** @param  Closure(): ?Identity  $resolver */
    public function using(Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    public function resolve(): ?Identity
    {
        try {
            if ($this->resolver !== null) {
                return ($this->resolver)();
            }

            $id = self::signedIn();

            return $id === null ? null : new Identity($id);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The user something already loaded, or else the id a session guard keeps in the session: on a page without the
     * auth middleware nothing has loaded them yet, and the guard's own id() would (a query). Other guards: only a
     * loaded user.
     */
    private static function signedIn(): ?string
    {
        $guard = Auth::guard();

        if ($guard->hasUser()) {
            $id = $guard->user()?->getAuthIdentifier();
        } elseif ($guard instanceof SessionGuard) {
            $id = $guard->getSession()->get($guard->getName());
        } else {
            return null;
        }

        return is_int($id) || (is_string($id) && $id !== '') ? (string) $id : null;
    }
}
