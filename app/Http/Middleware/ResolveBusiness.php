<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\CurrentBusiness;
use App\Domain\Tenancy\Exceptions\AccountHasNoBooks;
use App\Models\Business;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens the signed-in user's books, and nobody else's.
 *
 * Runs on every web request rather than only the authenticated ones: a guest binds
 * nothing, and the global scope then refuses any query that touches a business's data.
 * A public page that accidentally reads a counterparty is a loud failure instead of a
 * leak.
 *
 * The business is read from the user's own row, never from anything the request
 * carries. A business id in a URL, a header or a session would be a value the client
 * can change.
 */
final class ResolveBusiness
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user instanceof User) {
            // Signed in and attached to nothing. Sign-up cannot produce this — it creates
            // both in one transaction — but a database migrated from before books were
            // kept per business can, and the symptom was a stack trace on every screen.
            $business = $user->business;

            if (! $business instanceof Business) {
                throw new AccountHasNoBooks;
            }

            app(CurrentBusiness::class)->set($business);
        }

        return $next($request);
    }
}
