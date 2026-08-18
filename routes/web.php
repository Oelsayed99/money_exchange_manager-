<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CounterpartyController;
use App\Http\Controllers\CounterpartyStatementController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\DashboardController;
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
    Route::get('dashboard', DashboardController::class)->name('dashboard');

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

    // The document that replaces the spreadsheet: one party, one currency, every line.
    // The PDF takes the same query string, so what is on screen is what gets handed over.
    Route::get('counterparties/{counterparty}/statement', [CounterpartyStatementController::class, 'show'])
        ->name('counterparties.statement');
    Route::get('counterparties/{counterparty}/statement/pdf', [CounterpartyStatementController::class, 'pdf'])
        ->name('counterparties.statement.pdf');

    // The preview computes the margin without recording anything, on the server, using
    // the same calculator that runs when the deal is stored.
    Route::get('exchange', [ExchangeController::class, 'create'])->name('exchange.create');
    Route::post('exchange/preview', [ExchangeController::class, 'preview'])->name('exchange.preview');

    // Rate, amount in, amount out: give any two and this returns the third, so the
    // operator can enter a deal the way they negotiated it.
    Route::post('exchange/convert', [ExchangeController::class, 'convert'])->name('exchange.convert');
    Route::post('exchange', [ExchangeController::class, 'store'])->name('exchange.store');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
