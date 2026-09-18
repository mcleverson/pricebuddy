<?php

namespace App\Filament\Resources;

use App\Contracts\ProductDataProvider;
use App\Enums\AccessMode;
use App\Enums\AiFeature;
use App\Enums\Icons;
use App\Enums\ProxyMode;
use App\Enums\ScraperService;
use App\Enums\ScraperStrategyType;
use App\Enums\StockStatus;
use App\Filament\Concerns\HasScraperTrait;
use App\Filament\Pages\AppSettingsPage;
use App\Filament\Resources\StoreResource\Pages\CreateStore;
use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Filament\Resources\StoreResource\Pages\ListStores;
use App\Models\Store;
use App\Models\Url;
use App\Providers\Filament\AdminPanelProvider;
use App\Rules\StoreUrl;
use App\Services\Helpers\IntegrationHelper;
use App\Services\ProductData\MarketplaceRegistry;
use Filament\Forms;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\View;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class StoreResource extends Resource
{
    use HasScraperTrait;

    public const array DEFAULT_SELECTORS = [
        'title' => 'meta[property=og:title]|content',
        'price' => 'meta[property=og:price:amount]|content',
        'image' => 'meta[property=og:image]|content',
    ];

    public const string API_GROUP = 'Store';

    protected static ?string $model = Store::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Basics')->schema([
                    TextInput::make('name')
                        ->label('Name')
                        ->hintIcon(Icons::Help->value, 'The name of the store')
                        ->required(),
                ])
                    ->columns(1)
                    ->description(__('Stores are shared between all users in :name', ['name' => config('app.name')]))
                    ->live(),

                Section::make('Domains')->schema([
                    Forms\Components\Repeater::make('domains')
                        ->schema([
                            TextInput::make('domain')->label('Domain'),
                        ])->required(),
                ])
                    ->description('What domains does this store apply to'),

                Section::make('Product data access')->schema([
                    Select::make('marketplace_id')
                        ->label('Marketplace')
                        ->options(fn (): array => app(MarketplaceRegistry::class)->options())
                        ->placeholder('Auto-detect from domain')
                        ->live()
                        ->hintIcon(Icons::Help->value, 'Optional explicit marketplace identity used to select an API provider'),
                    Select::make('access_mode')
                        ->label('Collection Mode')
                        ->options(AccessMode::class)
                        ->default(AccessMode::Scraping->value)
                        ->selectablePlaceholder(false)
                        ->live()
                        ->hintIcon(Icons::Help->value, 'Api requires a configured provider for this marketplace; Scraping visits product pages directly; Agentic autonomously discovers new products via Hermes'),
                ])->columns(2),

                Section::make('Api credentials')
                    ->description('Credentials this marketplace\'s API needs.')
                    ->schema(fn (Get $get): array => self::apiCredentialFields($get('marketplace_id')))
                    ->statePath('settings.api_credentials')
                    ->columns(2)
                    ->visible(fn (Get $get): bool => $get('access_mode') === AccessMode::Api->value),

                Section::make('Proxy')
                    ->description('Proxy settings used when scraping this store. Most major marketplaces block unproxied scraping.')
                    ->schema(self::proxyFormFields())
                    ->columns(2)
                    ->visible(fn (Get $get): bool => $get('access_mode') === AccessMode::Scraping->value),

                Section::make('Discovery')
                    ->description('Finds new products matching this niche. Agentic browses the URLs below with Hermes; Api queries the marketplace directly, when it supports discovery.')
                    ->schema(self::agenticFormFields())
                    ->columns(2)
                    ->visible(fn (Get $get): bool => in_array($get('access_mode'), [AccessMode::Agentic->value, AccessMode::Api->value], true)),

                Forms\Components\Group::make([
                    Section::make('Title strategy')->schema([
                        Forms\Components\Group::make(self::makeStrategyInput('title', self::DEFAULT_SELECTORS['title'], required: self::isScrapingMode()))->columns(2),
                    ])->description('How to get the product title'),
                    Section::make('Original price strategy')->schema([
                        Forms\Components\Group::make(self::makeStrategyInput('original_price', required: false))->columns(2),
                    ])->description('How to get the original product price'),
                    Section::make('Price strategy')->schema([
                        Forms\Components\Group::make(self::makeStrategyInput('price', self::DEFAULT_SELECTORS['price'], required: self::isScrapingMode()))->columns(2),
                    ])->description('How to get the product price'),
                    Section::make('Image strategy')->schema([
                        Forms\Components\Group::make(self::makeStrategyInput('image', self::DEFAULT_SELECTORS['image'], required: self::isScrapingMode()))->columns(2),
                    ])->description('How to get the product image'),
                    Section::make('Availability strategy')->schema([
                        Forms\Components\Group::make(self::makeStrategyInput('availability', required: false))->columns(2),
                        Section::make('Match values')
                            ->schema(
                                collect(StockStatus::nonInStockCases())->map(
                                    fn (StockStatus $status) => Forms\Components\Group::make([
                                        Forms\Components\Select::make('availability.match.'.$status->value.'.type')
                                            ->label('Type')
                                            ->options([
                                                'match' => 'Exact match',
                                                'regex' => 'Regex',
                                            ])
                                            ->default('match')
                                            ->afterStateHydrated(fn (Forms\Components\Select $component, ?string $state) => $component->state($state ?? 'match'))
                                            ->required(self::isScrapingMode()),
                                        TextInput::make('availability.match.'.$status->value.'.value')
                                            ->label($status->getLabel())
                                            ->hintIcon($status->getIcon(), 'If the scraped text matches this value, the product will be marked as "'.$status->getLabel().'"'),
                                    ])->columns(2)
                                )->toArray()
                            )
                            ->description('Map scraped text values to stock statuses. Order is priority (first match wins).')
                            ->columns(1)
                            ->collapsed(fn (Get $get): bool => empty(array_filter(
                                (array) $get('availability.match'),
                                fn ($entry, $key) => $key !== 'default' && (is_array($entry) ? ($entry['value'] ?? '') !== '' : ($entry !== '' && $entry !== null)),
                                ARRAY_FILTER_USE_BOTH,
                            )))
                            ->hidden(fn (Get $get): bool => $get('availability.type') === ScraperStrategyType::SchemaOrg->value),
                        Forms\Components\Select::make('availability.match.default')
                            ->label('Default status')
                            ->options(StockStatus::class)
                            ->default(StockStatus::InStock->value)
                            ->afterStateHydrated(fn (Forms\Components\Select $component, ?string $state) => $component->state($state ?? StockStatus::InStock->value))
                            ->required(self::isScrapingMode())
                            ->hintIcon(Icons::Help->value, 'The status to use when the scraped text does not match any of the values above')
                            ->hidden(fn (Get $get): bool => $get('availability.type') === ScraperStrategyType::SchemaOrg->value),
                    ])->description('Optional: a selector that matches product availability.')
                        ->collapsed(fn (Get $get): bool => ($get('availability.value') ?? '') === ''),
                ])
                    ->label('Scrape Strategy')
                    ->statePath('scrape_strategy')
                    ->visible(self::isScrapingMode()),

                self::getScraperSettings()
                    ->visible(self::isScrapingMode()),

                Section::make('Locale')
                    ->description(__('Override region and locale settings for this store'))
                    ->columns(2)
                    ->schema(collect(AppSettingsPage::getLocaleFormFields('settings.locale_settings'))
                        ->map(fn ($field) => $field->required(self::isScrapingMode()))
                        ->all())
                    ->visible(self::isScrapingMode()),

                Section::make('Cookies')->schema([
                    TextInput::make('cookies')
                        ->label('Cookies')
                        ->hintIcon(Icons::Help->value, 'Any cookies to include in scrape requests for this store. Format as you would in an HTTP header, e.g. "cookie1=value; cookie2=value"'),
                ])->description('Optional cookies to include in scrape requests for this store'),

                Section::make('Notes')->schema([
                    Forms\Components\RichEditor::make('notes')
                        ->hiddenLabel(true),
                ])->description('Additional notes regarding this store and how to scrape its content'),
            ])
            ->columns(1);
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    protected static function apiCredentialFields(?string $marketplaceId): array
    {
        $providerClass = $marketplaceId !== null
            ? (string) config('product_data.providers.'.$marketplaceId)
            : '';

        if ($providerClass === '' || ! is_a($providerClass, ProductDataProvider::class, true)) {
            return [
                Forms\Components\Placeholder::make('no_api_provider')
                    ->hiddenLabel()
                    ->content($marketplaceId === null
                        ? 'Select a Marketplace above to configure its API credentials.'
                        : 'No API integration is implemented for this marketplace yet.'),
            ];
        }

        $fields = $providerClass::credentialFields();

        return $fields === []
            ? [
                Forms\Components\Placeholder::make('no_api_credentials')
                    ->hiddenLabel()
                    ->content('This marketplace\'s API integration needs no credentials.'),
            ]
            : $fields;
    }

    /**
     * @return \Closure(Get): bool
     */
    protected static function isScrapingMode(): \Closure
    {
        return fn (Get $get): bool => $get('access_mode') === AccessMode::Scraping->value;
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    protected static function proxyFormFields(): array
    {
        return [
            Select::make('settings.proxy_mode')
                ->label('Proxy mode')
                ->options(ProxyMode::class)
                ->placeholder('Use global default ('.(ProxyMode::tryFrom(config('scraping.proxy.mode'))?->name ?? ProxyMode::Disabled->name).')')
                ->hintIcon(Icons::Help->value, 'Required always uses a proxy (and fails if none is available); Prefer uses one when available; Disabled never uses one. Most major marketplaces block unproxied scraping.'),
        ];
    }

    /**
     * Niche/target fields shared by Agentic (Hermes) and Api-driven discovery —
     * both ultimately loop over the same Store.tags to find new candidates,
     * they just differ in how they search (browsing configured URLs vs
     * querying the marketplace's API per niche).
     *
     * @return array<int, \Filament\Forms\Components\Component>
     */
    protected static function discoveryFormFields(\Closure $isRequired): array
    {
        return [
            Select::make('tags')
                ->label('Niche (Tags)')
                ->relationship('tags', 'name')
                ->multiple()
                ->required($isRequired)
                ->preload()
                ->searchable()
                ->columnSpanFull(),

            TextInput::make('agent_max_products')
                ->label('Minimum new products')
                ->helperText('Stop after this many new products are created. Existing products do not count.')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->default(10)
                ->required($isRequired),

            TextInput::make('agent_min_discount_percentage')
                ->label('Minimum discount (%)')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->default(20)
                ->suffix('%')
                ->required($isRequired),

            // Quality floor, on top of the discount floor above. Api providers
            // (e.g. Shopee) read these straight from the marketplace's own
            // sales/rating fields; Hermes has the LLM report the same signals
            // when visibly shown on the page, so both paths honor it.
            TextInput::make('discovery_min_sales')
                ->label('Minimum sales (historical)')
                ->helperText('Skip candidates with fewer historical sales than this. Filters out unproven/low-appeal listings. 0 = no minimum.')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(0),

            TextInput::make('discovery_min_rating')
                ->label('Minimum rating')
                ->helperText('Skip candidates — and, when fanning out by shop, shops — rated below this (0-5 scale). Filters out unqualified sellers. 0 = no minimum.')
                ->numeric()
                ->minValue(0)
                ->maxValue(5)
                ->step(0.1)
                ->default(0),
        ];
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    protected static function agenticFormFields(): array
    {
        $isAgentic = fn (Get $get): bool => $get('access_mode') === AccessMode::Agentic->value;
        $isAgenticOrApi = fn (Get $get): bool => in_array($get('access_mode'), [
            AccessMode::Agentic->value, AccessMode::Api->value,
        ], true);

        return [
            ...self::discoveryFormFields($isAgenticOrApi),

            // Only Agentic (Hermes) browses starting URLs; Api-driven discovery
            // queries the marketplace directly by niche instead.
            Forms\Components\Repeater::make('agent_urls')
                ->label('Visit URLs')
                ->hintIcon(Icons::Help->value, 'URLs the agent should visit, in priority order')
                ->schema([
                    TextInput::make('url')
                        ->label('URL')
                        ->url()
                        ->required()
                        ->maxLength(2048),
                ])
                ->reorderable()
                ->collapsible()
                ->minItems(1)
                ->itemLabel(fn (array $state): ?string => $state['url'] ?? null)
                ->required($isAgentic)
                ->hidden(fn (Get $get): bool => $get('access_mode') !== AccessMode::Agentic->value)
                ->columnSpanFull(),

            // Only meaningful for Api: each niche gets its own API query, so a
            // floor across tags is deterministic (unlike Agentic, where niches
            // share the same browsed pages).
            TextInput::make('discovery_min_percentage_per_tag')
                ->label('Minimum per niche (%)')
                ->helperText('Guarantee at least this share of the target from each niche before filling the rest from any. 0 = no floor (first-come-first-served across niches).')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->default(0)
                ->suffix('%')
                ->hidden(fn (Get $get): bool => $get('access_mode') !== AccessMode::Api->value)
                ->columnSpanFull(),
        ];
    }

    public static function testForm(Form $form, Store $store): Form
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, Url> $shortcutUrls */
        $shortcutUrls = $store->urls()
            ->with('product')
            ->whereHas('product')
            ->latest()
            ->latest('id') // deterministic tiebreaker when created_at values are equal
            ->get()
            ->unique('product_id')
            ->take(5);

        return $form
            ->columns(1)
            ->schema(array_values(array_filter([
                $shortcutUrls->isNotEmpty()
                    ? Actions::make(
                        $shortcutUrls->map(fn (Url $url): FormAction => FormAction::make('product_'.$url->getKey())
                            ->label($url->product->title)
                            ->action(fn (Get $get, EditStore $livewire) => $livewire->runScrape($url->url, $get('test_scraper')))
                        )->all()
                    )->label('Existing products')->key('product_shortcuts')
                    : null,

                TextInput::make('test_url')
                    ->label($shortcutUrls->isNotEmpty() ? 'Or product URL' : 'Product URL')
                    ->hintIcon(Icons::Help->value, 'The URL to scrape')
                    ->placeholder(fn (): ?string => filled($host = data_get($store, 'domains.0.domain'))
                        ? 'https://'.$host.'/example-product'
                        : null)
                    ->default(fn (): string => (string) data_get($store, 'settings.test_url', ''))
                    ->required()
                    ->rules([new StoreUrl])
                    ->suffixAction(
                        FormAction::make('scrape')
                            ->label('Test url scrape')
                            ->icon(Icons::Search->value)
                            ->action(function (Get $get, EditStore $livewire): void {
                                $url = (string) $get('test_url');

                                if (filled($url)) {
                                    $livewire->runScrape($url, $get('test_scraper'));
                                }
                            })
                    ),

                Section::make('Results')
                    ->description('What we could find')
                    ->extraAttributes(['class' => 'mt-4'])
                    ->visible(fn (EditStore $livewire): bool => filled($livewire->testScrapeResult))
                    ->headerActions([
                        FormAction::make('compareWithAi')
                            ->label('Compare with AI')
                            ->icon('heroicon-m-sparkles')
                            ->visible(fn (): bool => IntegrationHelper::isAiEnabled())
                            ->action(fn (EditStore $livewire) => $livewire->compareWithAi()),
                        FormAction::make('healWithAi')
                            ->label('Heal with AI')
                            ->icon('heroicon-m-wrench-screwdriver')
                            ->visible(fn (): bool => IntegrationHelper::isFeatureEnabled(AiFeature::Healing))
                            ->action(fn (EditStore $livewire) => $livewire->previewSelfHeal()),
                    ])
                    ->schema([
                        View::make('filament.resources.store-resource.test-results')
                            ->viewData(fn (EditStore $livewire): array => [
                                'scrape' => $livewire->testScrapeResult,
                                'ai' => $livewire->testAiResult,
                                'record' => $livewire->buildUnsavedStore(),
                            ]),

                        Select::make('test_scraper')
                            ->label('Change scraper')
                            ->options(ScraperService::class)
                            ->selectablePlaceholder(false)
                            ->afterStateHydrated(fn (Select $component, EditStore $livewire) => $component->state(
                                $component->getState()
                                    ?: $livewire->testScraper
                                    ?: $livewire->buildUnsavedStore()->scraper_service
                                    ?: ScraperService::Http->value
                            ))
                            ->live()
                            ->afterStateUpdated(function (EditStore $livewire, ?string $state): void {
                                if (filled($livewire->testUrl) && filled($state)) {
                                    $livewire->runScrape($livewire->testUrl, $state);
                                }
                            }),
                    ]),

                Section::make('AI healing proposal')
                    ->description('Proposed selectors — review, then apply to the form')
                    ->extraAttributes(['class' => 'mt-4'])
                    ->visible(fn (EditStore $livewire): bool => filled($livewire->healPreview))
                    ->headerActions([
                        FormAction::make('applySelfHeal')
                            ->label('Apply to form')
                            ->icon('heroicon-m-check')
                            ->action(fn (EditStore $livewire) => $livewire->applySelfHeal()),
                        FormAction::make('discardSelfHeal')
                            ->label('Discard')
                            ->color('gray')
                            ->action(fn (EditStore $livewire) => $livewire->discardSelfHeal()),
                    ])
                    ->schema([
                        View::make('filament.resources.store-resource.heal-preview')
                            ->viewData(fn (EditStore $livewire): array => ['preview' => $livewire->healPreview]),
                    ]),
            ])));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Split::make([
                    Split::make([
                        TextColumn::make('name')
                            ->searchable()
                            ->sortable()
                            ->weight(FontWeight::Bold)
                            ->description(fn (Store $record): HtmlString => $record->domains_html),
                    ]),
                    TextColumn::make('products_count')
                        ->sortable()
                        ->formatStateUsing(fn (string $state) => $state.' products')
                        ->extraAttributes(['class' => 'min-w-36 md:flex md:justify-end pr-4'])
                        ->grow(false),
                    TextColumn::make('access_mode')
                        ->label('Collection Mode')
                        ->badge()
                        ->sortable()
                        ->extraAttributes(['class' => 'min-w-16'])
                        ->formatStateUsing(fn (AccessMode $state) => strtoupper($state->value))
                        ->color(fn (AccessMode $state): array => $state->getColor())
                        ->grow(false),
                ])->from('sm'),

            ])
            ->paginated(AdminPanelProvider::DEFAULT_PAGINATION)
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('access_mode')
                    ->options(AccessMode::class)
                    ->label('Collection Mode'),
            ])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(function (Builder $query) {
                $query->withCount('products');
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
            'index' => ListStores::route('/'),
            'create' => CreateStore::route('/create'),
            'edit' => EditStore::route('/{record}/edit'),
        ];
    }
}
