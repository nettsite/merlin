<?php

namespace App\Filament\Tenant\Clusters\Billing\Resources\PartyResource\Pages;

use App\Filament\Tenant\Clusters\Billing\Resources\PartyResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateParty extends CreateRecord
{
    protected static string $resource = PartyResource::class;
}
