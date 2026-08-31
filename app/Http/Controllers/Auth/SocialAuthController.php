<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Exceptions\SocialAuthFailed;
use App\Domain\Auth\GoogleProvider;
use App\Domain\Auth\SocialIdentity;
use App\Domain\Auth\SocialProvider;
use App\Domain\Tenancy\BusinessProvisioner;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Signing in through somebody else.
 *
 * One route in and one route back for every provider, so adding Apple is a class and a
 * line of configuration rather than a pair of controllers.
 *
 * ## The state parameter is the security of this whole flow
 *
 * Without it, a link containing an attacker's authorisation code signs the person who
 * clicks it into the attacker's account, and everything they then record goes into the
 * attacker's books. The state is generated here, kept in the session, and required to
 * match on the way back. It is single-use: kept or replayed, a code is worthless
 * without the session that started it.
 */
final class SocialAuthController extends Controller
{
    private const string SESSION_KEY = 'social.state';

    public function __construct(private readonly BusinessProvisioner $provisioner) {}

    /**
     * The providers that have been given credentials, for the sign-in screen.
     *
     * A button for a provider with no keys would be a button that leads to an error
     * page, so an unconfigured provider simply does not appear. That is what lets Apple
     * ship dark and light up the moment its credentials are in the environment.
     *
     * @return list<array{name: string, label: string}>
     */
    public static function available(): array
    {
        $providers = [];

        foreach (self::providers() as $provider) {
            if ($provider->isConfigured()) {
                $providers[] = ['name' => $provider->name(), 'label' => __('auth.providers.'.$provider->name())];
            }
        }

        return $providers;
    }

    /** @return list<SocialProvider> */
    private static function providers(): array
    {
        $configured = config('services.social_providers', []);
        $providers = [];

        foreach (is_array($configured) ? $configured : [] as $class) {
            if (is_string($class)) {
                $providers[] = app($class);
            }
        }

        return $providers;
    }

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $driver = $this->driver($provider);

        if ($driver === null) {
            return to_route('login')->withErrors(['email' => __('auth.social_unavailable')]);
        }

        $state = Str::random(40);

        $request->session()->put(self::SESSION_KEY, ['provider' => $provider, 'state' => $state]);

        return redirect()->away($driver->redirectUrl($state));
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $expected = $request->session()->pull(self::SESSION_KEY);
        $driver = $this->driver($provider);

        if ($driver === null
            || ! is_array($expected)
            || ($expected['provider'] ?? null) !== $provider
            || ! is_string($state = $request->query('state'))
            || ! hash_equals((string) ($expected['state'] ?? ''), $state)
        ) {
            return $this->refuse('The state did not match the session that started the sign-in.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            // The person pressed cancel at the provider, which is not an error.
            return to_route('login');
        }

        try {
            $identity = $driver->identify($code);
        } catch (SocialAuthFailed $failure) {
            return $this->refuse($failure->getMessage());
        }

        Auth::login($this->userFor($identity), remember: true);

        return to_route('dashboard');
    }

    /**
     * The person behind an identity, signing them up if this is their first visit.
     *
     * Matched on the verified address. A provider that had not verified it never gets
     * this far — see {@see GoogleProvider} — because matching an
     * unverified address to an existing user would let somebody claim an account by
     * asserting its owner's email at Google.
     */
    private function userFor(SocialIdentity $identity): User
    {
        $existing = User::query()->where('email', $identity->email)->first();

        if ($existing instanceof User) {
            $existing->forceFill([
                'oauth_provider' => $identity->provider,
                'oauth_id' => $identity->id,
                'email_verified_at' => $existing->email_verified_at ?? now(),
            ])->save();

            return $existing;
        }

        // A new business, named after the person until they rename it. Asking for a
        // business name mid-redirect would mean holding the identity somewhere across
        // another round trip, and a name is the one thing that is trivial to change.
        $user = $this->provisioner->provision(
            businessName: $identity->name,
            name: $identity->name,
            email: $identity->email,
            attributes: [
                'password' => null,
                'email_verified_at' => now(),
                'oauth_provider' => $identity->provider,
                'oauth_id' => $identity->id,
            ],
        );

        event(new Registered($user));

        return $user;
    }

    private function driver(string $name): ?SocialProvider
    {
        foreach (self::providers() as $provider) {
            if ($provider->name() === $name && $provider->isConfigured()) {
                return $provider;
            }
        }

        return null;
    }

    /** Specific in the log, vague at the browser: the difference would tell an attacker things. */
    private function refuse(string $reason): RedirectResponse
    {
        Log::warning('Social sign-in refused.', ['reason' => $reason]);

        return to_route('login')->withErrors(['email' => __('auth.social_failed')]);
    }
}
