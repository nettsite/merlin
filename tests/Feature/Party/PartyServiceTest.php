<?php

use App\Modules\Core\Models\Business;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\PartyRelationship;
use App\Modules\Core\Services\PartyService;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    $this->service = app(PartyService::class);
});

it('creates a business party with supplier relationship', function (): void {
    $party = $this->service->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Acme Hosting Pty Ltd',
        'trading_name' => 'Acme Hosting',
        'tax_number' => '12 345 678 901',
        'primary_email' => 'accounts@acme.example',
    ], relationships: ['supplier']);

    expect($party)->toBeInstanceOf(Party::class)
        ->and($party->party_type)->toBe('business')
        ->and($party->primary_email)->toBe('accounts@acme.example');

    $this->assertDatabaseHas('parties', [
        'id' => $party->id,
        'party_type' => 'business',
    ]);

    $this->assertDatabaseHas('businesses', [
        'id' => $party->id,
        'legal_name' => 'Acme Hosting Pty Ltd',
        'trading_name' => 'Acme Hosting',
        'tax_number' => '12 345 678 901',
    ]);

    $this->assertDatabaseHas('party_relationships', [
        'party_id' => $party->id,
        'relationship_type' => 'supplier',
        'is_active' => true,
    ]);
});

it('creates a person party', function (): void {
    $party = $this->service->createPerson([
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'title' => 'Ms',
        'email' => 'jane@example.com',
    ], relationships: ['supplier']);

    expect($party)->toBeInstanceOf(Party::class)
        ->and($party->party_type)->toBe('person');

    $this->assertDatabaseHas('parties', [
        'id' => $party->id,
        'party_type' => 'person',
    ]);

    $this->assertDatabaseHas('persons', [
        'id' => $party->id,
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'title' => 'Ms',
        'email' => 'jane@example.com',
    ]);

    $this->assertDatabaseHas('party_relationships', [
        'party_id' => $party->id,
        'relationship_type' => 'supplier',
    ]);
});

it('prevents creating a party without a child record', function (): void {
    // Omitting the required legal_name causes Business insert to fail,
    // which must roll back the Party row too.
    expect(fn () => $this->service->createBusiness([
        'business_type' => 'company',
        // legal_name intentionally omitted — NOT NULL constraint
    ]))->toThrow(QueryException::class);

    expect(Party::count())->toBe(0);
    expect(Business::count())->toBe(0);
});

it('allows a party to have multiple relationships', function (): void {
    $party = $this->service->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Multi-Role Corp',
    ], relationships: ['supplier', 'landlord']);

    $relationshipTypes = PartyRelationship::where('party_id', $party->id)
        ->pluck('relationship_type')
        ->sort()
        ->values()
        ->all();

    expect($relationshipTypes)->toBe(['landlord', 'supplier']);
});
