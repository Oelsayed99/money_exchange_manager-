<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Support\Locale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LocaleController extends Controller
{
    /**
     * Switch the interface language.
     *
     * Stored in the session for everyone, and additionally persisted on the user when
     * one is authenticated, so the choice survives logout on a shared machine but also
     * follows the user to another device.
     *
     * Redirects back rather than to a fixed route, which is what keeps the current
     * page, its filters and its pagination intact across the switch (Section 12).
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(Locale::codes())],
        ]);

        $request->session()->put(SetLocale::SESSION_KEY, $validated['locale']);

        $user = $request->user();

        if ($user !== null) {
            $user->forceFill(['locale' => $validated['locale']])->save();
        }

        return back();
    }
}
