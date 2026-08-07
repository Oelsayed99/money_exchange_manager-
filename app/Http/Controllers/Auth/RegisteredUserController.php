<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role as SpatieRole;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Determined before the row is inserted, or the new user would count itself.
        $isFirstUser = User::query()->count() === 0;

        // A fresh installation may not have been seeded yet. Registering must not fail
        // with "role does not exist", and the first account must not end up with a role
        // that grants nothing. Seeding from the same seeder the application ships keeps
        // one source of truth for the matrix; it is idempotent, so this is a no-op once
        // the roles are in place.
        if (SpatieRole::query()->count() === 0) {
            (new RolePermissionSeeder)->run();
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // Bootstrap: the very first account has to be able to administer the system,
        // because there is nobody yet to grant it anything. Everyone after that starts
        // read-only and is raised deliberately — a self-service route to managing the
        // currencies the ledger depends on would not be a permission system at all.
        $user->assignRole($isFirstUser ? Role::Administrator->value : Role::Viewer->value);

        event(new Registered($user));

        Auth::login($user);

        return to_route('dashboard');
    }
}
