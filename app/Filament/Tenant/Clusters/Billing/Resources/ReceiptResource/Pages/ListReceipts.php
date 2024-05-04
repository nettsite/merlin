<?php

namespace App\Filament\Tenant\Clusters\Billing\Resources\ReceiptResource\Pages;

use App\Filament\Tenant\Clusters\Billing\Resources\ReceiptResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListReceipts extends ListRecords
{
    protected static string $resource = ReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
