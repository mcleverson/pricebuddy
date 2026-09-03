<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AgentStrategyResource\Pages;
use App\Models\AgentStrategy;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AgentStrategyResource extends Resource
{
    protected static ?string $model = AgentStrategy::class;

    protected static ?string $navigationLabel = 'Agent Strategy';

    protected static ?int $navigationSort = 65;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Basics')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Select::make('store_id')
                            ->label('Store')
                            ->relationship('store', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),

                        Forms\Components\Select::make('tags')
                            ->label('Niche (Tags)')
                            ->relationship('tags', 'name')
                            ->multiple()
                            ->required()
                            ->preload()
                            ->searchable(),
                    ])
                    ->columns(1),

                Forms\Components\Section::make('Limits')
                    ->schema([
                        Forms\Components\TextInput::make('max_products')
                            ->label('Maximum products')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->default(10)
                            ->required(),

                        Forms\Components\TextInput::make('min_discount_percentage')
                            ->label('Minimum discount (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(20)
                            ->suffix('%')
                            ->required(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Visit URLs')
                    ->description('URLs the agent should visit, in priority order')
                    ->schema([
                        Forms\Components\Repeater::make('urls')
                            ->hiddenLabel()
                            ->schema([
                                Forms\Components\TextInput::make('url')
                                    ->label('URL')
                                    ->url()
                                    ->required()
                                    ->maxLength(2048),
                            ])
                            ->reorderable()
                            ->collapsible()
                            ->minItems(1)
                            ->itemLabel(fn (array $state): ?string => $state['url'] ?? null)
                            ->required(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\TextColumn::make('name')
                        ->searchable()
                        ->weight(FontWeight::Bold)
                        ->sortable(),
                    Tables\Columns\TextColumn::make('store.name')
                        ->sortable(),
                    Tables\Columns\TextColumn::make('max_products')
                        ->label('Max products')
                        ->sortable(),
                    Tables\Columns\TextColumn::make('min_discount_percentage')
                        ->label('Min discount')
                        ->suffix('%')
                        ->sortable(),
                    Tables\Columns\TextColumn::make('tags.name')
                        ->label('Niche')
                        ->badge()
                        ->wrap()
                        ->searchable(),
                ])->from('sm'),
            ])
            ->defaultSort('name')
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
            ])
            ->modifyQueryUsing(function (Builder $query) {
                $query->currentUser()->with(['store', 'tags']);
            });
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
            'index' => Pages\ListAgentStrategies::route('/'),
            'create' => Pages\CreateAgentStrategy::route('/create'),
            'edit' => Pages\EditAgentStrategy::route('/{record}/edit'),
        ];
    }
}
