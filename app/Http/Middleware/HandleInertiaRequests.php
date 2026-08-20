<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Permission as PermissionEnum;
use App\Support\Locale;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Translation groups shipped to the client.
     *
     * Listed explicitly rather than globbed, so adding a group is deliberate and
     * server-only strings can never leak into a page payload.
     *
     * @var list<string>
     */
    private const CLIENT_GROUPS = ['common', 'nav', 'currencies', 'settings', 'accounts', 'counterparties', 'transactions', 'statements', 'dashboard', 'reconciliations', 'movements', 'audit'];

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Split on a hyphen, and not every quote has one. Reading both halves with a
        // default keeps an author-less quote from becoming a null passed to trim().
        $quote = str(Inspiring::quotes()->random())->explode('-');
        $message = trim((string) $quote->get(0, ''));
        $author = trim((string) $quote->get(1, ''));

        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => $message, 'author' => $author],
            'auth' => [
                'user' => $request->user(),
                // Sent so the interface can avoid offering actions that would be
                // refused. This is presentation only — Section 16 requires
                // authorization to be enforced on the backend, and it is, by policies
                // and form requests. Nothing here is a security boundary.
                'permissions' => $this->permissions($request),
            ],
            'locale' => $locale,
            'direction' => Locale::direction($locale),
            'locales' => $this->availableLocales(),
            'translations' => $this->translations($locale),
            'flash' => [
                'success' => $request->session()->get('success'),
            ],
        ];
    }

    /**
     * The permissions the current user actually holds.
     *
     * Only those granted are listed, so the client never receives the shape of the
     * full permission matrix — a user cannot enumerate what they are missing.
     *
     * @return list<string>
     */
    private function permissions(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        return array_values(array_filter(
            PermissionEnum::values(),
            static fn (string $permission): bool => $user->can($permission),
        ));
    }

    /**
     * @return list<array{code: string, native: string, direction: string}>
     */
    private function availableLocales(): array
    {
        $locales = [];

        foreach (Locale::supported() as $code => $meta) {
            $locales[] = [
                'code' => $code,
                'native' => $meta['native'],
                'direction' => $meta['direction'],
            ];
        }

        return $locales;
    }

    /**
     * The client translation bundle for the active locale.
     *
     * Each group is merged over the fallback locale, so a key that has not been
     * translated yet renders as English rather than as a raw dotted key. Section 12
     * forbids hardcoded strings in the interface; it does not require every string to
     * exist in every language before a page can render.
     *
     * @return array<string, mixed>
     */
    private function translations(string $locale): array
    {
        $fallback = config('app.fallback_locale');
        $fallback = is_string($fallback) ? $fallback : 'en';

        $bundle = [];

        foreach (self::CLIENT_GROUPS as $group) {
            $base = Lang::get($group, [], $fallback);
            $current = Lang::get($group, [], $locale);

            $bundle[$group] = match (true) {
                is_array($base) && is_array($current) => array_replace_recursive($base, $current),
                is_array($current) => $current,
                is_array($base) => $base,
                default => [],
            };
        }

        return $bundle;
    }
}
