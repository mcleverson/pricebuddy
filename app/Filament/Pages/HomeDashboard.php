<?php

namespace App\Filament\Pages;

use App\Console\Commands\RunAgentStrategy;
use App\Filament\Resources\ProductResource\Actions\CreateAction;
use App\Filament\Widgets\ProductStats;
use App\Services\Dashboard\DashboardLayoutService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Facades\FilamentIcon;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Contracts\Support\Htmlable;

class HomeDashboard extends Page
{
    protected static string $routePath = '/';

    /**
     * Human labels for the toggleable dashboard sections.
     *
     * @var array<string, string>
     */
    private const SECTION_LABELS = [
        'stat_bar' => 'Summary stats',
        'buy_now' => "What's good to buy now",
        'recently_dropped' => 'Recently dropped',
        'needs_attention' => 'Needs attention',
    ];

    protected static ?int $navigationSort = -2;

    /**
     * @var view-string
     */
    protected static string $view = 'filament-panels::pages.dashboard';

    public static function getNavigationLabel(): string
    {
        return static::$navigationLabel ??
            static::$title ??
            __('filament-panels::pages/dashboard.title');
    }

    public static function getNavigationIcon(): string|Htmlable|null
    {
        return static::$navigationIcon
            ?? FilamentIcon::resolve('panels::pages.dashboard.navigation-item')
            ?? (Filament::hasTopNavigation() ? 'heroicon-m-home' : 'heroicon-o-home');
    }

    public static function getRoutePath(): string
    {
        return static::$routePath;
    }

    /**
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            ProductStats::class,
        ];
    }

    /**
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getVisibleWidgets(): array
    {
        return $this->filterVisibleWidgets($this->getWidgets());
    }

    /**
     * @return int | string | array<string, int | string | null>
     */
    public function getColumns(): int|string|array
    {
        return 1;
    }

    public function getTitle(): string|Htmlable
    {
        return static::$title ?? __('filament-panels::pages/dashboard.title');
    }

    public function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            $this->runStrategiesAction(),
            $this->customizeAction(),
        ];
    }

    /**
     * Product discovery of the chosen agentic/api stores, in the background;
     * the bell notifies when each store starts and when the run ends.
     */
    protected function runStrategiesAction(): Action
    {
        return Action::make('runStrategies')
            ->label(__('Run strategies'))
            ->icon('heroicon-o-play')
            ->color('gray')
            ->disabled(fn (): bool => RunAgentStrategy::isRunning())
            ->tooltip(fn (): ?string => RunAgentStrategy::isRunning() ? __('Discovery in progress') : null)
            ->modalHeading(__('Run strategies'))
            ->modalDescription(__('Product discovery runs in the background, one store after the other. You will be notified when each store starts and when all finish.'))
            ->modalSubmitActionLabel(__('Run'))
            ->fillForm(fn (): array => ['stores' => RunAgentStrategy::eligibleStores()->pluck('id')->all()])
            ->form([
                CheckboxList::make('stores')
                    ->label(__('Stores'))
                    ->options(fn (): array => RunAgentStrategy::eligibleStores()
                        ->mapWithKeys(fn ($store): array => [$store->id => "{$store->name} ({$store->access_mode->value})"])
                        ->all())
                    ->bulkToggleable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                if (RunAgentStrategy::isRunning()) {
                    Notification::make()->title(__('Discovery in progress'))->warning()->send();

                    return;
                }

                RunAgentStrategy::startInBackground($data['stores'], auth()->user());

                Notification::make()->title(__('Discovery started'))->body(trans_choice(':count store|:count stores', count($data['stores'])))->success()->send();
            });
    }

    protected function customizeAction(): Action
    {
        return Action::make('customize')
            ->label(__('Customize'))
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('gray')
            ->modalHeading(__('Customize dashboard'))
            ->modalSubmitActionLabel(__('Save'))
            ->fillForm(fn (): array => $this->currentSectionVisibility())
            ->form(
                array_map(
                    fn (string $key): Toggle => Toggle::make($key)->label(__(self::SECTION_LABELS[$key])),
                    DashboardLayoutService::SECTION_KEYS,
                )
            )
            ->action(function (array $data): void {
                $layout = new DashboardLayoutService(auth()->user());

                foreach (DashboardLayoutService::SECTION_KEYS as $key) {
                    $layout->setSectionVisible($key, (bool) ($data[$key] ?? false));
                }

                $this->dispatch('dashboard-sections-updated');
            });
    }

    /**
     * @return array<string, bool>
     */
    protected function currentSectionVisibility(): array
    {
        $layout = new DashboardLayoutService(auth()->user());

        $visibility = [];
        foreach (DashboardLayoutService::SECTION_KEYS as $key) {
            $visibility[$key] = $layout->isSectionVisible($key);
        }

        return $visibility;
    }
}
