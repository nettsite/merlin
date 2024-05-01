<?php

namespace App\Filament\Tenant\Clusters\Billing\Resources\DocumentResource\Pages;

use App\Filament\Tenant\Clusters\Billing\Resources\DocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;
}
