@php
    $actionColors = [
        'publish_now' => 'success', 'repost' => 'success', 'schedule' => 'info',
        'wait_confirmation' => 'warning', 'monitor' => 'gray', 'ignore' => 'danger',
    ];
    $brl = fn ($value) => $value === null ? '—' : 'R$ '.number_format((float) $value, 2, ',', '.');
    // wait_confirmation can be published by hand: the user checks the market prices.
    $publishable = ['publish_now', 'schedule', 'repost', 'wait_confirmation'];
@endphp
<x-filament-panels::page>
    <div x-data="{ tab: 'today' }">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <x-filament::tabs>
                <x-filament::tabs.item @click="tab = 'today'" :alpine-active="'tab === \'today\''">
                    {{ __('Products to post today') }} ({{ count($this->visibleItems()) }})
                </x-filament::tabs.item>
                <x-filament::tabs.item @click="tab = 'publications'" :alpine-active="'tab === \'publications\''">
                    {{ __('Publications') }} ({{ count($publications) }})
                </x-filament::tabs.item>
            </x-filament::tabs>
            <div class="flex items-center gap-3">
                <span class="text-xs text-gray-500">
                    {{ __('Last run') }}: {{ $lastRun['status'] ?? '—' }}
                    @isset($lastRun['analyzed']) · {{ $lastRun['analyzed'] }} {{ __('analyzed') }} @endisset
                    @isset($lastRun['error']) · {{ $lastRun['error'] }} @endisset
                </span>
                <x-filament::button size="sm" color="gray" icon="heroicon-m-arrow-path" wire:click="refresh">{{ __('Refresh') }}</x-filament::button>
                <x-filament::button size="sm" icon="heroicon-m-play" wire:click="runAnalysis">{{ __('Analyze now') }}</x-filament::button>
            </div>
        </div>

        @if ($error)
            <x-filament::section class="mb-6"><span class="text-danger-600">{{ $error }}</span></x-filament::section>
        @endif

        <div x-show="tab === 'today'" class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-gray-500">
                <span>
                    @if (isset($meta['in_window']))
                        {{ $meta['in_window'] }} {{ __('imported in the window') }} ·
                        {{ $meta['analyzed'] }} {{ __('analyzed') }} ·
                        <span @class(['font-semibold text-warning-600' => ($meta['pending'] ?? 0) > 0])>{{ $meta['pending'] }} {{ __('waiting for analysis') }}</span>
                    @endif
                </span>
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model.live="showAll" class="rounded border-gray-300">
                    {{ __('Show monitor/ignore') }} ({{ count($items) - count(array_filter($items, fn ($i) => ! in_array($i['action'], \App\Filament\Pages\IntelligencePage::HIDDEN_ACTIONS, true))) }})
                </label>
            </div>
            @forelse ($this->visibleItems() as $item)
                @php($p = $item['prices'])
                <x-filament::section wire:key="intel-{{ $item['product_id'] }}">
                    <div class="flex flex-col md:flex-row gap-4">
                        @if ($item['image'])
                            <img src="{{ $item['image'] }}" alt="" class="w-24 h-24 object-contain rounded-md bg-white">
                        @endif
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <x-filament::badge :color="$actionColors[$item['action']] ?? 'gray'">{{ $item['action'] }}</x-filament::badge>
                                <a href="{{ \App\Filament\Resources\ProductResource::getUrl('view', ['record' => $item['product_id']]) }}"
                                   class="font-semibold truncate hover:underline">{{ $item['title'] }}</a>
                            </div>
                            <div class="text-sm mb-2">
                                <span class="text-xl font-bold">{{ $brl($p['offer']) }}</span>
                                @if ($p['original'])<span class="line-through text-gray-400 ml-1">{{ $brl($p['original']) }}</span>@endif
                                @if ($p['discount_percent'])<span class="text-danger-600 ml-1">-{{ (int) $p['discount_percent'] }}%</span>@endif
                                <span class="text-gray-500 ml-2">@ {{ $item['store'] }}</span>
                                <span class="text-gray-500 ml-2">· {{ __('usual') }} {{ $brl($p['history_median']) }} · {{ __('lowest') }} {{ $brl($p['history_low']) }}</span>
                            </div>
                            <ul class="text-sm list-disc ml-5">
                                @foreach ($item['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach
                            </ul>
                            @if ($item['risks'])
                                <ul class="text-sm list-disc ml-5 mt-1 text-warning-600">
                                    @foreach ($item['risks'] as $risk)<li>{{ $risk }}</li>@endforeach
                                </ul>
                            @endif
                            @if ($item['references'])
                                <details class="mt-2 text-sm">
                                    <summary class="cursor-pointer text-gray-500">
                                        {{ __('References') }}: {{ $p['references_confirmed'] }} {{ __('confirmed') }} / {{ count($item['references']) }} · {{ __('market') }}: {{ $item['market_status'] }}
                                    </summary>
                                    <table class="w-full mt-2 text-xs">
                                        <tbody>
                                        @foreach (collect($item['references'])->sortBy('price') as $ref)
                                            <tr class="border-t border-gray-200 dark:border-white/10 {{ $ref['equivalence'] === 'same' ? '' : 'text-gray-400' }}">
                                                <td class="py-1 pr-2 whitespace-nowrap">{{ $brl($ref['price']) }}</td>
                                                <td class="pr-2 whitespace-nowrap">{{ $ref['store'] ?? '—' }}</td>
                                                <td class="pr-2">{{ \Illuminate\Support\Str::limit($ref['title'], 70) }}</td>
                                                <td class="pr-2 whitespace-nowrap">{{ $ref['equivalence'] }}</td>
                                                <td class="pr-2">{{ implode('; ', $ref['equivalence_reasons']) }}</td>
                                                <td class="whitespace-nowrap">{{ $ref['source'] }}</td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </details>
                            @endif
                        </div>
                        <div class="flex md:flex-col gap-2 md:w-56">
                            @if (in_array($item['action'], $publishable, true))
                                <x-filament::button size="sm" icon="heroicon-m-paper-airplane" wire:click="compose({{ $item['product_id'] }})">{{ __('Publish') }}</x-filament::button>
                            @endif
                            <x-filament::button size="sm" color="gray" icon="heroicon-m-arrow-path" wire:click="analyze({{ $item['product_id'] }})">{{ __('Re-analyze') }}</x-filament::button>
                            <span class="text-xs text-gray-500">{{ __('Analyzed') }} {{ \Illuminate\Support\Carbon::parse($item['analyzed_at'])->format('d/m H:i') }}</span>
                        </div>
                    </div>
                </x-filament::section>
            @empty
                <x-filament::section>
                    {{ $items ? __('Nothing worth posting right now: every analyzed product is in monitor or ignore.') : __('Nothing analyzed for the current window yet. Use "Analyze now".') }}
                </x-filament::section>
            @endforelse
        </div>

        <div x-show="tab === 'publications'" x-cloak>
            <x-filament::section>
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500">
                        <th class="py-1">#</th><th>{{ __('Product') }}</th><th>{{ __('Price') }}</th><th>{{ __('Status') }}</th>
                        <th>{{ __('Scheduled for') }}</th><th>{{ __('Published at') }}</th><th>{{ __('Detail') }}</th><th></th>
                    </tr></thead>
                    <tbody>
                    @forelse ($publications as $publication)
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="py-1">{{ $publication['id'] }}</td>
                            <td><a class="hover:underline" href="{{ \App\Filament\Resources\ProductResource::getUrl('view', ['record' => $publication['product_id']]) }}">#{{ $publication['product_id'] }}</a></td>
                            <td>{{ $brl($publication['price']) }}</td>
                            <td><x-filament::badge :color="match ($publication['status']) { 'sent' => 'success', 'dry_run' => 'info', 'scheduled' => 'warning', default => 'gray' }">{{ $publication['status'] }}</x-filament::badge></td>
                            <td>{{ \Illuminate\Support\Carbon::parse($publication['scheduled_for'])->format('d/m H:i') }}</td>
                            <td>{{ $publication['published_at'] ? \Illuminate\Support\Carbon::parse($publication['published_at'])->format('d/m H:i') : '—' }}</td>
                            <td class="text-xs">{{ $publication['detail'] }}</td>
                            <td>
                                @if ($publication['status'] === 'scheduled')
                                    <x-filament::button size="xs" color="danger" wire:click="cancel({{ $publication['id'] }})">{{ __('Cancel') }}</x-filament::button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-2 text-gray-500">{{ __('No publications yet.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </x-filament::section>
        </div>
    </div>

    @php($target = $this->publishTarget())
    <x-filament::modal id="compose-publication" width="2xl">
        <x-slot name="heading">{{ __('Publish') }}</x-slot>
        @if ($composeProductId)
            <x-slot name="description">{{ collect($items)->firstWhere('product_id', $composeProductId)['title'] ?? '' }}</x-slot>
        @endif

        <div class="flex flex-col gap-2 mb-2">
            <label class="text-sm font-medium">{{ __('Purchase link') }}</label>
            <input type="url" wire:model.blur="composeLink"
                   class="w-full text-sm rounded-md border-gray-300 dark:bg-gray-900 dark:border-white/10">
            @if ($this->needsAffiliateLink())
                <div class="text-sm rounded-md p-2 bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                    {{ __('Mercado Livre: this is the plain product link. Open the product, click "Compartilhar" in the affiliate bar and paste the meli.la link here.') }}
                </div>
            @endif
        </div>

        <div class="flex gap-2">
            <x-filament::button size="sm" :color="$composeKind === 'message' ? 'primary' : 'gray'" icon="heroicon-m-chat-bubble-left-right" wire:click="generateMessage">
                {{ $composeKind === 'message' ? __('Rewrite message') : __('Message') }}
            </x-filament::button>
            <x-filament::button size="sm" :color="$composeKind === 'video' ? 'primary' : 'gray'" icon="heroicon-m-video-camera" wire:click="$set('composeKind', 'video')">
                {{ __('Video') }}
            </x-filament::button>
        </div>

        <div wire:loading wire:target="generateMessage" class="text-sm text-gray-500">{{ __('Writing the message…') }}</div>

        @if ($composeKind === 'message')
            <div wire:loading.remove wire:target="generateMessage" class="flex flex-col gap-3"
                 x-data="{ copied: false, copy() {
                     const text = $refs.message.value;
                     const done = () => { this.copied = true; setTimeout(() => this.copied = false, 2000) };
                     if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done) }
                     else { $refs.message.select(); document.execCommand('copy'); done() }
                 } }">
                @if ($composeImage)
                    <div class="flex items-end gap-3">
                        <img src="{{ $composeImage }}" alt="" class="w-32 h-32 object-contain rounded-md bg-white">
                        <a href="{{ $composeImage }}" target="_blank" rel="noopener" class="text-sm text-primary-600 hover:underline">{{ __('Open image to save') }}</a>
                    </div>
                @endif
                <textarea x-ref="message" wire:model="composeMessage" rows="14"
                          class="w-full text-sm font-mono rounded-md border-gray-300 dark:bg-gray-900 dark:border-white/10"></textarea>

                <div @class(['text-sm rounded-md p-2', 'bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-400' => ! $target['dry_run'], 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400' => $target['dry_run']])>
                    {{ $composeImage ? __('Publish sends the image and this message to') : __('Publish sends this message to') }} <b>{{ $target['channel'] }}</b>@if ($target['chat_id']) ({{ $target['chat_id'] }})@endif.
                    @if (! $target['ready'])
                        {{ __('Telegram is not set up (bot token in Notifications > Telegram, chat id in Settings > Intelligence): it will only be recorded.') }}
                    @elseif ($target['dry_run'])
                        {{ __('Dry-run is on: it will only be recorded, not sent.') }}
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::button size="sm" color="gray" icon="heroicon-m-clipboard-document" x-on:click="copy()">
                        <span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'"></span>
                    </x-filament::button>
                    <x-filament::button size="sm" icon="heroicon-m-paper-airplane" wire:click="publish">{{ __('Publish to Telegram') }}</x-filament::button>
                    <input type="datetime-local" wire:model="composeScheduleAt"
                           class="text-sm rounded-md border-gray-300 dark:bg-gray-900 dark:border-white/10">
                    <x-filament::button size="sm" color="gray" icon="heroicon-m-clock" wire:click="publish(true)">{{ __('Schedule') }}</x-filament::button>
                </div>
            </div>
        @elseif ($composeKind === 'video')
            <div class="flex items-center justify-center h-48 rounded-md border border-dashed border-gray-300 dark:border-white/10 text-sm text-gray-500">
                <x-filament::icon icon="heroicon-o-video-camera" class="w-6 h-6 mr-2" />
                {{ __('Video generation is coming soon.') }}
            </div>
        @endif
    </x-filament::modal>
</x-filament-panels::page>
