<?php

declare(strict_types=1);

use App\Domain\Tenancy\CurrentBusiness;
use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Signing in through Google.
 *
 * The handshake is faked rather than performed — a test that reaches Google is a test
 * that fails when Google has an outage, and it could not exercise a refusal at all. The
 * faking is at the HTTP boundary, so everything this application actually does is real:
 * the state check, the verified-address rule, the provisioning, the matching.
 */
beforeEach(function (): void {
    config()->set('services.google.client_id', 'test-client-id');
    config()->set('services.google.client_secret', 'test-client-secret');

    (new RolePermissionSeeder)->run();
});

/** @param array<string, mixed> $profile */
function googleAnswers(array $profile = []): void
{
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'a-token']),
        'www.googleapis.com/oauth2/v3/userinfo' => Http::response([
            'sub' => '1234567890',
            'email' => 'omar@example.com',
            'email_verified' => true,
            'name' => 'Omar Elsayed',
            ...$profile,
        ]),
    ]);
}

/** Walk the redirect out, keeping the state it put in the session. */
function startAndFinish(string $query = 'code=an-auth-code'): TestResponse
{
    $redirect = test()->get('/auth/google/redirect');
    $state = session('social.state')['state'];

    return test()->get("/auth/google/callback?{$query}&state={$state}");
}

describe('leaving for Google', function (): void {
    it('sends the browser to Google with a state it keeps', function (): void {
        $response = $this->get('/auth/google/redirect');

        $target = $response->headers->get('Location');

        expect($target)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth')
            ->and($target)->toContain('client_id=test-client-id')
            ->and($target)->toContain(urlencode(session('social.state')['state']));
    });

    it('offers nothing for a provider with no credentials', function (): void {
        config()->set('services.google.client_id', null);

        $this->get('/auth/google/redirect')->assertRedirect(route('login'));
    });

    it('shows the button only when the provider is configured', function (): void {
        expect($this->get('/login')->viewData('page')['props']['providers'])
            ->toBe([['name' => 'google', 'label' => 'Continue with Google']]);

        config()->set('services.google.client_secret', null);

        expect($this->get('/login')->viewData('page')['props']['providers'])->toBe([]);
    });
});

/*
 * The state parameter is the security of this whole flow. Without it, a link carrying
 * an attacker's authorisation code signs whoever clicks it into the attacker's account,
 * and every figure they then record goes into the attacker's books.
 */
describe('coming back', function (): void {
    it('refuses a callback whose state does not match the session', function (): void {
        googleAnswers();

        $this->get('/auth/google/redirect');

        $this->get('/auth/google/callback?code=an-auth-code&state=not-the-one')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    });

    it('refuses a callback with no session behind it at all', function (): void {
        googleAnswers();

        $this->get('/auth/google/callback?code=an-auth-code&state=anything')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    });

    it('will not let one state be used twice', function (): void {
        googleAnswers();

        $redirect = $this->get('/auth/google/redirect');
        $state = session('social.state')['state'];

        $this->get("/auth/google/callback?code=an-auth-code&state={$state}");
        $this->post('/logout');

        $this->get("/auth/google/callback?code=an-auth-code&state={$state}")
            ->assertRedirect(route('login'));

        $this->assertGuest();
    });

    it('takes somebody pressing cancel back to the sign-in page quietly', function (): void {
        googleAnswers();

        $this->get('/auth/google/redirect');
        $state = session('social.state')['state'];

        $this->get("/auth/google/callback?error=access_denied&state={$state}")
            ->assertRedirect(route('login'))
            ->assertSessionHasNoErrors();
    });
});

describe('who it signs in', function (): void {
    it('signs up a new person with their own business', function (): void {
        googleAnswers();

        startAndFinish()->assertRedirect(route('dashboard'));

        $user = app(CurrentBusiness::class)->across(
            fn (): User => User::query()->where('email', 'omar@example.com')->sole(),
        );

        expect($user->hasRole(Role::Owner->value))->toBeTrue()
            ->and($user->oauth_provider)->toBe('google')
            ->and($user->business?->owner_id)->toBe($user->getKey())
            // Verified by Google, so there is nothing left for us to verify.
            ->and($user->email_verified_at)->not->toBeNull()
            // Nobody typed one, and nothing here should pretend otherwise.
            ->and($user->password)->toBeNull();
    });

    it('signs the same person back into the same business, not a second one', function (): void {
        googleAnswers();

        startAndFinish();
        $this->post('/logout');
        startAndFinish();

        $counts = app(CurrentBusiness::class)->across(fn (): array => [
            'users' => User::query()->where('email', 'omar@example.com')->count(),
            // One for the business each test starts inside, one for this sign-up.
            'businesses' => Business::query()->count(),
        ]);

        expect($counts)->toBe(['users' => 1, 'businesses' => 2]);
    });

    /*
     * Google will hand out a profile for an address the account holder has never proved
     * they control, and this application matches an incoming identity to an existing
     * user *by address*. Accepting an unverified one would let somebody claim an
     * existing account by asserting its owner's address at Google.
     */
    it('refuses an address Google has not verified', function (): void {
        googleAnswers(['email_verified' => false]);

        startAndFinish()->assertRedirect(route('login'));

        $this->assertGuest();

        expect(app(CurrentBusiness::class)->across(fn (): int => User::query()->count()))->toBe(0);
    });

    it('refuses a profile with no address on it', function (): void {
        googleAnswers(['email' => '']);

        startAndFinish()->assertRedirect(route('login'));

        $this->assertGuest();
    });

    it('refuses when Google will not exchange the code', function (): void {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        startAndFinish()->assertRedirect(route('login'));

        $this->assertGuest();
    });

    // Google being down is not this person's problem to read a stack trace about.
    it('refuses gracefully when Google cannot be reached at all', function (): void {
        Http::fake(fn () => throw new ConnectionException('network is down'));

        startAndFinish()->assertRedirect(route('login'));

        $this->assertGuest();
    });

    // Somebody who signed up with a password and later presses the Google button is the
    // same person, and must land in the books they already have.
    it('attaches the provider to an account that already exists', function (): void {
        $existing = app(CurrentBusiness::class)->across(fn (): User => User::factory()->create([
            'email' => 'omar@example.com',
            'business_id' => $this->business->getKey(),
        ]));

        googleAnswers();

        startAndFinish()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($existing->fresh());

        expect($existing->fresh()?->oauth_provider)->toBe('google')
            ->and($existing->fresh()?->business_id)->toBe($this->business->getKey())
            ->and(app(CurrentBusiness::class)->across(fn (): int => Business::query()->count()))->toBe(1);
    });
});
