<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Authenticated;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if (Authenticated::user($request)->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        Authenticated::user($request)->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    }
}
