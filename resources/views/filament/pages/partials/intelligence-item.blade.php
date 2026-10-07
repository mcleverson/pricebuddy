{{-- One analyzed (or pending) product on the Intelligence page. --}}
@php($p = $item['prices'])
<x-filament::section wire:key="intel-{{ $inSelection ? 'selection' : 'today' }}-{{ $item['product_id'] }}">
    <div class="flex flex-col md:flex-row gap-4">
        @if ($item['image'] ?? null)
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
                @if ($p['commission'] ?? null)
                    <span class="text-success-600 ml-2">· {{ __('commission') }} {{ round($p['commission'], 1) }}% ({{ $brl($p['offer'] * $p['commission'] / 100) }})@if ($p['seller_commission'] ?? null), {{ round($p['seller_commission'], 1) }}% {{ __('from the seller') }}@endif</span>
                @endif
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
            <x-filament::button size="sm" color="gray" icon="heroicon-m-arrow-path" wire:click="analyze({{ $item['product_id'] }})">{{ $item['analyzed_at'] ? __('Re-analyze') : __('Analyze') }}</x-filament::button>
            @if ($inSelection)
                <x-filament::button size="sm" color="gray" icon="heroicon-m-x-mark" wire:click="removeFromSelection({{ $item['product_id'] }})">{{ __('Remove from selection') }}</x-filament::button>
            @endif
            <span class="text-xs text-gray-500">
                {{ $item['analyzed_at'] ? __('Analyzed').' '.\Illuminate\Support\Carbon::parse($item['analyzed_at'])->format('d/m H:i') : __('Waiting for analysis') }}
            </span>
        </div>
    </div>
</x-filament::section>
