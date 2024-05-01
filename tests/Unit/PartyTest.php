<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Models\Party;
use App\Support\Address;
use Illuminate\Database\Eloquent\Casts\Attribute;

class PartyTest extends TestCase
{
    public function testFullAddress()
    {
        $party = new Party([
            'address' => '123 Main St',
            'city' => 'New York',
            'province' => 'NY',
            'country_code' => 'US',
            'postal_code' => '10001',
        ]);

        $fullAddressAttribute = $party->full_address();

        $this->assertInstanceOf(Attribute::class, $fullAddressAttribute);

        $expectedAddress = new Address(
            [
                '123 Main St',
                'New York',
                'NY',
                'US',
                '10001',
            ]
        );

        $this->assertEquals($expectedAddress, $fullAddressAttribute->get());
    }
}
