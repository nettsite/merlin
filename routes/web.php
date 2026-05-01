<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware(['auth', 'verified'])->group(function (): void {
    // Expenses
    Volt::route('suppliers', 'pages.suppliers.index')->name('suppliers.index');
    Volt::route('purchase-invoices', 'pages.purchase-invoices.index')->name('purchase-invoices.index');
    Volt::route('posting-rules', 'pages.posting-rules.index')->name('posting-rules.index');

    // Billing
    Volt::route('clients', 'pages.clients.index')->name('clients.index');
    Volt::route('payment-terms', 'pages.payment-terms.index')->name('payment-terms.index');
    Volt::route('sales-invoices', 'pages.sales-invoices.index')->name('sales-invoices.index');

    // Accounting
    Volt::route('accounts', 'pages.accounts.index')->name('accounts.index');
    Volt::route('account-groups', 'pages.account-groups.index')->name('account-groups.index');

    // Reports
    Route::redirect('reports', '/reports/expenses-by-account');
    Volt::route('reports/expenses-by-account', 'pages.reports.expenses-by-account')->name('reports.expenses-by-account');
    Volt::route('reports/expenses-by-supplier', 'pages.reports.expenses-by-supplier')->name('reports.expenses-by-supplier');
    Volt::route('reports/llm-performance', 'pages.reports.llm-performance')->name('reports.llm-performance');

    // Settings
    Volt::route('settings/general', 'pages.settings.general')->name('settings.general');
    Volt::route('settings/purchasing', 'pages.settings.purchasing')->name('settings.purchasing');
    Volt::route('roles', 'pages.roles.index')->name('roles.index');
    Volt::route('users', 'pages.users.index')->name('users.index');
    Volt::route('llm-logs', 'pages.llm-logs.index')->name('llm-logs.index');
});

require __DIR__.'/auth.php';
