<?php

use App\Modules\Core\Models\Document;
use App\Modules\Purchasing\Services\UnpostedInvoicesIncidentDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->detector = new UnpostedInvoicesIncidentDetector;
});

it('returns null when there are no received/reviewed/approved purchase invoices', function (): void {
    Document::factory()->purchaseInvoice()->create(['status' => 'posted']);
    Document::factory()->purchaseInvoice()->create(['status' => 'rejected']);

    expect($this->detector->check())->toBeNull();
});

it('returns incident details when purchase invoices are received/reviewed/approved', function (): void {
    Document::factory()->purchaseInvoice()->create(['status' => 'received']);
    Document::factory()->purchaseInvoice()->create(['status' => 'received']);
    Document::factory()->purchaseInvoice()->create(['status' => 'reviewed']);
    Document::factory()->purchaseInvoice()->create(['status' => 'approved']);
    Document::factory()->purchaseInvoice()->create(['status' => 'posted']); // shouldn't count

    $result = $this->detector->check();

    expect($result)->not->toBeNull()
        ->and($result['metadata']['total'])->toBe(4)
        ->and($result['metadata']['by_status'])->toEqual(['received' => 2, 'reviewed' => 1, 'approved' => 1])
        ->and($result['message'])->toContain('4 purchase invoices');
});

it('ignores sales invoices and other document types', function (): void {
    Document::factory()->salesInvoice()->create(['status' => 'received']);

    expect($this->detector->check())->toBeNull();
});

it('singularizes the message for exactly one invoice', function (): void {
    Document::factory()->purchaseInvoice()->create(['status' => 'received']);

    expect($this->detector->check()['message'])->toContain('1 purchase invoice ');
});
