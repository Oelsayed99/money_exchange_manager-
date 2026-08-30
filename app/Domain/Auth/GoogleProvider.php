<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Auth\Exceptions\SocialAuthFailed;
use App\Http\Controllers\Auth\SocialAuthController;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Sign in with Google, spoken directly.
 *
 * The standard authorisation-code flow and nothing more: send the browser to Google
 * with a state we generated, exchange the code it comes back with for a token, ask who
 * the token belongs to. Roughly sixty lines, no package, and no third-party abstraction
 * between this application and the one provider it uses.
 *
 * ## What is checked, and why
 *
 * `email_verified` is required. Google will hand out a profile for an address the
 * account holder has never proved they control, and this application matches an
 * incoming identity to an existing user *by address*. Accepting an unverified one would
 * let somebody claim an account by asserting its owner's email at Google.
 *
 * Nothing here trusts the callback's query string on its own: the `state` is compared
 * against the session by {@see SocialAuthController}, which
 * is what stops a link in an email from signing somebody into an attacker's account.
 */
final class GoogleProvider implements SocialProvider
{
    private const string AUTHORISE = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const string TOKEN = 'https://oauth2.googleapis.com/token';

    private const string PROFILE = 'https://www.googleapis.com/oauth2/v3/userinfo';

    public function name(): string
    {
        return 'google';
    }

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function redirectUrl(string $state): string
    {
        return self::AUTHORISE.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->callbackUrl(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,

            // Ask every time rather than silently reusing whichever Google account the
            // browser happens to be signed into. Somebody with two addresses signing up
            // for a second business must be able to choose.
            'prompt' => 'select_account',
        ]);
    }

    public function identify(string $code): SocialIdentity
    {
        $token = $this->exchange($code);
        $profile = $this->profile($token);

        $id = $profile['sub'] ?? null;
        $email = $profile['email'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($email) || $email === '') {
            throw SocialAuthFailed::because('Google returned a profile with no id or no address.');
        }

        if (($profile['email_verified'] ?? false) !== true) {
            throw SocialAuthFailed::because("Google has not verified [{$email}], so it cannot be used to claim an account.");
        }

        $name = $profile['name'] ?? null;

        return new SocialIdentity(
            provider: $this->name(),
            id: $id,
            email: mb_strtolower($email),
            name: is_string($name) && $name !== '' ? $name : (string) strstr($email.'@', '@', true),
            emailVerified: true,
        );
    }

    private function exchange(string $code): string
    {
        try {
            $response = Http::asForm()->timeout(15)->post(self::TOKEN, [
                'code' => $code,
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'redirect_uri' => $this->callbackUrl(),
                'grant_type' => 'authorization_code',
            ]);
        } catch (ConnectionException $unreachable) {
            // Google being down is not this person's problem to read a stack trace
            // about. They get the sign-in page and a sentence; the log gets the cause.
            throw SocialAuthFailed::because('Google could not be reached: '.$unreachable->getMessage());
        }

        if ($response->failed()) {
            throw SocialAuthFailed::because('Google refused the authorisation code.');
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw SocialAuthFailed::because('Google returned no access token.');
        }

        return $token;
    }

    /** @return array<string, mixed> */
    private function profile(string $token): array
    {
        try {
            $response = Http::withToken($token)->timeout(15)->get(self::PROFILE);
        } catch (ConnectionException $unreachable) {
            throw SocialAuthFailed::because('Google could not be reached: '.$unreachable->getMessage());
        }

        if ($response->failed()) {
            throw SocialAuthFailed::because('Google would not say who the token belongs to.');
        }

        $profile = $response->json();

        return is_array($profile) ? $profile : throw SocialAuthFailed::because('Google returned a profile that is not an object.');
    }

    private function clientId(): string
    {
        $value = config('services.google.client_id');

        return is_string($value) ? $value : '';
    }

    private function clientSecret(): string
    {
        $value = config('services.google.client_secret');

        return is_string($value) ? $value : '';
    }

    private function callbackUrl(): string
    {
        $configured = config('services.google.redirect');

        return is_string($configured) && $configured !== ''
            ? $configured
            : route('social.callback', ['provider' => $this->name()]);
    }
}
