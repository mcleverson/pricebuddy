<?php

namespace App\Filament\Pages;

use App\Enums\NotificationMethods;
use App\Exceptions\AiProviderException;
use App\Models\Product;
use App\Models\Tag;
use App\Services\Helpers\AffiliateHelper;
use App\Services\Helpers\NotificationsHelper;
use App\Services\Intelligence\OfferMessage;
use App\Services\Intelligence\PublicationStatus;
use App\Settings\AppSettings;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * "Products to post today" and "My Product Selection" from
 * pricebuddy-intelligence. Nothing is stored in PriceBuddy: this page only
 * reads and triggers the service's /v1 API.
 */
class IntelligencePage extends Page
{
    /** Not worth posting; hidden from the list unless "show all" is on. */
    public const HIDDEN_ACTIONS = ['monitor', 'ignore'];

    protected static ?string $navigationIcon = 'heroicon-o-light-bulb';

    protected static ?string $navigationLabel = 'Intelligence';

    protected static ?string $title = 'Intelligence';

    protected static ?string $slug = 'intelligence';

    protected static ?int $navigationSort = 25;

    protected static string $view = 'filament.pages.intelligence';

    /** @var array<int, array<string, mixed>> */
    public array $items = [];

    /** @var array<int, array<string, mixed>> */
    public array $publications = [];

    /** @var array<string, mixed> */
    public array $meta = [];

    /** Show monitor/ignore too; by default only what is worth posting or needs confirmation. */
    public bool $showAll = false;

    /** @var array<string, mixed> */
    public array $lastRun = [];

    /** today | selection | publications; "Analyze now" on the selection tab analyzes only the selection. */
    public string $tab = 'today';

    /** Latest analysis of each selected product, plus the selected ones never analyzed (action "pending"). @var array<int, array<string, mixed>> */
    public array $selection = [];

    /** Same filters as the Products list, applied to both product tabs. @var array<string, mixed> */
    public array $filters = [
        'tag' => null, 'min_discount' => null, 'publication' => null, 'from' => null,
        'until' => null, 'min_price' => null, 'max_price' => null, 'text' => null,
    ];

    public ?string $error = null;

    /** Publish modal: the product being published, what to create and the editable message. */
    public ?int $composeProductId = null;

    public ?string $composeKind = null;

    public string $composeMessage = '';

    #[Locked]
    public ?string $composeImage = null;

    public ?string $composeScheduleAt = null;

    /** Ticked once the message was sent by hand to WhatsApp and recorded as published. */
    public bool $composeSent = false;

    public function mount(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $this->error = null;
        $today = $this->call('get', '/v1/today') ?? [];
        $this->items = $today['data'] ?? [];
        $this->meta = $today['meta'] ?? [];
        $this->publications = $this->call('get', '/v1/publications')['data'] ?? [];
        $this->lastRun = $this->call('get', '/v1/runs/latest')['data'] ?? [];
        $selection = $this->call('get', '/v1/selection') ?? [];
        $this->selection = [...($selection['data'] ?? []), ...$this->pendingItems($selection['meta']['pending'] ?? [])];
    }

    public function runAnalysis(): void
    {
        $response = $this->call('post', '/v1/runs', $this->tab === 'selection' ? ['scope' => 'selection'] : []);
        if ($response !== null) {
            Notification::make()->title(($response['status'] ?? '') === 'started' ? 'Analysis started' : ($response['error'] ?? 'Could not start'))
                ->success()->send();
        }
        $this->refresh();
    }

    public function removeFromSelection(int $productId): void
    {
        $this->call('post', "/v1/selection/{$productId}/remove");
        $this->refresh();
    }

    public function analyze(int $productId): void
    {
        $this->call('post', "/v1/products/{$productId}/analyze");
        $this->refresh();
    }

    public function compose(int $productId): void
    {
        $item = $this->item($productId);
        $this->composeProductId = $productId;
        $this->composeKind = null;
        $this->composeMessage = '';
        $this->composeSent = false;
        $this->composeImage = $item['image'] ?? null;
        $this->composeScheduleAt = filled($item['scheduled_for'] ?? null) ? substr($item['scheduled_for'], 0, 16) : null;
        $this->dispatch('open-modal', id: 'compose-publication');
    }

    public function generateMessage(): void
    {
        $this->composeKind = 'message';
        $item = $this->item((int) $this->composeProductId);
        if ($item === null) {
            return;
        }
        try {
            $this->composeMessage = OfferMessage::generate([...$item, 'url' => $this->purchaseLink((string) ($item['url'] ?? ''))]);
        } catch (AiProviderException $e) {
            Notification::make()->title('Could not write the message')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * The product image as a data URL, so the browser can turn it into a PNG for
     * the clipboard (WhatsApp paste) without cross-origin restrictions. Only the
     * image of the product being composed is fetched.
     */
    public function composeImageData(): ?string
    {
        $url = $this->item((int) $this->composeProductId)['image'] ?? null;
        if (blank($url)) {
            return null;
        }
        try {
            $response = Http::timeout(10)->get($url);
        } catch (Throwable) {
            return null;
        }
        $type = Str::before((string) $response->header('Content-Type'), ';');

        return $response->successful() && str_starts_with($type, 'image/') && strlen($response->body()) < 5_000_000
            ? 'data:'.$type.';base64,'.base64_encode($response->body())
            : null;
    }

    /**
     * The product page at the store, to open it (and, on Mercado Livre, generate the affiliate link).
     */
    public function composeProductUrl(): ?string
    {
        return $this->item((int) $this->composeProductId)['url'] ?? null;
    }

    /**
     * Mercado Livre affiliate links only exist through its "Compartilhar" button, so the
     * user pastes one into the message; the modal warns while it is missing.
     */
    public function isMercadoLivre(): bool
    {
        return str_contains((string) parse_url((string) ($this->item((int) $this->composeProductId)['url'] ?? ''), PHP_URL_HOST), 'mercadolivre.com');
    }

    public function publish(bool $schedule = false): void
    {
        if (blank($this->composeMessage)) {
            Notification::make()->title('Write the message first')->warning()->send();

            return;
        }
        $body = ['product_id' => $this->composeProductId, 'message' => $this->composeMessage, 'image' => $this->composeImage];
        if ($schedule) {
            if (blank($this->composeScheduleAt)) {
                Notification::make()->title('Choose when to publish')->warning()->send();

                return;
            }
            // The browser picker gives local (Brazil) time, the service compares in the same zone.
            $body['scheduled_for'] = Carbon::parse($this->composeScheduleAt, 'America/Sao_Paulo')->toIso8601String();
        }
        $response = $this->call('post', '/v1/publications', $body);
        PublicationStatus::forget();
        if (isset($response['data'])) {
            $status = $response['data']['status'];
            Notification::make()->title('Publication '.str_replace('_', '-', $status))
                ->body($response['data']['detail'] ?? null)
                ->color(in_array($status, ['sent', 'dry_run', 'scheduled'], true) ? 'success' : 'warning')
                ->send();
            $this->dispatch('close-modal', id: 'compose-publication');
        }
        $this->refresh();
    }

    /**
     * The message was pasted in WhatsApp by hand: record it as published now,
     * like a publication sent through the Publish button.
     */
    public function markSent(): void
    {
        if ($this->composeSent || blank($this->composeMessage)) {
            return;
        }
        $response = $this->call('post', '/v1/publications/manual', [
            'product_id' => $this->composeProductId, 'message' => $this->composeMessage,
            'channel' => 'whatsapp', 'image' => $this->composeImage,
        ]);
        PublicationStatus::forget();
        if (isset($response['data'])) {
            $this->composeSent = true;
            Notification::make()->title('Marked as sent to WhatsApp')->success()->send();
        }
        $this->refresh();
    }

    /**
     * Where "Publish" sends to, for the notice in the modal. Only Telegram exists today.
     *
     * @return array{channel: string, chat_id: ?string, ready: bool, dry_run: bool}
     */
    public function publishTarget(): array
    {
        $settings = AppSettings::new()->intelligence_settings;
        $chatId = data_get($settings, 'telegram_chat_id');
        $ready = filled($chatId) && filled(NotificationsHelper::getSetting(NotificationMethods::Telegram, 'bot_token'));

        return [
            'channel' => 'Telegram',
            'chat_id' => $chatId,
            'ready' => $ready,
            'dry_run' => (bool) data_get($settings, 'dry_run', true) || ! $ready,
        ];
    }

    /**
     * Amazon: the canonical /dp/ASIN link with the associate tag is short and still
     * official, unlike the product page link full of tracking parameters.
     */
    protected function purchaseLink(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (str_contains($host, 'amazon.') && preg_match('#/(?:dp|gp/product)/([A-Z0-9]{10})#', $url, $match)) {
            return AffiliateHelper::new()->parseUrl("https://{$host}/dp/{$match[1]}");
        }

        return $url;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function item(int $productId): ?array
    {
        return collect([...$this->items, ...$this->selection])->firstWhere('product_id', $productId);
    }

    public function cancel(int $publicationId): void
    {
        $this->call('post', "/v1/publications/{$publicationId}/cancel");
        PublicationStatus::forget();
        $this->refresh();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function visibleItems(): array
    {
        return $this->applyFilters($this->showAll
            ? $this->items
            : array_values(array_filter($this->items, fn (array $item): bool => ! in_array($item['action'], self::HIDDEN_ACTIONS, true))));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function visibleSelection(): array
    {
        return $this->applyFilters($this->selection);
    }

    /**
     * @return array<int|string, string>
     */
    public function tagOptions(): array
    {
        return Tag::where('user_id', auth()->id())->orderBy('name')->pluck('name', 'id')->all();
    }

    public function resetFilters(): void
    {
        $this->filters = array_fill_keys(array_keys($this->filters), null);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function applyFilters(array $items): array
    {
        $f = array_filter($this->filters, fn (mixed $value): bool => filled($value));
        if ($f === []) {
            return $items;
        }
        $tagged = isset($f['tag'])
            ? Product::whereIn('id', array_column($items, 'product_id'))
                ->whereHas('tags', fn ($query) => $query->whereKey($f['tag']))->pluck('id')->all()
            : [];

        return array_values(array_filter($items, function (array $item) use ($f, $tagged): bool {
            $prices = $item['prices'] ?? [];
            $imported = filled($item['imported_at'] ?? null) ? Carbon::parse($item['imported_at'])->toDateString() : null;
            $publication = PublicationStatus::for((int) $item['product_id'])['status'] ?? null;

            return (! isset($f['tag']) || in_array($item['product_id'], $tagged, true))
                && (! isset($f['min_discount']) || ($prices['discount_percent'] ?? 0) >= (float) $f['min_discount'])
                && (! isset($f['publication']) || match ($f['publication']) {
                    'not_published' => $publication === null,
                    default => $publication !== null && str_starts_with($publication, $f['publication']),
                })
                && (! isset($f['from']) || ($imported !== null && $imported >= $f['from']))
                && (! isset($f['until']) || ($imported !== null && $imported <= $f['until']))
                && (! isset($f['min_price']) || ($prices['offer'] ?? 0) >= (float) $f['min_price'])
                && (! isset($f['max_price']) || ($prices['offer'] ?? 0) <= (float) $f['max_price'])
                && (! isset($f['text']) || Str::contains($item['title'] ?? '', $f['text'], ignoreCase: true));
        }));
    }

    /**
     * Selected products not analyzed yet, shaped like an analysis so they share
     * the list, the filters and the card.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array<string, mixed>>
     */
    protected function pendingItems(array $productIds): array
    {
        return Product::whereIn('id', $productIds)->get()->map(function (Product $product): array {
            $price = $product->getPriceCache()->first();

            return [
                'product_id' => $product->id, 'title' => $product->title, 'image' => $product->primary_image,
                'store' => $price?->getStoreName(), 'url' => $price?->getUrl(), 'action' => 'pending',
                'reasons' => [], 'risks' => [], 'references' => [], 'conditions' => [],
                'prices' => ['offer' => $product->current_price, 'original' => $price?->hasOriginalPrice() ? $price->getOriginalPrice() : null,
                    'discount_percent' => $price?->getDiscountPercentage(), 'history_median' => null, 'history_low' => null,
                    'references_confirmed' => 0],
                'imported_at' => $product->created_at?->toIso8601String(), 'analyzed_at' => null, 'scheduled_for' => null,
                'market_status' => null,
            ];
        })->all();
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    protected function call(string $method, string $path, array $body = []): ?array
    {
        try {
            $request = Http::timeout((int) config('services.intelligence.timeout', 30))->acceptJson();
            $response = $method === 'post'
                ? $request->post(config('services.intelligence.url').$path, (object) $body)
                : $request->get(config('services.intelligence.url').$path);
        } catch (ConnectionException) {
            $this->error = 'pricebuddy-intelligence is not reachable at '.config('services.intelligence.url');

            return null;
        }

        if ($response->failed()) {
            Notification::make()->title(Str::limit((string) ($response->json('error') ?? 'HTTP '.$response->status()), 200))
                ->danger()->send();

            return null;
        }

        return $response->json();
    }
}
