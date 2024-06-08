<?php

namespace App\Filament\Tenant\Clusters\Billing\Resources\ClientResource\Pages;

use App\Filament\Tenant\Clusters\Billing\Resources\ClientResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListClients extends ListRecords
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
