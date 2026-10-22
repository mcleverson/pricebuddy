@php
    $brl = fn ($value) => $value === null ? '—' : 'R$ '.number_format((float) $value, 2, ',', '.');
    $carousel = $this->record;
    $statusColors = \App\Filament\Resources\CarouselResource::STATUS_COLORS;
    $labels = \App\Services\Carousels\CarouselSettings::CRITERIA_LABELS;
    [$minProducts, $maxProducts] = \App\Services\Carousels\CarouselSettings::productRange();
    $input = 'text-sm rounded-md border-gray-300 dark:bg-gray-900 dark:border-white/10';
@endphp
<x-filament-panels::page>
    <div class="flex flex-col gap-6" @if ($carousel?->status === 'discovering') wire:poll.5s @endif>
        <div class="flex flex-wrap items-center gap-3">
                <x-filament::badge :color="$statusColors[$carousel->status] ?? 'gray'">{{ $carousel->status }}</x-filament::badge>
                @if ($carousel->status === 'discovering')
                    <span class="text-sm text-gray-500">{{ __('Searching the stores; this can take several minutes per store.') }}</span>
                @endif
            </div>

            @if ($carousel->error)
                <x-filament::section><span class="text-sm text-warning-600">{{ $carousel->error }}</span></x-filament::section>
            @endif

            @if ($carousel->store_reports)
                <div class="flex flex-wrap gap-2 text-sm">
                    @foreach ($carousel->store_reports as $report)
                        <x-filament::badge :color="$report['status'] === 'completed' ? 'success' : 'danger'">
                            {{ $report['store'] }}:
                            @if ($report['status'] === 'completed') {{ $report['kept'] }} {{ __('kept of') }} {{ $report['found'] }} {{ __('found') }} @else {{ __('failed') }} @endif
                        </x-filament::badge>
                        @if ($report['error'] ?? $report['note'] ?? null)<span class="text-xs text-gray-500">{{ $report['error'] ?? $report['note'] }}</span>@endif
                    @endforeach
                </div>
            @endif

            @if ($carousel->status !== 'discovering')
                @php($selection = $carousel->selection()->with('store')->get())
                <x-filament::section>
                    <x-slot name="heading">{{ __('Final selection') }} ({{ $selection->count() }} · {{ $minProducts }}–{{ $maxProducts }})</x-slot>
                    <x-slot name="description">
                        {{ $carousel->status === 'approved'
                            ? __('Approved on').' '.$carousel->approved_at?->format('d/m/Y H:i').'. '.__('The products were added to your catalog.')
                            : __('The suggestion prefers a different kind of product per slide. Approve between :min and :max products; the Canva AI prompt is shown after approval.', ['min' => $minProducts, 'max' => $maxProducts]) }}
                    </x-slot>
                    <ol class="flex flex-col gap-2">
                        @foreach ($selection as $candidate)
                            <li class="flex items-center gap-3" wire:key="selected-{{ $candidate->id }}">
                                <span class="w-6 text-center font-bold">{{ $candidate->position }}</span>
                                @if ($candidate->image)<img src="{{ $candidate->image }}" alt="" class="w-12 h-12 object-contain rounded bg-white">@endif
                                <span class="flex-1 truncate">{{ $candidate->title }}</span>
                                <span class="text-sm text-gray-500 whitespace-nowrap">{{ $brl($candidate->price) }} @ {{ $candidate->store?->name }}</span>
                                @if ($carousel->isEditable())
                                    <x-filament::icon-button icon="heroicon-m-arrow-up" color="gray" wire:click="moveUp({{ $candidate->id }})" :label="__('Move up')" />
                                    <x-filament::icon-button icon="heroicon-m-arrow-down" color="gray" wire:click="moveDown({{ $candidate->id }})" :label="__('Move down')" />
                                    <x-filament::icon-button icon="heroicon-m-x-mark" color="danger" wire:click="unselect({{ $candidate->id }})" :label="__('Remove')" />
                                @elseif ($candidate->product_id)
                                    <a class="text-sm hover:underline" href="{{ \App\Filament\Resources\ProductResource::getUrl('view', ['record' => $candidate->product_id]) }}">{{ __('Product') }} #{{ $candidate->product_id }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                    @if ($carousel->isEditable())
                        <div class="mt-4">
                            <x-filament::button icon="heroicon-m-check" wire:click="approve" :disabled="$selection->count() < $minProducts || $selection->count() > $maxProducts"
                                wire:confirm="{{ __('Approve these :count products? They will be added to your catalog and the selection can no longer change (use Duplicate to make another carousel from them).', ['count' => $selection->count()]) }}">
                                {{ __('Approve final selection') }}
                            </x-filament::button>
                        </div>
                    @endif
                </x-filament::section>

                @if ($carousel->status === 'approved')
                    @include('filament.resources.carousel-resource.pages.partials.canva-prompt')
                @endif

                @php($candidates = $this->candidates())
                @php($storeOptions = $carousel->candidates()->with('store')->get()->pluck('store.name', 'store_id'))
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <select wire:model.live="storeFilter" class="{{ $input }}">
                        <option value="">{{ __('All stores') }}</option>
                        @foreach ($storeOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="showRejected" class="rounded border-gray-300"> {{ __('Show rejected') }}
                    </label>
                    <span class="text-gray-500">{{ $candidates->count() }} {{ __('candidates') }} · {{ __('Scores are the AI provider\'s opinion from the title, rating and sales only; images were not analyzed.') }}</span>
                </div>

                @if ($carousel->isEditable())
                    <form wire:submit="addByUrl" class="flex flex-wrap items-center gap-2 text-sm">
                        <input type="url" wire:model="manualUrl" placeholder="{{ __('Product URL from a registered store') }}" class="{{ $input }} flex-1 min-w-64">
                        <x-filament::button type="submit" size="sm" icon="heroicon-m-plus" wire:loading.attr="disabled" wire:target="addByUrl">{{ __('Add by URL') }}</x-filament::button>
                        <span wire:loading wire:target="addByUrl" class="text-gray-500">{{ __('Reading the product page…') }}</span>
                        @error('manualUrl')<span class="text-danger-600">{{ $message }}</span>@enderror
                    </form>
                @endif

                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @forelse ($candidates as $candidate)
                        <x-filament::section wire:key="candidate-{{ $candidate->id }}">
                            <div class="flex gap-3">
                                @if ($candidate->image)
                                    <img src="{{ $candidate->image }}" alt="" class="w-24 h-24 object-contain rounded-md bg-white">
                                @endif
                                <div class="flex-1 min-w-0">
                                    <a href="{{ $candidate->url }}" target="_blank" rel="noopener noreferrer" class="font-semibold line-clamp-2 hover:underline">{{ $candidate->title }}</a>
                                    <div class="text-sm mt-1">
                                        <span class="font-bold">{{ $brl($candidate->price) }}</span>
                                        <span class="text-gray-500">@ {{ $candidate->store?->name }}</span>
                                        @if ($candidate->rating !== null)<span class="text-gray-500">· ★ {{ $candidate->rating }}</span>@endif
                                        @if ($candidate->sales_count !== null)<span class="text-gray-500">· {{ number_format($candidate->sales_count, 0, ',', '.') }} {{ __('sold') }}</span>@endif
                                    </div>
                                    <div class="flex flex-wrap gap-1 mt-1">
                                        @if ($candidate->score !== null)
                                            <x-filament::badge :color="$candidate->score >= \App\Services\Carousels\CarouselSettings::get('min_score') ? 'success' : 'gray'">{{ __('Score') }} {{ (int) $candidate->score }}</x-filament::badge>
                                        @endif
                                        @if ($candidate->status === 'unscored')<x-filament::badge color="warning">{{ __('Not scored') }}</x-filament::badge>@endif
                                        @if ($candidate->status === 'rejected')<x-filament::badge color="danger">{{ __('Rejected') }}</x-filament::badge>@endif
                                        <x-filament::badge color="gray">{{ $this->affiliateStatus($candidate) }}</x-filament::badge>
                                        <x-filament::badge color="gray">{{ __('Availability not verified') }}</x-filament::badge>
                                        @if ($candidate->product_type)<x-filament::badge color="info">{{ $candidate->product_type }}</x-filament::badge>@endif
                                        @if ($candidate->source === \App\Models\CarouselCandidate::SOURCE_MANUAL)<x-filament::badge color="gray">{{ __('Added by URL') }}</x-filament::badge>@endif
                                    </div>
                                </div>
                            </div>
                            @if ($candidate->description)<p class="text-sm mt-2">{{ $candidate->description }}</p>@endif
                            @if ($candidate->reasons)
                                <ul class="text-sm list-disc ml-5 mt-1">@foreach ($candidate->reasons as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                            @endif
                            @if ($candidate->scores)
                                <details class="mt-2 text-xs text-gray-500">
                                    <summary class="cursor-pointer">{{ __('Score details') }}</summary>
                                    <ul class="mt-1">
                                        @foreach ($labels as $criterion => $label)
                                            <li>{{ $label }}: {{ $candidate->scores[$criterion] ?? __('not scored (image not analyzed)') }}</li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                            @if ($carousel->isEditable())
                                <div class="flex gap-2 mt-3">
                                    @if ($candidate->position)
                                        <x-filament::button size="xs" color="gray" icon="heroicon-m-minus" wire:click="unselect({{ $candidate->id }})">{{ __('Selected') }} #{{ $candidate->position }}</x-filament::button>
                                    @elseif ($candidate->status !== 'rejected')
                                        <x-filament::button size="xs" icon="heroicon-m-plus" wire:click="select({{ $candidate->id }})">{{ __('Select') }}</x-filament::button>
                                    @endif
                                    @if ($candidate->status === 'rejected')
                                        <x-filament::button size="xs" color="gray" wire:click="restore({{ $candidate->id }})">{{ __('Restore') }}</x-filament::button>
                                    @else
                                        <x-filament::button size="xs" color="danger" icon="heroicon-m-x-mark" wire:click="reject({{ $candidate->id }})">{{ __('Reject') }}</x-filament::button>
                                    @endif
                                </div>
                            @endif
                        </x-filament::section>
                    @empty
                        <x-filament::section>{{ __('No candidates to show.') }}</x-filament::section>
                    @endforelse
                </div>
            @endif
    </div>
</x-filament-panels::page>
