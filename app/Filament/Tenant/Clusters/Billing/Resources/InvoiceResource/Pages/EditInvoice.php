<?php

namespace App\Filament\Tenant\Clusters\Billing\Resources\InvoiceResource\Pages;

use App\Filament\Tenant\Clusters\Billing\Resources\InvoiceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
