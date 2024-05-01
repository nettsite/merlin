<?php

namespace App\Filament\Tenant\Clusters\Payroll\Resources\EmployeeResource\Pages;

use App\Filament\Tenant\Clusters\Payroll\Resources\EmployeeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
