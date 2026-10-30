<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use MyBeast\Errors\Laravel\Capture\IdentityResolver;
use MyBeast\Errors\Laravel\Errors;

/**
 * GET {token_path}: a fresh client token for whoever is signed in on this host, in the web group (their session
 * decides who). @mybeast/errors asks a little before the page's token runs out, so a page left open stays someone's
 * instead of turning into a guest's after token_ttl. 204 when nobody is signed in any more: the page goes on as a guest.
 */
final class TokenController
{
    public function __invoke(Errors $errors, IdentityResolver $identity): JsonResponse|Response
    {
        $who = $identity->resolve();
        $config = $who === null ? null : $errors->clientConfig($who->subject, $who->account, $who->internal);

        if (! is_array($config) || ! isset($config['token'])) {
            return response('', 204)->header('Cache-Control', 'no-store');
        }

        return response()->json(['token' => $config['token'], 'expires_at' => $config['expires_at']])->header('Cache-Control', 'no-store');
    }
}
