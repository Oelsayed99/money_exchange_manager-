<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CounterpartyController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\ExchangeController;
use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

// Available to guests too, so the language can be switched from the login screen.
Route::put('locale', [LocaleController::class, 'update'])->name('locale.update');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    // No destroy: currencies are referenced by ledger history and are deactivated,
    // never deleted. See CurrencyController.
    Route::resource('currencies', CurrencyController::class)
        ->except(['show', 'destroy']);

    // Same reasoning: both are referenced by history that must stay reproducible, so
    // they are deactivated rather than deleted.
    Route::resource('accounts', AccountController::class)
        ->except(['show', 'destroy']);

    Route::resource('counterparties', CounterpartyController::class)
        ->except(['show', 'destroy']);

    // The preview computes the margin without recording anything, on the server, using
    // the same calculator that runs when the deal is stored.
    Route::get('exchange', [ExchangeController::class, 'create'])->name('exchange.create');
    Route::post('exchange/preview', [ExchangeController::class, 'preview'])->name('exchange.preview');
    Route::post('exchange', [ExchangeController::class, 'store'])->name('exchange.store');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
