<?php

namespace App\Filament\Tenant\Clusters\Billing\Resources\ClientResource\Pages;

use App\Filament\Tenant\Clusters\Billing\Resources\ClientResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;
}
