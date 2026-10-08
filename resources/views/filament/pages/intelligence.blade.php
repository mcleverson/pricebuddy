@php
    $actionColors = [
        'publish_now' => 'success', 'repost' => 'success', 'schedule' => 'info',
        'wait_confirmation' => 'warning', 'monitor' => 'gray', 'ignore' => 'danger', 'pending' => 'gray',
    ];
    $brl = fn ($value) => $value === null ? '—' : 'R$ '.number_format((float) $value, 2, ',', '.');
    // wait_confirmation can be published by hand: the user checks the market prices.
    $publishable = ['publish_now', 'schedule', 'repost', 'wait_confirmation'];
@endphp
<x-filament-panels::page>
    <div>
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <x-filament::tabs>
                <x-filament::tabs.item wire:click="$set('tab', 'today')" :active="$tab === 'today'">
                    {{ __('Products to post today') }} ({{ count($this->visibleItems()) }})
                </x-filament::tabs.item>
                <x-filament::tabs.item wire:click="$set('tab', 'selection')" :active="$tab === 'selection'">
                    {{ __('My Product Selection') }} ({{ count($this->visibleSelection()) }})
                </x-filament::tabs.item>
                <x-filament::tabs.item wire:click="$set('tab', 'publications')" :active="$tab === 'publications'">
                    {{ __('Publications') }} ({{ count($publications) }})
                </x-filament::tabs.item>
            </x-filament::tabs>
            <div class="flex items-center gap-3">
                <span class="text-xs text-gray-500">
                    {{ __('Last run') }}: {{ $lastRun['status'] ?? '—' }}
                    @if (($lastRun['scope'] ?? null) === 'selection') ({{ __('selection') }}) @endif
                    @isset($lastRun['analyzed']) · {{ $lastRun['analyzed'] }} {{ __('analyzed') }} @endisset
                    @isset($lastRun['error']) · {{ $lastRun['error'] }} @endisset
                </span>
                <x-filament::button size="sm" color="gray" icon="heroicon-m-arrow-path" wire:click="refresh">{{ __('Refresh') }}</x-filament::button>
                <x-filament::button size="sm" icon="heroicon-m-play" wire:click="runAnalysis">
                    {{ $tab === 'selection' ? __('Analyze selection') : __('Analyze now') }}
                </x-filament::button>
            </div>
        </div>

        @if ($error)
            <x-filament::section class="mb-6"><span class="text-danger-600">{{ $error }}</span></x-filament::section>
        @endif

        @if ($tab !== 'publications')
            @php($tags = $this->tagOptions())
            @php($input = 'text-sm rounded-md border-gray-300 dark:bg-gray-900 dark:border-white/10')
            <div class="flex flex-wrap items-end gap-2 mb-4 text-sm">
                <input type="search" wire:model.live.debounce.400ms="filters.text" placeholder="{{ __('Search title') }}" class="{{ $input }}">
                <select wire:model.live="filters.tag" class="{{ $input }}">
                    <option value="">{{ __('All tags') }}</option>
                    @foreach ($tags as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <select wire:model.live="filters.min_discount" class="{{ $input }}">
                    <option value="">{{ __('Any discount') }}</option>
                    @foreach ([10, 20, 30, 50] as $pct)<option value="{{ $pct }}">{{ $pct }}% {{ __('or more') }}</option>@endforeach
                </select>
                <select wire:model.live="filters.publication" class="{{ $input }}">
                    <option value="">{{ __('Any publication') }}</option>
                    <option value="published">{{ __('Published') }}</option>
                    <option value="in_queue">{{ __('In queue') }}</option>
                    <option value="not_published">{{ __('Not published') }}</option>
                </select>
                <label class="flex flex-col text-xs text-gray-500">{{ __('Imported from') }}<input type="date" wire:model.live="filters.from" class="{{ $input }}"></label>
                <label class="flex flex-col text-xs text-gray-500">{{ __('until') }}<input type="date" wire:model.live="filters.until" class="{{ $input }}"></label>
                <input type="number" min="0" wire:model.live.debounce.400ms="filters.min_price" placeholder="{{ __('Min price') }}" class="{{ $input }} w-28">
                <input type="number" min="0" wire:model.live.debounce.400ms="filters.max_price" placeholder="{{ __('Max price') }}" class="{{ $input }} w-28">
                <x-filament::button size="sm" color="gray" wire:click="resetFilters">{{ __('Clear') }}</x-filament::button>
            </div>
        @endif

        @if ($tab === 'today')
        <div class="flex flex-col gap-4">
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
                @include('filament.pages.partials.intelligence-item', ['inSelection' => false])
            @empty
                <x-filament::section>
                    {{ $items ? __('Nothing to show: every analyzed product is in monitor/ignore or outside the filters.') : __('Nothing analyzed for the current window yet. Use "Analyze now".') }}
                </x-filament::section>
            @endforelse
        </div>
        @endif

        @if ($tab === 'selection')
        <div class="flex flex-col gap-4">
            @forelse ($this->visibleSelection() as $item)
                @include('filament.pages.partials.intelligence-item', ['inSelection' => true])
            @empty
                <x-filament::section>
                    {{ $selection ? __('No selected product matches the filters.') : __('No products selected. In Products, select products and use "Add to My Product Selection".') }}
                </x-filament::section>
            @endforelse
        </div>
        @endif

        @if ($tab === 'publications')
        <div>
            <x-filament::section>
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500">
                        <th class="py-1">#</th><th>{{ __('Product') }}</th><th>{{ __('Channel') }}</th><th>{{ __('Price') }}</th><th>{{ __('Status') }}</th>
                        <th>{{ __('Scheduled for') }}</th><th>{{ __('Published at') }}</th><th>{{ __('Detail') }}</th><th></th>
                    </tr></thead>
                    <tbody>
                    @forelse ($publications as $publication)
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="py-1">{{ $publication['id'] }}</td>
                            <td><a class="hover:underline" href="{{ \App\Filament\Resources\ProductResource::getUrl('view', ['record' => $publication['product_id']]) }}">#{{ $publication['product_id'] }}</a></td>
                            <td>{{ $publication['channel'] }}</td>
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
                        <tr><td colspan="9" class="py-2 text-gray-500">{{ __('No publications yet.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </x-filament::section>
        </div>
        @endif
    </div>

    @php($target = $this->publishTarget())
    <x-filament::modal id="compose-publication" width="2xl">
        <x-slot name="heading">{{ __('Publish') }}</x-slot>
        @if ($composeProductId)
            <x-slot name="description">{{ collect([...$items, ...$selection])->firstWhere('product_id', $composeProductId)['title'] ?? '' }}</x-slot>
        @endif

        <div class="flex flex-wrap gap-2">
            @if ($productUrl = $this->composeProductUrl())
                <x-filament::button size="sm" color="gray" icon="heroicon-m-arrow-top-right-on-square" tag="a" :href="$productUrl" target="_blank" rel="noopener">
                    {{ __('Open product page') }}
                </x-filament::button>
            @endif
            <x-filament::button size="sm" :color="$composeKind === 'message' ? 'primary' : 'gray'" icon="heroicon-m-chat-bubble-left-right" wire:click="generateMessage">
                {{ $composeKind === 'message' ? __('Rewrite message') : __('Message') }}
            </x-filament::button>
            <x-filament::button size="sm" :color="$composeKind === 'video' ? 'primary' : 'gray'" icon="heroicon-m-video-camera" wire:click="startVideo">
                {{ __('Video') }}
            </x-filament::button>
        </div>

        <div wire:loading wire:target="generateMessage" class="text-sm text-gray-500">{{ __('Writing the message…') }}</div>

        @if ($composeKind === 'message')
            <div wire:loading.remove wire:target="generateMessage" class="flex flex-col gap-3"
                 {{-- Mercado Livre: warn before copy/publish, and on close, while the message has no affiliate link (meli.la or the long /social/ link). --}}
                 x-data="{
                     mercadoLivre: @js($this->isMercadoLivre()), copied: false, pending: null, used: false,
                     missingAffiliate() { return this.mercadoLivre && ! /https?:\/\/(meli\.la\/|(www\.)?mercadolivre\.com\.br\/social\/)\S+/.test($refs.message.value) },
                     guard(action, force = false) {
                         if (! force && this.missingAffiliate()) { this.pending = action; return }
                         this.pending = null; this.used = true; action === 'copy' ? this.copy() : $wire.publish(action === 'schedule')
                     },
                     {{-- WhatsApp Web keeps only the image when both go in one paste, so copy in two steps:
                          the image (pasted in the chat) and then the text (pasted in the caption). --}}
                     step: @js($composeImage ? 'image' : 'text'),
                     async copy(textOnly = false) {
                         const done = () => { this.copied = true; setTimeout(() => this.copied = false, 2000) };
                         if (! navigator.clipboard || ! window.isSecureContext) { $refs.message.select(); document.execCommand('copy'); return done() }
                         if (this.step === 'image' && ! textOnly) {
                             try {
                                 await navigator.clipboard.write([new ClipboardItem({ 'image/png': this.png() })]);
                                 this.step = 'text';
                             } catch (e) {
                                 new FilamentNotification().title('{{ __('The image could not be copied') }}').body('{{ __('Copy the text only.') }}').warning().send();
                             }
                             return done()
                         }
                         await navigator.clipboard.writeText($refs.message.value);
                         this.step = @js($composeImage ? 'image' : 'text');
                         done()
                     },
                     async png() {
                         const data = await $wire.composeImageData();
                         if (! data) { throw new Error('image unavailable') }
                         const img = new Image(); img.src = data; await img.decode();
                         const canvas = document.createElement('canvas');
                         canvas.width = img.naturalWidth; canvas.height = img.naturalHeight;
                         canvas.getContext('2d').drawImage(img, 0, 0);
                         return await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
                     },
                 }"
                 x-on:modal-closed.window="if ($event.detail.id === 'compose-publication' && ! used && $refs.message.value && missingAffiliate()) {
                     new FilamentNotification().title('{{ __('Mercado Livre message without affiliate link') }}').body('{{ __('The message you left has no meli.la link, so it would not earn commission.') }}').warning().send()
                 }">
                @if ($composeImage)
                    <div class="flex items-end gap-3">
                        <img src="{{ $composeImage }}" alt="" class="w-32 h-32 object-contain rounded-md bg-white">
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

                <div x-show="pending" x-cloak class="text-sm rounded-md p-2 bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                    {{ __('This Mercado Livre message has no affiliate link. Open the product, click "Compartilhar" in the affiliate bar and paste the meli.la link in the message.') }}
                    <button type="button" class="ml-1 font-semibold underline" x-on:click="guard(pending, true)">{{ __('Continue anyway') }}</button>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::button size="sm" color="gray" icon="heroicon-m-clipboard-document" x-on:click="guard('copy')">
                        <span x-text="copied ? '{{ __('Copied') }}' : (step === 'image' ? '{{ __('1. Copy image') }}' : '{{ $composeImage ? __('2. Copy text') : __('Copy') }}')"></span>
                    </x-filament::button>
                    @if ($composeImage)
                        <button type="button" class="text-xs text-gray-500 hover:underline" x-show="step === 'image'" x-on:click="copy(true)">{{ __('Copy text only') }}</button>
                        <span class="text-xs text-gray-500" x-show="step === 'text'" x-cloak>{{ __('Paste the image in the WhatsApp chat, then copy the text and paste it in the caption.') }}</span>
                    @endif
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" class="rounded border-gray-300" wire:click="markSent" x-on:change="used = true"
                               @checked($composeSent) @disabled($composeSent)>
                        {{ $composeSent ? __('Sent to WhatsApp') : __('Mark as sent to WhatsApp') }}
                    </label>
                    <x-filament::button size="sm" icon="heroicon-m-paper-airplane" x-on:click="guard('publish')">{{ __('Publish to Telegram') }}</x-filament::button>
                    <input type="datetime-local" wire:model="composeScheduleAt"
                           class="text-sm rounded-md border-gray-300 dark:bg-gray-900 dark:border-white/10">
                    <x-filament::button size="sm" color="gray" icon="heroicon-m-clock" x-on:click="guard('schedule')">{{ __('Schedule') }}</x-filament::button>
                </div>
            </div>
        @elseif ($composeKind === 'video')
            @php($rendering = in_array($composeRender['status'] ?? null, ['queued', 'rendering'], true))
            @php($input = 'text-sm rounded-md border-gray-300 dark:bg-gray-900 dark:border-white/10')
            <div class="flex flex-col gap-4" @if ($rendering) wire:poll.3s="pollVideo" @endif>
                {{-- Slides in this order: the product photo (optional), then the uploaded images and videos. --}}
                <div class="flex flex-col gap-2">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" class="rounded border-gray-300" wire:model="composeUseProductImage">
                        {{ __('Product photo as the first slide') }}
                    </label>
                    @foreach ($composeMedia as $index => $file)
                        <div class="flex items-center justify-between gap-2 text-sm rounded-md bg-gray-50 dark:bg-white/5 px-3 py-1.5" wire:key="media-{{ $index }}">
                            <span class="truncate">{{ $index + 1 }}. {{ $file->getClientOriginalName() }}</span>
                            <x-filament::icon-button icon="heroicon-m-x-mark" color="gray" size="sm" wire:click="removeMedia({{ $index }})" :label="__('Remove')" />
                        </div>
                    @endforeach
                    <input type="file" multiple accept="image/*,video/mp4,video/quicktime,video/webm" wire:model="composeUpload" class="text-sm">
                    <div wire:loading wire:target="composeUpload" class="text-sm text-gray-500">{{ __('Uploading…') }}</div>
                    @error('composeUpload.*') <div class="text-sm text-danger-600">{{ $message }}</div> @enderror
                </div>

                @if ($composeTemplate !== [])
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
                        <label class="flex flex-col gap-1">{{ __('Primary color') }}
                            <input type="color" wire:model="composeTemplate.primaryColor" class="h-9 w-full rounded-md">
                        </label>
                        <label class="flex flex-col gap-1">{{ __('Background') }}
                            <input type="color" wire:model="composeTemplate.backgroundColor" class="h-9 w-full rounded-md">
                        </label>
                        <label class="flex flex-col gap-1">{{ __('Text color') }}
                            <input type="color" wire:model="composeTemplate.textColor" class="h-9 w-full rounded-md">
                        </label>
                        <label class="flex flex-col gap-1 col-span-2 sm:col-span-1">{{ __('Call to action') }}
                            <input type="text" wire:model="composeTemplate.cta" class="{{ $input }}">
                        </label>
                        <label class="flex flex-col gap-1">{{ __('Seconds per image') }}
                            <input type="number" min="1" max="15" step="0.5" wire:model="composeTemplate.secondsPerSlide" class="{{ $input }}">
                        </label>
                        <label class="flex flex-col gap-1">{{ __('Transition') }}
                            <select wire:model="composeTemplate.transition" class="{{ $input }}">
                                <option value="fade">{{ __('Fade') }}</option>
                                <option value="slide">{{ __('Slide') }}</option>
                                <option value="none">{{ __('None') }}</option>
                            </select>
                        </label>
                        <div class="col-span-2 sm:col-span-3 flex flex-wrap gap-4">
                            <label class="flex items-center gap-2"><input type="checkbox" class="rounded border-gray-300" wire:model="composeTemplate.showOriginalPrice"> {{ __('Original price') }}</label>
                            <label class="flex items-center gap-2"><input type="checkbox" class="rounded border-gray-300" wire:model="composeTemplate.showDiscount"> {{ __('Discount') }}</label>
                            <label class="flex items-center gap-2"><input type="checkbox" class="rounded border-gray-300" wire:model="composeTemplate.showStore"> {{ __('Store') }}</label>
                        </div>
                    </div>
                @endif

                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::button size="sm" icon="heroicon-m-film" wire:click="renderVideo" :disabled="$rendering" wire:loading.attr="disabled" wire:target="renderVideo,composeUpload">
                        {{ $composeVideoUrl ? __('Render again') : __('Render video') }}
                    </x-filament::button>
                    <span wire:loading wire:target="renderVideo" class="text-sm text-gray-500">{{ __('Sending the files…') }}</span>
                    @if ($rendering)
                        <span class="text-sm text-gray-500">{{ __('Rendering…') }} {{ (int) round(($composeRender['progress'] ?? 0) * 100) }}%</span>
                    @elseif (($composeRender['status'] ?? null) === 'failed')
                        <span class="text-sm text-danger-600">{{ __('The render failed.') }}</span>
                    @endif
                </div>

                @if ($composeVideoUrl)
                    <div class="flex flex-col items-center gap-2">
                        <video src="{{ $composeVideoUrl }}" controls class="max-h-[28rem] rounded-md" wire:key="{{ $composeVideoUrl }}"></video>
                        <x-filament::button size="sm" icon="heroicon-m-arrow-down-tray" tag="a" :href="$composeVideoUrl" download>{{ __('Download MP4') }}</x-filament::button>
                    </div>
                @endif
            </div>
        @endif
    </x-filament::modal>
</x-filament-panels::page>
