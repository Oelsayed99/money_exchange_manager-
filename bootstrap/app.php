<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveBusiness;
use App\Http\Middleware\SetLocale;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            // Before everything that reads data: no model belonging to a business can
            // be queried until this has said whose books are open.
            ResolveBusiness::class,

            // Must run before HandleInertiaRequests so that shared props — including
            // the translation bundle — are built against the resolved locale.
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Route-model binding queries the tenant-scoped model. ResolveBusiness must
        // therefore run after authentication has loaded the user but before
        // SubstituteBindings tries to find a counterparty/account from the URL.
        $middleware->appendToPriorityList(AuthenticatesRequests::class, ResolveBusiness::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
