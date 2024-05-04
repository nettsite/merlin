<?php

namespace App\Filament\Tenant\Clusters\Billing\Resources\ReceiptResource\Pages;

use App\Filament\Tenant\Clusters\Billing\Resources\ReceiptResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateReceipt extends CreateRecord
{
    protected static string $resource = ReceiptResource::class;
}
