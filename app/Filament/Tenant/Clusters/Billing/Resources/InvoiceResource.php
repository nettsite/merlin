<?php

namespace App\Filament\Tenant\Clusters\Billing\Resources;

use App\Filament\Tenant\Clusters\Billing;
use App\Filament\Tenant\Clusters\Billing\Resources\InvoiceResource\Pages;
use App\Filament\Tenant\Clusters\Billing\Resources\InvoiceResource\RelationManagers;
use App\Models\Invoice;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Date;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?int $navigationSort = 10;

    protected static ?string $cluster = Billing::class;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('party_id')
                    ->label('Client')
                    ->relationship('party', 'full_name')
                    ->searchable()
                    ->required(),

                DatePicker::make('due_date')
                    ->closeOnDateSelection()
                    ->default(Date::now()->firstOfMonth()->addMonth()->format('Y-m-d'))
                    ->required(),

                Toggle::make('recurring')
                    ->live(onBlur: true),

                Radio::make('frequency')
                    ->options([
                        // 'D' => 'Daily',
                        // 'W' => 'Weekly',
                        'M' => 'Monthly',
                        'Y' => 'Yearly',
                    ])
                    ->inline()
                    ->required(fn (Get $get): bool => $get('recurring') === true)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state) {
                        if (!empty($old)) {
                            return;
                        }
                        $now = Date::now();
                        switch ($state) {
                            case 'M':
                                $set('next_date', $now->addMonth()->format('Y-m-d'));
                                break;
                            case 'Y':
                                $set('next_date', $now->addYear()->format('Y-m-d'));
                            default:
                            $set('next_date', $now->format('Y-m-d'));
                        }
                    }),

                DatePicker::make('next_date')
                    ->closeOnDateSelection()
                    ->required(fn (Get $get): bool => $get('recurring') === true),
            ])
            ->inlineLabel();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //
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
            'index' => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }
}
