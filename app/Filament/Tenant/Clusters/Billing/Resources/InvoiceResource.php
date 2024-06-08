<?php

namespace App\Filament\Tenant\Clusters\Billing\Resources;

use App\Filament\Tenant\Clusters\Billing;
use App\Filament\Tenant\Clusters\Billing\Resources\InvoiceResource\Pages;
use App\Filament\Tenant\Clusters\Billing\Resources\InvoiceResource\RelationManagers;
use App\Models\Document;
use App\Models\Invoice;
use Faker\Provider\ar_EG\Text;
use Filament\Tables\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Icetalker\FilamentTableRepeater\Forms\Components\TableRepeater;
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
                Hidden::make('role') // Invoice
                    ->default('I'),

                Hidden::make('status') // Draft
                    ->default('D'),

                TextInput::make('number')
                    ->readOnly()
                    ->default(fn (): string => Document::nextNumber('I')),

                Select::make('party_id')
                    ->label('Client')
                    ->relationship('party', 'full_name')
                    ->preload()
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
                    ->hidden(fn (Get $get): bool => $get('recurring') === false)
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
                    ->hidden(fn (Get $get): bool => $get('recurring') === false)
                    ->required(fn (Get $get): bool => $get('recurring') === true),

                TableRepeater::make('Items')
                    ->relationship('transactions')
                    ->schema([
                        Select::make('product_id')
                            ->label('Description')
                            ->relationship('product', 'name')
                            ->required()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->required(),
                                Forms\Components\TextInput::make('price')
                                    ->numeric()
                                    ->required(),
                            ])
                            ->editOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->required(),
                                Forms\Components\TextInput::make('price')
                                    ->numeric()
                                    ->required(),
                            ]),

                        TextInput::make('unit_price')
                            ->label('Unit Price')
                            ->type('number')
                            ->required(),

                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->type('number')
                            ->required(),

                        Radio::make('discount_type')
                            ->options([
                                1 => 'Percentage',
                                2 => 'Value',
                            ])
                            ->default(0)
                            ->inline(),

                        TextInput::make('discount')
                            ->label('Discount')
                            ->type('number'),

                        TextInput::make('total_amount')
                            ->label('Total')
                            ->readOnly(),
                    ])
                    ->reorderable()
                    ->cloneable()
                    ->collapsible()
                    ->defaultItems(1)
                    ->columnSpan('full')
                    ->inlineLabel(false),

                Fieldset::make('Totals')
                    ->schema([
                        TextInput::make('total_amount')
                            ->mask(RawJs::make('$money($input,\'.\',\',\')'))
                            ->stripCharacters(',')
                            ->numeric()
                            ->readOnly(),

                        TextInput::make('total_discount')
                            ->type('number')
                            ->readOnly(),

                        TextInput::make('net_amount')
                            ->type('number')
                            ->readOnly(),
                    ])
                    ->inlineLabel()
                    ->columns(3)
                    ->columnSpan('full'),
            ])
            ->inlineLabel();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Date')
                    ->date('d-m-Y')
                    ->searchable()
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('due_date')
                    ->date('d-m-Y')
                    ->searchable()
                    ->sortable()
                    ->color(fn (Invoice $record): string => $record->due_date->format('Y-m-d') == date('Y-m-d') ? 'warning' : ($record->due_date->isPast() ? 'danger' : 'success'))
                    ->alignCenter(),

                TextColumn::make('party.full_name')
                    ->label('Client')
                    ->searchable(),

                TextColumn::make('total_amount')
                    ->numeric(decimalPlaces: 2)
                    ->alignRight(),

                TextColumn::make('total_discount')
                    ->numeric(decimalPlaces: 2)
                    ->alignRight(),

                TextColumn::make('net_amount')
                    ->numeric(decimalPlaces: 2)
                    ->alignRight(),

                TextColumn::make('status')
                    ->formatStateUsing(
                        fn (Invoice $record) => match ($record->status) {
                            'D' => 'Draft',
                            'S' => 'Sent',
                            'C' => 'Cancelled',
                            'P' => 'Paid',
                            default => $record->status,
                        }
                    )
                    ->color(fn (Invoice $record): string => match ($record->status) {
                        'D' => 'warning',
                        'S' => 'primary',
                        'C' => 'danger',
                        'P' => 'success',
                        default => 'dark',
                    })
                    ->sortable(),

            ])

            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'D' => 'Draft',
                        'S' => 'Sent',
                        'C' => 'Cancelled',
                        'P' => 'Paid',
                    ]),
            ]) //, layout: FiltersLayout::AboveContent)

            ->actions([
                Tables\Actions\EditAction::make(),
                Action::make('Send')
                    ->action(fn (Invoice $record) => $record->sendDocument())
                    ->visible(fn (Invoice $record) => $record->status === 'D'),
                Action::make('Send')
                    ->requiresConfirmation()
                    ->modalHeading('Resend Invoice')
                    ->modalDescription('Are you sure you\'d like to resend this invoice?')
                    ->action(fn (Invoice $record) => $record->sendDocument())
                    ->visible(fn (Invoice $record) => $record->status === 'S'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->striped();
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
