<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

describe('shared props', function (): void {
    it('defaults to English, left to right', function (): void {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('locale', 'en')
                ->where('direction', 'ltr')
            );
    });

    it('reports right-to-left for Arabic', function (): void {
        $user = User::factory()->create(['locale' => 'ar']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('locale', 'ar')
                ->where('direction', 'rtl')
            );
    });

    it('lists the supported locales with their direction', function (): void {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('locales', 2)
                ->where('locales.0.code', 'en')
                ->where('locales.1.code', 'ar')
                ->where('locales.1.native', 'العربية')
                ->where('locales.1.direction', 'rtl')
            );
    });

    it('ships the translation bundle to the client', function (): void {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('translations.nav.currencies', 'Currencies')
                ->where('translations.common.save', 'Save')
            );
    });

    it('ships Arabic strings when the locale is Arabic', function (): void {
        $user = User::factory()->create(['locale' => 'ar']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('translations.nav.currencies', 'العملات')
                ->where('translations.common.save', 'حفظ')
                ->where('translations.currencies.title', 'العملات')
            );
    });

    // The settings screens were shipped untranslated: the group existed on no page
    // because it was never added to the client bundle.
    it('ships the settings group in both languages', function (): void {
        $this->actingAs(User::factory()->create())
            ->get('/settings/profile')
            ->assertInertia(fn (Assert $page) => $page
                ->where('translations.settings.title', 'Settings')
                ->where('translations.settings.nav.password', 'Password')
                ->where('translations.settings.delete.button', 'Delete account')
            );

        $arabic = User::factory()->create(['locale' => 'ar']);

        $this->actingAs($arabic)
            ->get('/settings/profile')
            ->assertInertia(fn (Assert $page) => $page
                ->where('translations.settings.title', 'الإعدادات')
                ->where('translations.settings.nav.password', 'كلمة المرور')
                ->where('translations.settings.appearance.dark', 'داكن')
                ->where('translations.settings.delete.button', 'حذف الحساب')
            );
    });

    // Every key referenced by the settings screens must exist in both languages, or a
    // user sees a raw dotted key where a label should be.
    it('has no untranslated settings keys in Arabic', function (): void {
        $english = trans('settings', [], 'en');
        $arabic = trans('settings', [], 'ar');

        $flatten = function (array $values, string $prefix = '') use (&$flatten): array {
            $keys = [];
            foreach ($values as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                $keys = [...$keys, ...(is_array($value) ? $flatten($value, $path) : [$path])];
            }

            return $keys;
        };

        expect($flatten($arabic))->toBe($flatten($english));
    });
});

describe('switching', function (): void {
    it('persists the choice on the authenticated user', function (): void {
        $user = User::factory()->create(['locale' => null]);

        $this->actingAs($user)
            ->from('/dashboard')
            ->put('/locale', ['locale' => 'ar'])
            ->assertRedirect('/dashboard');

        expect($user->fresh()?->locale)->toBe('ar');
    });

    // Section 12: the switch must not break the current page or lose its state. The
    // controller redirects back rather than to a fixed route, which is what preserves
    // the URL — and therefore its query string, filters and page number.
    it('returns to the page the user was on, query string intact', function (): void {
        $this->actingAs(User::factory()->create())
            ->from('/currencies?page=3&sort=code')
            ->put('/locale', ['locale' => 'ar'])
            ->assertRedirect('/currencies?page=3&sort=code');
    });

    it('lets a guest switch language via the session', function (): void {
        $this->from('/login')
            ->put('/locale', ['locale' => 'ar'])
            ->assertRedirect('/login')
            ->assertSessionHas('locale', 'ar');
    });

    it('applies a guest session locale to the next request', function (): void {
        $this->withSession(['locale' => 'ar'])
            ->get('/login')
            ->assertInertia(fn (Assert $page) => $page->where('direction', 'rtl'));
    });

    it('rejects an unsupported locale', function (): void {
        $user = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)
            ->from('/dashboard')
            ->put('/locale', ['locale' => 'fr'])
            ->assertSessionHasErrors('locale');

        expect($user->fresh()?->locale)->toBe('en');
    });

    // The locale reaches the translator and the html lang attribute, so it must never
    // be trusted as arbitrary input.
    it('ignores an unsupported locale already stored on the user', function (): void {
        $user = User::factory()->create(['locale' => 'zz']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    });
});

describe('fallback', function (): void {
    // Section 12 forbids hardcoded strings; it does not require every string to exist
    // in every language before a page can render. An untranslated key shows English.
    // Previously demonstrated with auth.failed, which had no Arabic translation. It has
    // one now — every string does — so the example is registered here instead. Proving
    // fallback with a real gap would mean keeping a gap for the test's benefit.
    it('falls back to English for a key with no Arabic translation', function (): void {
        app('translator')->addLines(['fallback.probe' => 'English only'], 'en');

        app()->setLocale('ar');

        expect(__('currencies.fields.code'))->toBe('الرمز')
            ->and(__('fallback.probe'))->toBe('English only');
    });

    it('translates validation messages into Arabic', function (): void {
        // Needs the permission to manage currencies, or authorization rejects the
        // request before any validation message is produced.
        $user = userWithRole(Role::Administrator);
        $user->update(['locale' => 'ar']);

        $response = $this->actingAs($user)
            ->from('/currencies/create')
            ->post('/currencies', ['code' => '']);

        $errors = session('errors');

        expect($errors?->first('code'))->toContain('مطلوب');
    });
});

describe('document direction', function (): void {
    it('sets the html dir attribute server-side for Arabic', function (): void {
        $user = User::factory()->create(['locale' => 'ar']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertSee('dir="rtl"', false)
            ->assertSee('lang="ar"', false);
    });

    it('sets the html dir attribute to ltr for English', function (): void {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertSee('dir="ltr"', false);
    });

    // Section 13: the theme must be applied before first paint, which means from the
    // server-rendered document rather than a React effect.
    it('applies the theme in a blocking script before paint', function (): void {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertSee("localStorage.getItem('appearance')", false);
    });
});
