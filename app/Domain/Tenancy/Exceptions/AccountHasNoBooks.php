<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Somebody is signed in to an account that owns no books.
 *
 * Sign-up creates the person and their business in one transaction, so this should be
 * unreachable — but "should be unreachable" is how the owner met a stack trace on the
 * login screen. It happened to an account that predated books being kept per business,
 * on a database where the migration had not been run yet.
 *
 * There is no honest automatic recovery: creating a business for them here would hand
 * somebody an empty ledger and hide the fact that their real one was not found. So this
 * says what is wrong, in a sentence, to whoever is looking at it.
 */
final class AccountHasNoBooks extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'This account is not attached to any business, so there are no books to open. '
            .'An account created before this version had per-business books needs '
            .'`php artisan migrate` to be run, which attaches it.'
        );
    }

    /** Rendered rather than left to the generic error page, which would say nothing. */
    public function render(Request $request): Response
    {
        $body = <<<'HTML'
            <!doctype html>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>No books on this account</title>
            <style>
              body{margin:0;min-height:100svh;display:grid;place-items:center;padding:24px;
                   font:16px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
                   background:#0a1720;color:#dde7ec}
              main{max-width:52ch}
              h1{font-size:22px;margin:0 0 12px;font-weight:600}
              p{margin:0 0 12px;color:#a2b4be}
              code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.9em;
                   background:#10222e;padding:2px 6px;border-radius:3px;color:#dde7ec}
              a{color:#7fbee4}
            </style>
            <main>
              <h1>This account has no books yet</h1>
              <p>You are signed in, but the account is not attached to a business, so there
                 is nothing to open.</p>
              <p>This happens to an account created before the application kept a separate
                 set of books per business. Running <code>php artisan migrate</code> attaches
                 it to the existing books.</p>
              <p><a href="/logout" onclick="event.preventDefault();document.getElementById('o').submit()">Sign out</a></p>
              <form id="o" method="post" action="/logout" hidden>
                <input type="hidden" name="_token" value="__TOKEN__">
              </form>
            </main>
            HTML;

        // No session means no token and nothing to sign out of — which is possible
        // here, because this can be reached on a request that never started one.
        $token = $request->hasSession() ? $request->session()->token() : '';

        return new Response(str_replace('__TOKEN__', $token, $body), 500);
    }
}
