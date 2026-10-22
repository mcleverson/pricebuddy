<?php

namespace App\Filament\Resources\CarouselResource\Pages;

use App\Filament\Resources\CarouselResource;
use App\Jobs\DiscoverCarouselProductsJob;
use App\Models\Carousel;
use App\Models\Tag;
use App\Services\Carousels\CarouselDiscovery;
use App\Services\Carousels\CarouselSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListCarousels extends ListRecords
{
    protected static string $resource = CarouselResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newCarousel')
                ->label('New carousel')
                ->icon('heroicon-m-plus')
                ->modalDescription(fn (): string => ($stores = app(CarouselDiscovery::class)->stores()->pluck('name')->all())
                    ? 'Products are searched by the niche text in: '.implode(', ', $stores).'. Discounts do not matter.'
                    : 'No store supports carousel discovery. Check Settings > Carousels and the stores.')
                ->form([
                    TextInput::make('niche')
                        ->required()
                        ->maxLength(255)
                        ->datalist(fn (): array => self::nicheSuggestions())
                        ->placeholder('e.g. Cantinho do café')
                        ->helperText('Also the search term sent to the marketplaces.')
                        ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                            if (! CarouselSettings::get('custom_niche_enabled') && ! in_array(trim((string) $value), self::nicheSuggestions(), true)) {
                                $fail('Choose one of the suggested niches.');
                            }
                        }),
                ])
                ->action(function (array $data) {
                    $settings = CarouselSettings::all();
                    $carousel = Carousel::create([
                        'user_id' => auth()->id(),
                        'niche' => trim($data['niche']),
                        'headline' => trim($data['niche']),
                        'language' => $settings['language'],
                        'region' => $settings['region'],
                        'status' => Carousel::STATUS_DISCOVERING,
                    ]);
                    DiscoverCarouselProductsJob::dispatch($carousel->getKey());
                    Notification::make()->title('Searching the stores in the background')->success()->send();

                    return redirect(CarouselResource::getUrl('view', ['record' => $carousel]));
                }),
        ];
    }

    /**
     * Configured suggestions and the user's own tags (Pinterest Trends is not used).
     *
     * @return list<string>
     */
    public static function nicheSuggestions(): array
    {
        $settings = CarouselSettings::all();
        if (! $settings['suggestions_enabled']) {
            return [];
        }

        $tags = Tag::query()->where('user_id', auth()->id())->orderBy('name')->pluck('name')->all();

        return array_values(array_unique([...$settings['suggested_niches'], ...$tags]));
    }
}
