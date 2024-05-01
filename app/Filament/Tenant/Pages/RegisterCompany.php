<?php

namespace App\Filament\Tenant\Pages;

use App\Models\Company;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Tenancy\RegisterTenant;
 
class RegisterCompany extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Register Company';
    }
 
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name'),
                // ...
            ]);
    }
 
    protected function handleRegistration(array $data): Company
    {
        $Company = Company::create($data);
 
        $Company->members()->attach(auth()->user());
 
        return $Company;
    }
}