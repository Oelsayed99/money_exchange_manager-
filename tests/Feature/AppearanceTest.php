<?php

declare(strict_types=1);

use App\Models\User;

it('persists the theme against the user', function (): void {
    $user = User::factory()->create(['theme' => null]);

    $this->actingAs($user)
        ->from('/settings/appearance')
        ->put('/settings/appearance', ['appearance' => 'dark'])
        ->assertRedirect('/settings/appearance');

    expect($user->fresh()?->theme)->toBe('dark');
});

it('accepts every supported appearance', function (string $appearance): void {
    $user = User::factory()->create();

    $this->actingAs($user)->put('/settings/appearance', ['appearance' => $appearance]);

    expect($user->fresh()?->theme)->toBe($appearance);
})->with(['light', 'dark', 'system']);

it('rejects an unsupported appearance', function (): void {
    $user = User::factory()->create(['theme' => 'dark']);

    $this->actingAs($user)
        ->from('/settings/appearance')
        ->put('/settings/appearance', ['appearance' => 'neon'])
        ->assertSessionHasErrors('appearance');

    expect($user->fresh()?->theme)->toBe('dark');
});

it('requires authentication', function (): void {
    $this->put('/settings/appearance', ['appearance' => 'dark'])->assertRedirect('/login');
});

// Section 13 forbids a theme flash. For a signed-in user the preference is known to
// the server, so it must reach the blocking script rather than waiting for the client
// to read localStorage.
it('renders the saved preference into the blocking script', function (): void {
    $user = User::factory()->create(['theme' => 'dark']);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSee("var saved = 'dark'", false);
});

it('renders a null preference for a user who has not chosen', function (): void {
    $user = User::factory()->create(['theme' => null]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSee('var saved = null', false);
});

it('renders a null preference for a guest', function (): void {
    $this->get('/login')->assertSee('var saved = null', false);
});

// The preference must reach the client so the settings screen shows the right option
// selected, rather than defaulting to system on every visit.
it('exposes the preference to the client', function (): void {
    $user = User::factory()->create(['theme' => 'light']);

    $this->actingAs($user)
        ->get('/settings/appearance')
        ->assertInertia(fn ($page) => $page->where('auth.user.theme', 'light'));
});
