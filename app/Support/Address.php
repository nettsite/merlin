<?php

namespace App\Support;

class Address
{
    public string $address = '';

    public function __construct(
        public array $address_lines
    ) {
        foreach ($this->address_lines as $address_line) {
            if (strlen($address_line) > 64) {
                throw new \InvalidArgumentException('Address line cannot be longer than 64 characters');
            }
            if(empty($address_line)) {
                continue;
            }
            $this->address .= $address_line . "\n";
        }
        $this->address = substr($this->address, 0, -1);
    }
}
