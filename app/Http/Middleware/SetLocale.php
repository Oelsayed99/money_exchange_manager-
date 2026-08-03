<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active locale for every request.
 *
 * Order of preference: the authenticated user's saved choice, then the session (which
 * lets a guest switch language on the login screen), then the configured default.
 * An unsupported value is ignored rather than trusted — the locale reaches the
 * translator and the html lang attribute, so it must never be arbitrary user input.
 */
final class SetLocale
{
    public const SESSION_KEY = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        app()->setLocale($locale);

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $user = $request->user();

        if ($user !== null && is_string($user->locale) && Locale::isSupported($user->locale)) {
            return $user->locale;
        }

        $session = $request->session()->get(self::SESSION_KEY);

        if (is_string($session) && Locale::isSupported($session)) {
            return $session;
        }

        return config('app.locale', 'en');
    }
}
