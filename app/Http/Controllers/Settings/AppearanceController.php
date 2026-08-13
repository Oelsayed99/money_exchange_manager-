<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\Appearance;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AppearanceController extends Controller
{
    /**
     * Persist the theme choice against the user.
     *
     * Section 13 requires the preference to be saved. localStorage alone only saves it
     * per browser; storing it on the user is what makes the choice follow them, and
     * what lets the server apply it during the initial render instead of leaving a
     * flash for the client to correct.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'appearance' => ['required', 'string', Rule::enum(Appearance::class)],
        ]);

        $request->user()?->forceFill(['theme' => $validated['appearance']])->save();

        return back();
    }
}
