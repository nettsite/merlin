<?php

namespace App\Filament\Tenant\Clusters\Settings\Resources\CompanyResource\Pages;

use App\Filament\Tenant\Clusters\Settings\Resources\CompanyResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;
}
