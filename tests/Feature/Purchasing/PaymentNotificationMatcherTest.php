<?php

use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentActivity;
use App\Modules\Core\Models\DocumentLine;
use App\Modules\Core\Models\User;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\Services\PaymentNotificationMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
    $this->matcher = new PaymentNotificationMatcher(app(CurrencySettings::class));
});

function paymentNotification(array $overrides = []): Document
{
    return Document::factory()->create(array_merge([
        'document_type' => 'payment_notification',
        'status' => 'received',
        'party_id' => null,
        'currency' => 'ZAR',
        'total' => 100.0,
        'issue_date' => now()->toDateString(),
        'metadata' => [],
    ], $overrides));
}

// --- Scoring ---

it('scores a reference match on invoice document_number highly', function (): void {
    $invoice = Document::factory()->purchaseInvoice()->create([
        'document_number' => 'PINV-2024-00001',
        'issue_date' => '2024-01-10',
    ]);

    $notification = paymentNotification([
        'issue_date' => '2024-01-12',
        'metadata' => ['reference_text' => 'Payment for PINV-2024-00001'],
    ]);

    $match = $this->matcher->findInvoiceMatch($notification);

    expect($match)->not->toBeNull()
        ->and($match['document']->id)->toBe($invoice->id)
        ->and($match['confidence'])->toBe(0.95);
});

it('does not match an invoice issued after the payment date', function (): void {
    Document::factory()->purchaseInvoice()->create([
        'document_number' => 'PINV-2024-00002',
        'issue_date' => '2024-01-20',
    ]);

    $notification = paymentNotification([
        'issue_date' => '2024-01-12',
        'metadata' => ['reference_text' => 'Payment for PINV-2024-00002'],
    ]);

    expect($this->matcher->findInvoiceMatch($notification))->toBeNull();
});

it('scores a same-day-only match lower than a reference match', function (): void {
    Document::factory()->purchaseInvoice()->create([
        'document_number' => 'PINV-2024-00003',
        'issue_date' => '2024-01-12',
    ]);

    $notification = paymentNotification([
        'issue_date' => '2024-01-12',
        'metadata' => [],
    ]);

    $match = $this->matcher->findInvoiceMatch($notification);

    expect($match)->not->toBeNull()
        ->and($match['confidence'])->toBe(0.4);
});

it('finds a pending payment notification for a freshly processed invoice (reciprocal check)', function (): void {
    $invoice = Document::factory()->purchaseInvoice()->create([
        'document_number' => 'PINV-2024-00004',
        'issue_date' => '2024-01-10',
    ]);

    $notification = paymentNotification([
        'issue_date' => '2024-01-12',
        'metadata' => ['reference_text' => 'Payment for PINV-2024-00004'],
    ]);

    $match = $this->matcher->findPaymentMatch($invoice);

    expect($match)->not->toBeNull()
        ->and($match['document']->id)->toBe($notification->id);
});

// --- Merge ---

it('corrects a foreign-currency invoice to the confirmed local amount and soft-deletes the notification', function (): void {
    $invoice = Document::factory()->purchaseInvoice()->create([
        'currency' => 'USD',
        'exchange_rate' => 18.0,
        'exchange_rate_provisional' => true,
        'status' => 'received',
    ]);

    $line = DocumentLine::factory()->for($invoice)->create([
        'unit_price' => 1000,
        'tax_rate' => 15,
    ]);

    $invoice->refresh();
    expect((float) $invoice->total)->toBe(1150.0);

    $notification = paymentNotification([
        'currency' => 'ZAR',
        'total' => 2300.0,
        'metadata' => ['payee_name' => 'Acme', 'method' => 'PayPal', 'confirmed' => true],
    ]);

    $this->matcher->merge($invoice, $notification, 0.95, 'test reason');

    $invoice->refresh();
    $line->refresh();

    expect((float) $invoice->total)->toBe(2300.0)
        ->and((float) $line->unit_price)->toBe(2000.0)
        ->and((float) $line->tax_amount)->toBe(300.0)
        ->and((float) $invoice->exchange_rate)->toBe(36.0)
        ->and((bool) $invoice->exchange_rate_provisional)->toBeFalse()
        ->and($invoice->metadata['payment_notification']['amount_applied'])->toBeTrue()
        ->and(Document::find($notification->id))->toBeNull()
        ->and(DocumentActivity::where('document_id', $invoice->id)
            ->where('activity_type', 'payment_notification_matched')
            ->exists())->toBeTrue();
});

it('does not change totals when the invoice is already posted', function (): void {
    $invoice = Document::factory()->purchaseInvoice()->create([
        'currency' => 'USD',
        'exchange_rate' => 18.0,
        'status' => 'posted',
    ]);

    DocumentLine::factory()->for($invoice)->create(['unit_price' => 1000, 'tax_rate' => 15]);
    $invoice->refresh();
    $originalTotal = (float) $invoice->total;

    $notification = paymentNotification(['currency' => 'ZAR', 'total' => 9999.0]);

    $this->matcher->merge($invoice, $notification, 0.95, 'test reason');

    $invoice->refresh();

    expect((float) $invoice->total)->toBe($originalTotal)
        ->and($invoice->metadata['payment_notification']['amount_applied'])->toBeFalse();
});

it('does not change totals when the payment was not made in the base currency', function (): void {
    $invoice = Document::factory()->purchaseInvoice()->create([
        'currency' => 'USD',
        'exchange_rate' => 18.0,
        'status' => 'received',
    ]);

    DocumentLine::factory()->for($invoice)->create(['unit_price' => 1000, 'tax_rate' => 15]);
    $invoice->refresh();
    $originalTotal = (float) $invoice->total;

    // Paid from a USD PayPal balance — not the local currency, nothing reliable to correct against.
    $notification = paymentNotification(['currency' => 'USD', 'total' => 100.0, 'metadata' => ['confirmed' => true]]);

    $this->matcher->merge($invoice, $notification, 0.6, 'test reason');

    $invoice->refresh();

    expect((float) $invoice->total)->toBe($originalTotal)
        ->and($invoice->metadata['payment_notification']['amount_applied'])->toBeFalse();
});

it('does not change totals when the payment notification is only a pending/reserved hold, not confirmed', function (): void {
    // e.g. an FNB card "reserved for purchase" alert — could still be reversed
    // before it settles, so it isn't reliable enough to correct the invoice.
    $invoice = Document::factory()->purchaseInvoice()->create([
        'currency' => 'USD',
        'exchange_rate' => 18.0,
        'status' => 'received',
    ]);

    DocumentLine::factory()->for($invoice)->create(['unit_price' => 1000, 'tax_rate' => 15]);
    $invoice->refresh();
    $originalTotal = (float) $invoice->total;

    $notification = paymentNotification([
        'currency' => 'ZAR',
        'total' => 2300.0,
        'metadata' => ['payee_name' => 'Acme', 'confirmed' => false],
    ]);

    $this->matcher->merge($invoice, $notification, 0.6, 'test reason');

    $invoice->refresh();

    expect((float) $invoice->total)->toBe($originalTotal)
        ->and($invoice->metadata['payment_notification']['amount_applied'])->toBeFalse()
        ->and(Document::find($notification->id))->toBeNull();
});
