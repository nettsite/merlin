<?php

namespace App\Filament\Tenant\Clusters\Billing\Resources\DocumentResource\Pages;

use App\Filament\Tenant\Clusters\Billing\Resources\DocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
