<?php

namespace App\Models;

use App\Casts\StoreScraperStrategySetCast;
use App\Dto\StoreScraperStrategySetDto;
use App\Enums\AccessMode;
use App\Enums\ScraperService;
use App\Services\Helpers\CurrencyHelper;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * @property string $name
 * @property ?string $marketplace_id
 * @property ?AccessMode $access_mode
 * @property string $initials
 * @property array $domains
 * @property HtmlString $domains_html
 * @property StoreScraperStrategySetDto $scrape_strategy
 * @property array $settings
 * @property string $scraper_service
 * @property array $scraper_options
 * @property bool $ai_extraction_enabled
 * @property string|null $ai_provider_id
 * @property bool $ai_self_healing_disabled
 * @property string $locale
 * @property string $currency
 * @property Collection $urls
 * @property Collection $products
 * @property ?User $user
 * @property ?string $cookies
 */
class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory;

    use HasSlug;

    protected $fillable = [
        'name',
        'marketplace_id',
        'access_mode',
        'initials',
        'domains',
        'scrape_strategy',
        'settings',
        'notes',
        'user_id',
        'cookies',
        'agent_urls',
        'agent_max_products',
        'agent_min_discount_percentage',
        'discovery_min_percentage_per_tag',
    ];

    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'scrape_strategy' => StoreScraperStrategySetCast::class,
            'settings' => 'array',
            'access_mode' => AccessMode::class,
            'agent_urls' => 'array',
            'agent_max_products' => 'integer',
            'agent_min_discount_percentage' => 'decimal:2',
            'discovery_min_percentage_per_tag' => 'integer',
        ];
    }

    public static function booted()
    {
        static::deleted(function (Store $store) {
            // Get all products before deleting URLs (since products() relationship uses URLs).
            $products = Product::whereIn('id', function ($query) use ($store) {
                $query->select('product_id')
                    ->from('urls')
                    ->where('store_id', $store->id);
            })->get();

            // Delete URLs individually to trigger model events (which cascade delete prices).
            $store->urls->each->delete();

            // Update price cache for all affected products.
            $products->each(function (Product $product): void {
                $product->updatePriceCache();
                $product->updateInsightsCache();
            });
        });
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    /***************************************************
     * Relationships.
     **************************************************/

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function urls(): HasMany
    {
        return $this->hasMany(Url::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'store_tag')
            ->orderBy('name')
            ->withTimestamps();
    }

    public function products(): HasManyThrough
    {
        return $this->hasManyThrough(
            Product::class,
            Url::class,
            'store_id',
            'id',
            'id',
            'product_id'
        );
    }

    /***************************************************
     * Scopes.
     **************************************************/

    public function scopeDomainFilter(Builder $query, string|array|null $domains): Builder
    {
        $domains = array_values(array_filter(
            Arr::wrap($domains),
            fn ($domain) => filled($domain)
        ));

        if ($domains === []) {
            return $query->whereRaw('1 = 0');
        }

        $first = array_shift($domains);

        return $query->where(function (Builder $subQuery) use ($first, $domains) {
            $subQuery->whereJsonContains('domains', ['domain' => $first]);

            foreach ($domains as $domain) {
                $subQuery->orWhereJsonContains('domains', ['domain' => $domain]);
            }
        });
    }

    /***************************************************
     * Attributes.
     **************************************************/

    public function initials(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (! empty($value)) {
                    return strtoupper($value);
                }

                $parts = explode(' ', Str::slug($this->name, ' '));
                $initials = count($parts) > 1
                    ? collect(explode(' ', Str::slug($this->name, ' ')))
                        ->map(fn ($part) => Str::substr($part, 0, 1))
                        ->take(2)->join('')
                    : Str::substr($this->name, 0, 2);

                return strtoupper($initials);
            }
        );
    }

    public function domainsHtml(): Attribute
    {
        return Attribute::make(
            get: fn () => new HtmlString(Str::limit(collect($this->domains)
                ->pluck('domain')
                ->join(', ')))
        );
    }

    public function scraperService(): Attribute
    {
        return Attribute::make(
            get: function () {
                return data_get($this->settings, 'scraper_service', ScraperService::Http->value);
            }
        );
    }

    public function aiExtractionEnabled(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => (bool) data_get($this->settings, 'ai_extraction_enabled', false),
        );
    }

    public function aiProviderId(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => data_get($this->settings, 'ai_provider_id'),
        );
    }

    public function aiSelfHealingDisabled(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => (bool) data_get($this->settings, 'ai_self_healing_disabled', false),
        );
    }

    public function scraperOptions(): Attribute
    {
        return Attribute::make(
            get: function () {
                $options = collect(explode(PHP_EOL, data_get($this->settings, 'scraper_service_settings', '')))
                    ->filter(fn ($option) => ! empty($option) && Str::contains($option, '='))
                    ->mapWithKeys(function ($option) {
                        $parts = explode('=', $option);

                        return [data_get($parts, 0) => data_get($parts, 1)];
                    })
                    ->toArray();

                $sleep = data_get($this->settings, 'scraper_sleep');
                if ($sleep !== null && $sleep !== '') {
                    $options['sleep'] = (string) $sleep;
                }

                $waitUntil = data_get($this->settings, 'scraper_wait_until');
                if (filled($waitUntil)) {
                    $options['wait-until'] = $waitUntil;
                }

                $proxyMode = data_get($this->settings, 'proxy_mode');
                if (filled($proxyMode)) {
                    $options['proxy_mode'] = $proxyMode;
                }

                return $options;
            }
        );
    }

    public function testUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => data_get($this->settings, 'test_url', ''),
        );
    }

    public function locale(): Attribute
    {
        return Attribute::make(
            get: fn () => data_get($this->settings, 'locale_settings.locale', CurrencyHelper::getLocale()),
        );
    }

    public function currency(): Attribute
    {
        return Attribute::make(
            get: fn () => data_get($this->settings, 'locale_settings.currency', CurrencyHelper::getCurrency()),
        );
    }

    /***************************************************
     * Helpers.
     **************************************************/

    public function hasDomain($domain): bool
    {
        return collect($this->domains)
            ->pluck('domain')
            ->contains($domain);
    }

    /**
     * Get allowed hosts from the agent visit URLs and this store's own domains,
     * for use as the Hermes agentic discovery allow-list.
     *
     * @return array<int, string>
     */
    public function allowedHosts(): array
    {
        $hosts = [];

        foreach ((array) $this->agent_urls as $url) {
            $url = is_array($url) ? ($url['url'] ?? null) : $url;
            if (! is_string($url) || $url === '') {
                continue;
            }

            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        foreach ((array) $this->domains as $domain) {
            if (is_string($domain) && $domain !== '') {
                $hosts[] = strtolower($domain);
            } elseif (is_array($domain) && isset($domain['domain'])) {
                $hosts[] = strtolower((string) $domain['domain']);
            }
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    public function getAiHealFailedAt(): ?Carbon
    {
        $value = data_get($this->settings, 'ai_heal_failed_at');

        return filled($value) ? Carbon::parse($value) : null;
    }

    public function markAiHealFailed(): void
    {
        $this->settings = array_merge($this->settings ?? [], [
            'ai_heal_failed_at' => now()->toIso8601String(),
        ]);
        $this->save();
    }

    public function clearAiHealFailed(): void
    {
        $settings = $this->settings ?? [];
        unset($settings['ai_heal_failed_at']);
        $this->settings = $settings;
        $this->save();
    }
}
