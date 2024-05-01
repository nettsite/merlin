<?php

namespace App\Filament\Tenant\Clusters\Settings\Resources\CompanyResource\Pages;

use App\Filament\Tenant\Clusters\Settings\Resources\CompanyResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
