<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant;
use App\Filament\Tenant\Resources\PartyResource\Pages;
use App\Filament\Tenant\Resources\PartyResource\RelationManagers;
use App\Models\Party;
use App\Settings\PartySettings;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PartyResource extends Resource
{
    protected static ?string $model = Party::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?int $navigationSort = 30;
    protected static bool $shouldRegisterNavigation = false;

    public static function form(Form $form): Form
    {
        $settings = new PartySettings();
        // dd($settings);
        // dd($settings->types);
        // $table->string('name', 64);
        // $table->string('email', 64);
        // $table->string('phone', 16)->nullable();
        // $table->string('address', 128)->nullable();
        // $table->string('city', 16)->nullable();
        // $table->string('province', 16)->nullable();
        // $table->string('country_code', 2)->default('ZA');
        // $table->string('postal_code', 4)->nullable();
        // $table->tinyInteger('type')->default(0);
        // $table->string('tax_number', 16)->nullable();
        // $table->string('registration_number', 16)->nullable();
        return $form
            ->schema([
                TextInput::make('name')
                    ->required(),
                TextInput::make('email')
                    ->email()
                    ->required(),
                TextInput::make('phone'),
                Textarea::make('address'),
                TextInput::make('city'),
                TextInput::make('province'),
                Select::make('country_code')
                    ->label('Country')
                    ->options($settings->countries)
                    ->default('ZA'),
                TextInput::make('postal_code'),
                Select::make('type')
                    ->options($settings->types)
                    ->default(0),
                TextInput::make('tax_number'),
                TextInput::make('registration_number'),
                
                Select::make('contacts')
                    ->options(fn () => Party::all()->pluck('name', 'id'))
                    ->multiple()
                    ->relationship('contacts', 'name')
                    ->createOptionForm([
                        TextInput::make('name')
                            ->required(),
                        TextInput::make('surname'),
                        TextInput::make('email')
                            ->required()
                            ->email(),
                        TextInput::make('phone'),
                    ]),

            ])->columns(4);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListParties::route('/'),
            'create' => Pages\CreateParty::route('/create'),
            'edit' => Pages\EditParty::route('/{record}/edit'),
        ];
    }
}
