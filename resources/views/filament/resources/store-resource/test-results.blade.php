@php
    $ai = $ai ?? null;
    $hasAi = filled($ai);

    $fields = [
        'title' => 'Title',
        'original_price' => 'Original Price',
        'price' => 'Price',
        'currency' => 'Currency',
        'image' => 'Image',
        'availability' => 'Availability',
        'description' => 'Description',
    ];

    $isUrl = fn ($v): bool => is_string($v) && (str_starts_with($v, 'http://') || str_starts_with($v, 'https://'));
@endphp

<div>
    @if (empty($scrape))
        <p class="my-6">{{ __('Unable to find any data, check store settings') }}</p>
    @else
        @php
            $availabilityVal = data_get($scrape, 'availability');
            $availabilityStrategy = data_get($record, 'scrape_strategy.availability');
            $resolvedStatus = \App\Enums\StockStatus::resolveAvailability($availabilityVal, $availabilityStrategy);
            $isSchemaOrgAvailability = data_get($availabilityStrategy, 'type') === \App\Enums\ScraperStrategyType::SchemaOrg->value;
            $matchConfig = data_get($record, 'scrape_strategy.availability.match');

            // The status the resolver falls back to when nothing matches (defaults to In Stock).
            $configuredDefault = \App\Enums\StockStatus::tryFrom(data_get($matchConfig, 'default') ?? '')
                ?? \App\Enums\StockStatus::InStock;

            $matchedRule = null;
            if (is_array($matchConfig)) {
                foreach ($matchConfig as $statusValue => $matchEntry) {
                    if ($statusValue === 'default' || $matchEntry === '' || $matchEntry === null) {
                        continue;
                    }
                    if (is_array($matchEntry)) {
                        $matchValue = $matchEntry['value'] ?? '';
                        $matchType = $matchEntry['type'] ?? 'match';
                        if ($matchValue !== '' && \App\Enums\StockStatus::tryFrom($statusValue)?->value === $resolvedStatus->value) {
                            $matchedRule = $matchType === 'regex' ? "regex \"$matchValue\"" : "exact \"$matchValue\"";
                            break;
                        }
                    } elseif (is_string($matchEntry) && trim((string) $availabilityVal) === trim($matchEntry)
                        && \App\Enums\StockStatus::tryFrom($statusValue)?->value === $resolvedStatus->value) {
                        $matchedRule = "exact \"$matchEntry\"";
                        break;
                    }
                }
            }
        @endphp
        {{-- Loader is scoped to the "Change scraper" re-test only. The scrape buttons and the
             Compare with AI action each show their own button loading indicator. --}}
        <div wire:loading wire:target="mountedActionsData.0.test_scraper" class="flex items-center gap-2 py-6 text-sm text-gray-500 dark:text-gray-400">
            <x-filament::loading-indicator class="h-5 w-5" />
            {{ __('Scraping…') }}
        </div>

        <div wire:loading.remove wire:target="mountedActionsData.0.test_scraper">
        <table class="w-full text-sm border-collapse table-fixed">
            <thead>
                <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                    <th class="py-2 pr-4 font-semibold w-1/3">Field</th>
                    <th class="py-2 pr-4 font-semibold w-1/3">Scraped</th>
                    @if ($hasAi)
                        <th class="py-2 pr-4 font-semibold w-1/3">AI ✨</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($fields as $key => $label)
                    <tr class="border-b border-gray-100 dark:border-white/5 align-top">
                        <td class="py-2 pr-4 font-medium text-gray-500 dark:text-gray-400">{{ $label }}</td>

                        <td class="py-2 pr-4">
                            {{-- Scraper has no currency field; AI may. --}}
                            @php $scrapedVal = $key === 'currency' ? null : data_get($scrape, $key); @endphp
                            @if ($key === 'image' && $isUrl($scrapedVal))
                                <img src="{{ $scrapedVal }}" alt="" class="h-16 w-16 rounded object-contain bg-white" />
                            @elseif ($key === 'availability' && filled($scrapedVal))
                                {{ $resolvedStatus->getLabel() }}@if ($matchedRule) <span class="text-gray-400">— matched {{ $matchedRule }}</span>@elseif ($isSchemaOrgAvailability) <span class="text-gray-400">— inferred from schema.org</span>@elseif ($resolvedStatus === $configuredDefault) <span class="text-gray-400">— no match (default)</span>@endif
                            @elseif (filled($scrapedVal))
                                <span class="break-words">{{ $scrapedVal }}</span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>

                        @if ($hasAi)
                            <td class="py-2 pr-4">
                                @php $aiVal = data_get($ai, $key); @endphp
                                @if ($key === 'image' && $isUrl($aiVal))
                                    <img src="{{ $aiVal }}" alt="" class="h-16 w-16 rounded object-contain bg-white" />
                                @elseif (filled($aiVal))
                                    <span class="break-words">{{ $aiVal }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach

                @if ($hasAi)
                    <tr class="border-b border-gray-100 dark:border-white/5">
                        <td class="py-2 pr-4 font-medium text-gray-500 dark:text-gray-400">Confidence</td>
                        <td class="py-2 pr-4"><span class="text-gray-400">—</span></td>
                        <td class="py-2 pr-4">@if (filled(data_get($ai, 'confidence'))){{ number_format((float) data_get($ai, 'confidence'), 2) }}@else<span class="text-gray-400">—</span>@endif</td>
                    </tr>
                @endif
            </tbody>
        </table>

        @if (filled(data_get($scrape, 'errors')))
            <div class="mt-6">
                <x-filament::section heading="Errors">
                    <code class="block whitespace-pre-wrap break-all text-xs">{{ json_encode(data_get($scrape, 'errors'), JSON_PRETTY_PRINT) }}</code>
                </x-filament::section>
            </div>
        @endif

        @if (filled(data_get($scrape, 'body')))
            <div class="mt-6">
                <div
                    x-data="{
                        copied: false,
                        async copy() {
                            const text = @js(data_get($scrape, 'body'));

                            try {
                                if (window.navigator.clipboard && window.isSecureContext) {
                                    await window.navigator.clipboard.writeText(text);
                                } else {
                                    const textarea = document.createElement('textarea');
                                    textarea.value = text;
                                    textarea.style.position = 'fixed';
                                    textarea.style.opacity = '0';
                                    document.body.appendChild(textarea);
                                    textarea.focus();
                                    textarea.select();
                                    const succeeded = document.execCommand('copy');
                                    textarea.remove();

                                    if (!succeeded) {
                                        throw new Error('Clipboard copy failed');
                                    }
                                }

                                this.copied = true;
                                setTimeout(() => this.copied = false, 2000);
                            } catch (error) {
                                this.copied = false;
                            }
                        }
                    }"
                    class="flex justify-end mb-2"
                >
                    <div>
                        <button
                            type="button"
                            x-on:click="copy()"
                            class="inline-flex items-center px-3 py-1.5 border rounded bg-white dark:bg-gray-800 text-sm shadow-sm"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M8 2a2 2 0 00-2 2v1H5a2 2 0 00-2 2v7a2 2 0 002 2h5a2 2 0 002-2v-1h1a2 2 0 002-2V9h-1V6a2 2 0 00-2-2H8V4a2 2 0 012-2h2V2H8z"/></svg>
                            <span x-text="copied ? @js(__('Copied!')) : @js(__('Copy HTML'))">{{ __('Copy HTML') }}</span>
                        </button>
                    </div>
                </div>

                <x-filament::section heading="Raw HTML body" collapsible collapsed>
                    <pre id="raw-html-body" class="text-xs p-4 rounded bg-gray-100 dark:bg-gray-800/30 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10"><code class="block whitespace-pre-wrap break-all max-h-96 overflow-auto">{{ data_get($scrape, 'body') }}</code></pre>
                </x-filament::section>

            </div>
        @endif
        </div>
    @endif
</div>
