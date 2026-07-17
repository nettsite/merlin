<?php

use App\Http\Controllers\DocumentMediaController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware(['auth', 'verified'])->group(function (): void {
    // Expenses
    Route::livewire('suppliers', 'pages.suppliers.index')->name('suppliers.index');
    Route::livewire('suppliers/{id}', 'pages.suppliers.show')->name('suppliers.show');
    Route::livewire('purchase-invoices', 'pages.purchase-invoices.index')->name('purchase-invoices.index');
    Route::livewire('payment-notifications', 'pages.payment-notifications.index')->name('payment-notifications.index');
    Route::livewire('posting-rules', 'pages.posting-rules.index')->name('posting-rules.index');

    // Billing
    Route::livewire('contacts', 'pages.contacts.index')->name('contacts.index');
    Route::livewire('clients', 'pages.clients.index')->name('clients.index');
    Route::livewire('clients/{id}', 'pages.clients.show')->name('clients.show');
    Route::livewire('payment-terms', 'pages.payment-terms.index')->name('payment-terms.index');
    Route::livewire('sales-invoices', 'pages.sales-invoices.index')->name('sales-invoices.index');
    Route::livewire('quotes', 'pages.quotes.index')->name('quotes.index');
    Route::livewire('credit-notes', 'pages.credit-notes.index')->name('credit-notes.index');
    Route::livewire('recurring-invoices', 'pages.recurring-invoices.index')->name('recurring-invoices.index');

    // Accounting
    Route::livewire('bank-statements', 'pages.bank-statements.index')->name('bank-statements.index');
    Route::livewire('bank-templates', 'pages.bank-templates.index')->name('bank-templates.index');
    Route::livewire('accounts', 'pages.accounts.index')->name('accounts.index');
    Route::livewire('accounts/{id}', 'pages.accounts.show')->name('accounts.show');
    Route::livewire('account-groups', 'pages.account-groups.index')->name('account-groups.index');

    // Reports
    Route::redirect('reports', '/reports/income-statement');
    Route::livewire('reports/income-statement', 'pages.reports.income-statement')->name('reports.income-statement');
    Route::livewire('reports/trial-balance', 'pages.reports.trial-balance')->name('reports.trial-balance');
    Route::livewire('reports/balance-sheet', 'pages.reports.balance-sheet')->name('reports.balance-sheet');
    Route::livewire('reports/income-by-client', 'pages.reports.income-by-client')->name('reports.income-by-client');
    Route::livewire('reports/income-by-account', 'pages.reports.income-by-account')->name('reports.income-by-account');
    Route::livewire('reports/expenses-by-account', 'pages.reports.expenses-by-account')->name('reports.expenses-by-account');
    Route::livewire('reports/expenses-by-supplier', 'pages.reports.expenses-by-supplier')->name('reports.expenses-by-supplier');
    Route::livewire('reports/llm-performance', 'pages.reports.llm-performance')->name('reports.llm-performance');

    // Settings (unified)
    Route::livewire('settings', 'pages.settings.index')->name('settings.index');
    Route::redirect('settings/general', '/settings?tab=general')->name('settings.general');
    Route::redirect('settings/purchasing', '/settings?tab=purchasing')->name('settings.purchasing');
    Route::redirect('settings/billing', '/settings?tab=billing')->name('settings.billing');

    // Administration
    Route::livewire('roles', 'pages.roles.index')->name('roles.index');
    Route::livewire('users', 'pages.users.index')->name('users.index');
    Route::livewire('llm-logs', 'pages.llm-logs.index')->name('llm-logs.index');

    // Help
    Route::livewire('help', 'pages.help.index')->name('help');

    // Authorized document file streaming (media stored on a private disk)
    Route::get('documents/media/{media}', DocumentMediaController::class)->name('documents.media');
});

require __DIR__.'/auth.php';
