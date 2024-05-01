<?php

namespace App\Filament\Tenant\Clusters\Payroll\Resources\EmployeeResource\Pages;

use App\Filament\Tenant\Clusters\Payroll\Resources\EmployeeResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;
}
