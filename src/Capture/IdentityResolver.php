<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Capture;

use Closure;
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

            if (! Auth::hasUser()) {
                return null;
            }

            $id = Auth::user()?->getAuthIdentifier();

            return $id === null ? null : new Identity((string) $id);
        } catch (Throwable) {
            return null;
        }
    }
}
