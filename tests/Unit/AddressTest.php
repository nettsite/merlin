<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Support\Address;

class AddressTest extends TestCase
{
    public function testAddressConcatenation()
    {
        $addressLines = ['123 Main St', 'Apt 4B', 'New York, NY 10001'];
        $address = new Address($addressLines);

        $expectedAddress = "123 Main St\nApt 4B\nNew York, NY 10001";

        $this->assertEquals($expectedAddress, $address->address);
    }

    public function testAddressLineLengthException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Address line cannot be longer than 64 characters');

        $longAddressLine = str_repeat('a', 65);
        $address = new Address([$longAddressLine]);
    }

    public function testEmptyAddressLine()
    {
        $addressLines = ['123 Main St', '', 'New York, NY 10001'];
        $address = new Address($addressLines);

        $expectedAddress = "123 Main St\nNew York, NY 10001";

        $this->assertEquals($expectedAddress, $address->address);
    }
}