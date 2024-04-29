<?php

namespace App\Filament\Landlord\Resources\TenantResource\Pages;

use App\Filament\Landlord\Resources\TenantResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;
}
