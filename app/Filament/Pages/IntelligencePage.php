<?php

namespace App\Filament\Pages;

use App\Enums\NotificationMethods;
use App\Exceptions\AiProviderException;
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

/**
 * "Products to post today" from pricebuddy-intelligence. Nothing is stored in
 * PriceBuddy: this page only reads and triggers the service's /v1 API.
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

    public ?string $error = null;

    /** Publish modal: the product being published, what to create and the editable message. */
    public ?int $composeProductId = null;

    public ?string $composeKind = null;

    public string $composeMessage = '';

    /** Purchase link used in the message; for Mercado Livre the user pastes the official affiliate link. */
    public string $composeLink = '';

    public ?string $composeImage = null;

    public ?string $composeScheduleAt = null;

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
    }

    public function runAnalysis(): void
    {
        $response = $this->call('post', '/v1/runs');
        if ($response !== null) {
            Notification::make()->title(($response['status'] ?? '') === 'started' ? 'Analysis started' : ($response['error'] ?? 'Could not start'))
                ->success()->send();
        }
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
        $this->composeLink = $this->purchaseLink((string) ($item['url'] ?? ''));
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
            $this->composeMessage = OfferMessage::generate([...$item, 'url' => $this->composeLink]);
        } catch (AiProviderException $e) {
            Notification::make()->title('Could not write the message')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * A link pasted after the message was written replaces the old one in the text.
     */
    public function updatingComposeLink(string $value): void
    {
        if (filled($this->composeLink) && filled($value) && str_contains($this->composeMessage, $this->composeLink)) {
            $this->composeMessage = str_replace($this->composeLink, trim($value), $this->composeMessage);
        }
    }

    /**
     * Mercado Livre affiliate links only exist through its "Compartilhar" button,
     * so the user pastes it into the publish modal. The notice stays until a meli.la link is in.
     */
    public function needsAffiliateLink(): bool
    {
        $productHost = (string) parse_url((string) ($this->item((int) $this->composeProductId)['url'] ?? ''), PHP_URL_HOST);

        return str_contains($productHost, 'mercadolivre.com') && parse_url($this->composeLink, PHP_URL_HOST) !== 'meli.la';
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
        return collect($this->items)->firstWhere('product_id', $productId);
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
        return $this->showAll
            ? $this->items
            : array_values(array_filter($this->items, fn (array $item): bool => ! in_array($item['action'], self::HIDDEN_ACTIONS, true)));
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
