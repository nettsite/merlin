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
    Volt::route('suppliers/{id}', 'pages.suppliers.show')->name('suppliers.show');
    Volt::route('purchase-invoices', 'pages.purchase-invoices.index')->name('purchase-invoices.index');
    Volt::route('posting-rules', 'pages.posting-rules.index')->name('posting-rules.index');

    // Billing
    Volt::route('contacts', 'pages.contacts.index')->name('contacts.index');
    Volt::route('clients', 'pages.clients.index')->name('clients.index');
    Volt::route('clients/{id}', 'pages.clients.show')->name('clients.show');
    Volt::route('payment-terms', 'pages.payment-terms.index')->name('payment-terms.index');
    Volt::route('sales-invoices', 'pages.sales-invoices.index')->name('sales-invoices.index');
    Volt::route('quotes', 'pages.quotes.index')->name('quotes.index');
    Volt::route('credit-notes', 'pages.credit-notes.index')->name('credit-notes.index');
    Volt::route('recurring-invoices', 'pages.recurring-invoices.index')->name('recurring-invoices.index');

    // Accounting
    Volt::route('bank-statements', 'pages.bank-statements.index')->name('bank-statements.index');
    Volt::route('accounts', 'pages.accounts.index')->name('accounts.index');
    Volt::route('account-groups', 'pages.account-groups.index')->name('account-groups.index');

    // Reports
    Route::redirect('reports', '/reports/income-statement');
    Volt::route('reports/income-statement', 'pages.reports.income-statement')->name('reports.income-statement');
    Volt::route('reports/trial-balance', 'pages.reports.trial-balance')->name('reports.trial-balance');
    Volt::route('reports/balance-sheet', 'pages.reports.balance-sheet')->name('reports.balance-sheet');
    Volt::route('reports/income-by-client', 'pages.reports.income-by-client')->name('reports.income-by-client');
    Volt::route('reports/income-by-account', 'pages.reports.income-by-account')->name('reports.income-by-account');
    Volt::route('reports/expenses-by-account', 'pages.reports.expenses-by-account')->name('reports.expenses-by-account');
    Volt::route('reports/expenses-by-supplier', 'pages.reports.expenses-by-supplier')->name('reports.expenses-by-supplier');
    Volt::route('reports/llm-performance', 'pages.reports.llm-performance')->name('reports.llm-performance');

    // Settings (unified)
    Volt::route('settings', 'pages.settings.index')->name('settings.index');
    Route::redirect('settings/general', '/settings?tab=general')->name('settings.general');
    Route::redirect('settings/purchasing', '/settings?tab=purchasing')->name('settings.purchasing');
    Route::redirect('settings/billing', '/settings?tab=billing')->name('settings.billing');

    // Administration
    Volt::route('roles', 'pages.roles.index')->name('roles.index');
    Volt::route('users', 'pages.users.index')->name('users.index');
    Volt::route('llm-logs', 'pages.llm-logs.index')->name('llm-logs.index');

    // Help
    Volt::route('help', 'pages.help.index')->name('help');
});

require __DIR__.'/auth.php';
