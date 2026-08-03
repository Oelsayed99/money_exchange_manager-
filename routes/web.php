<?php

use App\Http\Controllers\CurrencyController;
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
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
