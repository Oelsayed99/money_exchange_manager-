<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * The signed-in user, as a `User` rather than a `User|null`.
 *
 * Every caller sits behind `auth` middleware, so the null can never happen — but
 * `$request->user()` is typed to allow it, and fourteen call sites in the scaffolded
 * auth and settings controllers were papering over that with a static-analysis
 * baseline entry each.
 *
 * The alternative fixes were worse. An `assert()` or an inline `@var` tells the
 * analyser to stop worrying without doing anything at runtime, so a routing mistake
 * that dropped the middleware would surface as "call to method on null" somewhere
 * deeper. This throws the exception Laravel already throws for an unauthenticated
 * request, which is both true and useful.
 */
final class Authenticated
{
    /**
     * @throws AuthenticationException when the caller is not, in fact, authenticated
     */
    public static function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
