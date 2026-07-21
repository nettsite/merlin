<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentLine;
use App\Modules\Core\Models\User;
use App\Modules\Purchasing\Services\PaymentEvidenceRecorder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
    $this->recorder = app(PaymentEvidenceRecorder::class);
});

it('caps the applied amount at the outstanding balance due', function (): void {
    $invoice = Document::factory()->purchaseInvoice()->create(['status' => 'posted']);
    DocumentLine::factory()->for($invoice)->create(['unit_price' => 1000, 'tax_rate' => 15]);
    $invoice->refresh();
    expect((float) $invoice->balance_due)->toBe(1150.0);

    // Evidence claims more than is actually owed — apply only the balance due.
    $this->recorder->record($invoice, 2000.0, Carbon::now(), 'REF-1', 'test evidence');

    $invoice->refresh();

    expect((float) $invoice->balance_due)->toBe(0.0)
        ->and($invoice->status)->toBe('paid');
});

it('records the configured contra account on the auto-created payment so the credit leg posts', function (): void {
    $contraAccount = Account::factory()->create();
    $settings = app(BillingSettings::class);
    $settings->default_contra_account_id = $contraAccount->id;
    $settings->save();

    $invoice = Document::factory()->purchaseInvoice()->create(['status' => 'posted']);
    DocumentLine::factory()->for($invoice)->create(['unit_price' => 1000, 'tax_rate' => 15]);
    $invoice->refresh();

    $this->recorder->record($invoice, 1150.0, Carbon::now(), 'REF-1', 'test evidence');

    $payment = $invoice->fresh()->childDocuments()
        ->wherePivot('relationship_type', 'payment_for')
        ->first();

    expect($payment->contra_account_id)->toBe($contraAccount->id);
});

it('ignores a zero or negative amount', function (): void {
    $invoice = Document::factory()->purchaseInvoice()->create(['status' => 'posted']);
    DocumentLine::factory()->for($invoice)->create(['unit_price' => 1000, 'tax_rate' => 15]);
    $invoice->refresh();
    $originalBalance = (float) $invoice->balance_due;

    $this->recorder->record($invoice, 0.0, Carbon::now(), null, 'test evidence');

    expect((float) $invoice->fresh()->balance_due)->toBe($originalBalance);
});
