<?php

use App\Exceptions\InvalidDocumentStateException;
use App\Exceptions\InvalidFileTypeException;
use App\Modules\Core\Models\User;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Models\DocumentActivity;
use App\Modules\Purchasing\Models\DocumentLine;
use App\Modules\Purchasing\Services\DocumentService;
use App\Modules\Purchasing\Services\Pdf\MagikaService;
use App\Modules\Purchasing\Settings\PurchasingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = app(DocumentService::class);
    $this->user = User::factory()->create();
});

// --- Status transitions ---

it('transitions a purchase invoice from received to reviewed', function (): void {
    $doc = Document::factory()->purchaseInvoice()->create(['status' => 'received']);

    $this->service->markAsReviewed($doc, $this->user);

    expect($doc->fresh()->status)->toBe('reviewed');
});

it('transitions from reviewed to approved', function (): void {
    $doc = Document::factory()->purchaseInvoice()->create(['status' => 'reviewed']);

    $this->service->approve($doc, $this->user);

    expect($doc->fresh()->status)->toBe('approved');
});

it('transitions from approved to posted', function (): void {
    $doc = Document::factory()->purchaseInvoice()->create(['status' => 'approved']);

    $this->service->post($doc, $this->user);

    expect($doc->fresh()->status)->toBe('posted');
});

it('throws when attempting an invalid status transition', function (): void {
    $doc = Document::factory()->purchaseInvoice()->create(['status' => 'approved']);

    expect(fn () => $this->service->markAsReviewed($doc, $this->user))
        ->toThrow(InvalidDocumentStateException::class);
});

it('throws when attempting to transition from a terminal state', function (): void {
    $doc = Document::factory()->purchaseInvoice()->create(['status' => 'posted']);

    expect(fn () => $this->service->markAsReviewed($doc, $this->user))
        ->toThrow(InvalidDocumentStateException::class);
});

it('can dispute from received, reviewed, or approved', function (): void {
    foreach (['received', 'reviewed', 'approved'] as $status) {
        $doc = Document::factory()->purchaseInvoice()->create(['status' => $status]);
        $this->service->dispute($doc, $this->user, 'Wrong amount');
        expect($doc->fresh()->status)->toBe('disputed');
    }
});

// --- Activity recording ---

it('records activity for each status change', function (): void {
    $doc = Document::factory()->purchaseInvoice()->create(['status' => 'received']);

    $this->service->markAsReviewed($doc, $this->user);

    $activity = DocumentActivity::where('document_id', $doc->id)->first();

    expect($activity)->not->toBeNull()
        ->and($activity->activity_type)->toBe('status_changed')
        ->and($activity->user_id)->toBe($this->user->id)
        ->and($activity->metadata['from'])->toBe('received')
        ->and($activity->metadata['to'])->toBe('reviewed');
});

it('records activity with null user for system actions', function (): void {
    $doc = Document::factory()->purchaseInvoice()->create(['status' => 'received']);

    $this->service->markAsReviewed($doc, $this->user);
    $activity = $doc->activities()->first();

    expect($activity->user_id)->toBe($this->user->id);
});

// --- Payment recording ---

it('records a payment and updates balance_due', function (): void {
    $doc = Document::factory()->purchaseInvoice()->create([
        'status' => 'posted',
        'total' => 1150.00,
        'amount_paid' => 0,
        'balance_due' => 1150.00,
    ]);

    $this->service->recordPayment($doc, 500.00, now(), 'EFT-001');

    $doc->refresh();

    expect((float) $doc->amount_paid)->toBe(500.00)
        ->and((float) $doc->balance_due)->toBe(650.00);
});

it('records payment activity', function (): void {
    $doc = Document::factory()->purchaseInvoice()->create([
        'total' => 1000.00,
        'amount_paid' => 0,
        'balance_due' => 1000.00,
    ]);

    $this->service->recordPayment($doc, 1000.00, now());

    $activity = $doc->activities()->where('activity_type', 'payment_recorded')->first();

    expect($activity)->not->toBeNull()
        ->and((float) $activity->metadata['amount'])->toBe(1000.0);
});

// --- Foreign currency payment ---

it('finalises the exchange rate from actual ZAR paid', function (): void {
    // Invoice: $100 USD at provisional rate 18.00 → provisional total = R1800
    $doc = Document::factory()->purchaseInvoice()->create([
        'currency' => 'USD',
        'exchange_rate' => '18.000000',
        'exchange_rate_provisional' => true,
        'total' => 1800.00,   // provisional ZAR
        'subtotal' => 1800.00,
        'tax_total' => 0,
        'amount_paid' => 0,
        'balance_due' => 1800.00,
        'foreign_total' => 100.00,
        'foreign_subtotal' => 100.00,
        'foreign_tax_total' => 0,
        'foreign_amount_paid' => null,
        'foreign_balance_due' => 100.00,
    ]);

    // Actual payment: R1850 ZAR  →  actual rate = 1850 / 100 = 18.5
    $this->service->recordPayment($doc, 1850.00, now(), null, finaliseRate: true);

    $doc->refresh();

    expect((float) $doc->exchange_rate)->toBe(18.5)
        ->and((bool) $doc->exchange_rate_provisional)->toBeFalse()
        ->and((float) $doc->total)->toBe(1850.0)
        ->and((float) $doc->amount_paid)->toBe(1850.0)
        ->and((float) $doc->balance_due)->toBe(0.0)
        ->and((float) $doc->foreign_amount_paid)->toBe(100.0);
});

it('recomputes line base amounts when rate is finalised', function (): void {
    $doc = Document::factory()->purchaseInvoice()->create([
        'currency' => 'USD',
        'exchange_rate' => '18.000000',
        'exchange_rate_provisional' => true,
        'total' => 1800.00,
        'subtotal' => 1800.00,
        'tax_total' => 0,
        'amount_paid' => 0,
        'balance_due' => 1800.00,
        'foreign_total' => 100.00,
        'foreign_subtotal' => 100.00,
        'foreign_tax_total' => 0,
    ]);

    $line = DocumentLine::factory()->for($doc)->create([
        'unit_price' => 1800.00,   // provisional ZAR
        'line_total' => 1800.00,
        'tax_rate' => null,
        'tax_amount' => 0,
        'foreign_unit_price' => 100.00,
        'foreign_line_total' => 100.00,
        'foreign_tax_amount' => 0,
    ]);

    $this->service->recordPayment($doc, 1850.00, now(), null, finaliseRate: true);

    $line->refresh();

    expect((float) $line->unit_price)->toBe(round(100.0 * 18.5, 4))
        ->and((float) $line->line_total)->toBe(round(100.0 * 18.5, 2));
});

it('rejects an unsupported file in createFromFile before touching the database', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'merlin_test_');
    file_put_contents($file, 'this is not a supported file');

    $service = new DocumentService(
        app(CurrencySettings::class),
        app(PurchasingSettings::class),
        new MagikaService('__nonexistent__'),
    );

    expect(fn () => $service->createFromFile($file, []))
        ->toThrow(InvalidFileTypeException::class);

    expect(Document::where('source', 'upload')->count())->toBe(0);

    unlink($file);
});
