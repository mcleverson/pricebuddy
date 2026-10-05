<?php

namespace App\Filament\Pages;

use App\Services\Intelligence\PublicationStatus;
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

    /** @var array<int, string> */
    public array $scheduleAt = [];

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

    public function publish(int $productId, bool $schedule = false): void
    {
        $body = ['product_id' => $productId];
        if ($schedule) {
            if (blank($this->scheduleAt[$productId] ?? null)) {
                Notification::make()->title('Choose when to publish')->warning()->send();

                return;
            }
            // The browser picker gives local (Brazil) time, the service compares in the same zone.
            $body['scheduled_for'] = Carbon::parse($this->scheduleAt[$productId], 'America/Sao_Paulo')->toIso8601String();
        }
        $response = $this->call('post', '/v1/publications', $body);
        PublicationStatus::forget();
        if (isset($response['data'])) {
            $status = $response['data']['status'];
            Notification::make()->title('Publication '.str_replace('_', '-', $status))
                ->body($response['data']['detail'] ?? null)
                ->color(in_array($status, ['sent', 'dry_run', 'scheduled'], true) ? 'success' : 'warning')
                ->send();
        }
        $this->refresh();
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
