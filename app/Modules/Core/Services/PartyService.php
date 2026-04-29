<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Business;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\Person;
use Illuminate\Support\Facades\DB;

class PartyService
{
    /** Fields that belong on the Party row rather than the child table. */
    private const PARTY_FIELDS = ['status', 'primary_email', 'primary_phone', 'notes'];

    /**
     * Create a Business party, optionally assigning one or more relationship types.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $relationships
     */
    public function createBusiness(array $data, array $relationships = []): Party
    {
        return DB::transaction(function () use ($data, $relationships): Party {
            $partyData = array_intersect_key($data, array_flip(self::PARTY_FIELDS));
            $businessData = array_diff_key($data, array_flip(self::PARTY_FIELDS));

            $party = Party::create(array_merge($partyData, ['party_type' => 'business']));

            // forceFill used because 'id' is the PK and excluded from mass assignment
            (new Business($businessData))->forceFill(['id' => $party->id])->save();

            foreach ($relationships as $type) {
                $party->addRelationship($type);
            }

            return $party->load(['business', 'relationships']);
        });
    }

    /**
     * Create a pending business party from LLM extraction — awaiting user confirmation.
     *
     * @param  array<string, mixed>  $data
     */
    public function createPendingBusiness(array $data): Party
    {
        return $this->createBusiness(
            array_merge($data, ['status' => 'pending']),
            relationships: ['supplier'],
        );
    }

    /**
     * Approve a pending supplier, updating their details and setting status to active.
     *
     * @param  array<string, mixed>  $data  Keys: legal_name, trading_name, tax_number, primary_email, primary_phone
     */
    public function approveSupplier(Party $party, array $data): void
    {
        $party->business?->update([
            'legal_name' => $data['legal_name'],
            'trading_name' => $data['trading_name'] ?: $data['legal_name'],
            'tax_number' => $data['tax_number'],
        ]);

        $party->update([
            'status' => 'active',
            'primary_email' => $data['primary_email'],
            'primary_phone' => $data['primary_phone'],
        ]);
    }

    /**
     * Create a Person party, optionally assigning one or more relationship types.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $relationships
     */
    public function createPerson(array $data, array $relationships = []): Party
    {
        return DB::transaction(function () use ($data, $relationships): Party {
            $partyData = array_intersect_key($data, array_flip(self::PARTY_FIELDS));
            $personData = array_diff_key($data, array_flip(self::PARTY_FIELDS));

            $party = Party::create(array_merge($partyData, ['party_type' => 'person']));

            // forceFill used because 'id' is the PK and excluded from mass assignment
            (new Person($personData))->forceFill(['id' => $party->id])->save();

            foreach ($relationships as $type) {
                $party->addRelationship($type);
            }

            return $party->load(['person', 'relationships']);
        });
    }
}
