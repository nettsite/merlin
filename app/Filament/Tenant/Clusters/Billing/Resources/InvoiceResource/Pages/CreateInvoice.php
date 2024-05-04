<?php

namespace App\Filament\Tenant\Clusters\Billing\Resources\InvoiceResource\Pages;

use App\Filament\Tenant\Clusters\Billing\Resources\InvoiceResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;
}
