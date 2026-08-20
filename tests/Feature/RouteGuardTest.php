<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Every route is behind authentication unless it is deliberately listed here.
 *
 * A one-off audit of the route table is worth little: it is true on the day it is
 * done and silently false the next time somebody adds a route outside the auth group.
 * This is the same audit, run on every commit.
 *
 * Adding a route without `auth` fails this test, and the fix is either to move it
 * inside the middleware group or to add it below with a reason. The second is a
 * deliberate act, which is the point.
 */

/**
 * Routes that must work for somebody who is not signed in, and why.
 *
 * Keyed by method and URI rather than by name: the two that post credentials are
 * unnamed, and a route can be renamed without changing what it exposes.
 */
const PUBLIC_ROUTES = [
    'GET /' => 'The landing page.',
    'GET login' => 'The sign-in form.',
    'POST login' => 'Signing in.',
    'GET register' => 'The registration form.',
    'POST register' => 'Creating an account.',
    'GET forgot-password' => 'Asking for a reset link.',
    'POST forgot-password' => 'Sending a reset link.',
    'GET reset-password/{token}' => 'Following a reset link.',
    'POST reset-password' => 'Setting a new password from a reset link.',
    // Guests may switch language, so the sign-in page can be read in Arabic.
    'PUT locale' => 'Choosing a language before signing in.',
    // Laravel signs this; the signature is the authentication.
    'GET verify-email/{id}/{hash}' => 'Following a signed verification link.',
    // Laravel's health check. Reveals only that the application is running.
    'GET up' => 'Health check.',
];

function appRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        // Only routes this application defines. Vendor packages guard their own.
        fn (RoutingRoute $route): bool => ! str_starts_with((string) $route->uri(), '_'),
    ));
}

it('puts every route behind authentication unless it is listed as public', function (): void {
    $unguarded = [];

    foreach (appRoutes() as $route) {
        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                continue;
            }

            $signature = $method.' '.$route->uri();

            if (array_key_exists($signature, PUBLIC_ROUTES)) {
                continue;
            }

            if (in_array('auth', $route->gatherMiddleware(), true)) {
                continue;
            }

            $unguarded[] = $signature;
        }
    }

    expect($unguarded)->toBe([], "These routes are reachable without signing in:\n".implode("\n", $unguarded));
});

// The list above is only meaningful if it describes reality. A route that is renamed
// or removed leaves an entry exempting nothing, and hides that fact behind a green test.
it('lists no public route that no longer exists', function (): void {
    $signatures = [];

    foreach (appRoutes() as $route) {
        foreach ($route->methods() as $method) {
            $signatures[] = $method.' '.$route->uri();
        }
    }

    expect(array_diff(array_keys(PUBLIC_ROUTES), $signatures))->toBe([]);
});

/*
 * Authentication is not authorization. Every route below authenticates; these assert
 * that signing in is not by itself enough to read the books.
 */
it('refuses a signed-in user who holds no permissions', function (string $path): void {
    $nobody = User::factory()->create();

    $this->actingAs($nobody)->get($path)->assertForbidden();
})->with([
    '/currencies',
    '/accounts',
    '/counterparties',
    '/transactions',
    '/reconciliations',
    '/movements',
    '/exchange',
    '/audit',
]);
