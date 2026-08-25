<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Money\CurrencyRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Both of these hold a lookup for the life of a request and both said so in
         * their own docblocks — "registered as a singleton", "loaded once per request".
         * Neither was actually registered, so the container built a fresh one on every
         * resolution and each reloaded its table.
         *
         * The cost was invisible until it was measured: `select * from currencies` ran
         * fifteen times on one page, once per Money the page hydrated. Nothing was
         * wrong with the caching — it was simply thrown away between uses.
         *
         * Not persistent caches. An administrator changing a currency's precision must
         * take effect on the next request, not whenever a cache happens to expire.
         */
        $this->app->singleton(CurrencyRegistry::class);
        $this->app->singleton(LedgerAccountResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
