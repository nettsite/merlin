<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::prefix('portal')->name('portal.')->group(function (): void {

    // Guest-only portal routes (redirect portal-authenticated users away)
    Route::middleware('guest:portal')->group(function (): void {
        Volt::route('login', 'pages.portal.auth.login')->name('login');
        Volt::route('set-password/{token}', 'pages.portal.auth.set-password')->name('set-password');
    });

    // Authenticated portal routes
    Route::middleware('auth:portal')->group(function (): void {
        Route::post('logout', function () {
            Auth::guard('portal')->logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();

            return redirect()->route('portal.login');
        })->name('logout');

        // Phase C3 will add /portal/invoices/{invoice} here
    });
});
