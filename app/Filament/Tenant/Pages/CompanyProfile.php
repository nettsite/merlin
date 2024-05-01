<?php

namespace App\Filament\Tenant\Pages;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Tenancy\EditTenantProfile;
 
class CompanyProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Company Profile';
    }
 
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name'),
                // ...
            ]);
    }
}
