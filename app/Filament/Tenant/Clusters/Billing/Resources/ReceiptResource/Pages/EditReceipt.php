<?php

namespace App\Filament\Tenant\Clusters\Billing\Resources\ReceiptResource\Pages;

use App\Filament\Tenant\Clusters\Billing\Resources\ReceiptResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditReceipt extends EditRecord
{
    protected static string $resource = ReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
