<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\CurrentBusiness;
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

        if ($user instanceof User && $user->business instanceof Business) {
            app(CurrentBusiness::class)->set($user->business);
        }

        return $next($request);
    }
}
