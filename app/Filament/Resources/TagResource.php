<?php

namespace App\Filament\Resources;

use App\Enums\Icons;
use App\Filament\Resources\TagResource\Pages;
use App\Models\Tag;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TagResource extends Resource
{
    public const string API_GROUP = 'Tag';

    protected static ?string $model = Tag::class;

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    public static function getWeightHelperText(): string
    {
        return __('Lower values appear first. This will affect the order of product grouping on the homepage.');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Tag')
                    ->description(__('Used for grouping products'))
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Name')
                            ->required(),
                        Forms\Components\TextInput::make('weight')
                            ->label('Sort order')
                            ->numeric()
                            ->default(0)
                            ->helperText(self::getWeightHelperText()),
                    ]),

                Forms\Components\Section::make('Relevance profile')
                    ->description(__('Guides discovery (Hermes) on which products truly belong to this niche. Not a strict whitelist: the agent also admits new products semantically related to what is described here. Leave empty to keep discovery unfiltered by niche.'))
                    ->schema(self::relevanceProfileFields())
                    ->statePath('relevance_profile')
                    ->columns(2)
                    ->collapsible(),
            ]);
    }

    /**
     * Niche relevance fields, shared with the Store's discovery profile so a
     * strategy can add to (or narrow) its niches with the same vocabulary.
     *
     * @return array<int, \Filament\Forms\Components\Component>
     */
    public static function relevanceProfileFields(): array
    {
        $list = fn (string $name, string $label, string $help): Forms\Components\TagsInput => Forms\Components\TagsInput::make($name)
            ->label($label)
            ->hintIcon(Icons::Help->value, $help)
            ->splitKeys(['Enter', ',']);

        return [
            $list('include_product_types', 'Desired product types', 'e.g. smartphone, air fryer, robot vacuum.'),
            $list('include_brands', 'Priority brands', 'Brands to prioritize. Other brands are still accepted when relevant.'),
            $list('include_products', 'Relevant products / families / models', 'e.g. Galaxy S, iPhone, Redmi Note.'),
            $list('include_terms', 'Synonyms and term variations', 'e.g. celular, telefone, smartphone.'),
            $list('allowed_categories', 'Allowed categories', 'Marketplace categories that fit this niche.'),
            $list('exclude_product_types', 'Excluded product types', 'e.g. case, screen protector, charger.'),
            $list('exclude_brands', 'Excluded brands', 'Brands never admitted.'),
            $list('exclude_terms', 'Excluded terms', 'Titles containing any of these words are rejected before the LLM (whole-word match).'),
            $list('positive_examples', 'Examples of desired products', 'Product titles that illustrate what this niche wants.'),
            $list('negative_examples', 'Examples of undesired products', 'Product titles that illustrate what to reject.'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\TextColumn::make('name')
                        ->weight(FontWeight::Bold)
                        ->searchable()
                        ->sortable(),
                    Tables\Columns\TextColumn::make('products_count')
                        ->label(__('Products'))
                        ->formatStateUsing(fn ($state) => $state.' products')
                        ->color('gray')
                        ->sortable(),
                    Tables\Columns\TextColumn::make('weight')
                        ->label(__('Sort order'))
                        ->color('gray')
                        ->sortable(),
                ])->from('sm'),
            ])
            ->defaultSort('weight')
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
                $query->currentUser()->withCount(['products']);
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
            'index' => Pages\ListTags::route('/'),
            'create' => Pages\CreateTag::route('/create'),
            'edit' => Pages\EditTag::route('/{record}/edit'),
        ];
    }
}
