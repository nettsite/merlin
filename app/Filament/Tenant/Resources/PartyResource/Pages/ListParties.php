<?php

namespace App\Filament\Tenant\Resources\PartyResource\Pages;

use App\Filament\Tenant\Resources\PartyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListParties extends ListRecords
{
    protected static string $resource = PartyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
