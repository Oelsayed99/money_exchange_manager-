<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Tenancy\BusinessProvisioner;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function __construct(private readonly BusinessProvisioner $provisioner) {}

    /**
     * Show the registration page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/register', [
            'providers' => SocialAuthController::available(),
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * Every sign-up is a new business, owned outright by the person signing up.
     *
     * It used to be that the first account became an administrator and every account
     * after it a viewer, which made sense while this was one office's books. It stopped
     * making sense the moment books were kept per business: it made the second person
     * to sign up a read-only spectator of the first person's ledger.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'business_name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = $this->provisioner->provision(
            businessName: $validated['business_name'],
            name: $validated['name'],
            email: $validated['email'],
            attributes: ['password' => Hash::make($validated['password'])],
        );

        event(new Registered($user));

        // Remembered, so the session outlives the browser. See config/session.php.
        Auth::login($user, remember: true);

        return to_route('dashboard');
    }
}
