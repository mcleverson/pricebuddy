{{-- The prompt to paste into Canva AI and the product photos it refers to. --}}
@php
    $profiles = \App\Services\Carousels\CarouselPrompts::profiles();
    $profile = \App\Services\Carousels\CarouselPrompts::profile($carousel->profile_id);
@endphp
<x-filament::section>
    <x-slot name="heading">{{ __('Canva AI') }}</x-slot>
    <x-slot name="description">{{ __('Download the product photos, upload them to Canva, then paste this prompt in Canva AI. The style comes from the profile, so every carousel of a profile looks alike; adjust the result in Canva.') }}</x-slot>

    <div class="flex flex-col gap-4">
        <div class="grid gap-3 md:grid-cols-3">
            <label class="flex flex-col text-xs text-gray-500">{{ __('Profile') }}
                <select wire:model.live="profileId" class="{{ $input }}">
                    <option value="">{{ __('No profile (generic style)') }}</option>
                    @foreach ($profiles as $item)<option value="{{ $item['id'] }}">{{ $item['name'] }}</option>@endforeach
                </select>
                @if (! $profiles)<span class="mt-1">{{ __('Create profiles in Settings > Carousels.') }}</span>@endif
            </label>
            <label class="flex flex-col text-xs text-gray-500">{{ __('Cover headline') }}
                <input type="text" wire:model.blur="headline" maxlength="255" class="{{ $input }}">
            </label>
            <label class="flex flex-col text-xs text-gray-500">{{ __('Call to action') }}
                <input type="text" wire:model.blur="cta" maxlength="255" class="{{ $input }}" placeholder="{{ $profile['default_cta'] ?? __('e.g. Links na bio') }}">
            </label>
        </div>
        <div class="flex flex-col gap-2">
            <div class="flex flex-wrap items-center gap-2">
                <x-filament::button size="sm" color="gray" icon="heroicon-m-sparkles" wire:click="generateTexts">
                    {{ __('Generate headline and call to action') }}
                </x-filament::button>
                <span wire:loading wire:target="generateTexts" class="text-xs text-gray-500">{{ __('Generating…') }}</span>
            </div>
            @if ($this->headlineOptions)
                <div class="flex flex-wrap gap-2">
                    @foreach ($this->headlineOptions as $index => $option)
                        <x-filament::badge :color="$option === $this->headline ? 'primary' : 'gray'" class="cursor-pointer" wire:click="pickHeadline({{ $index }})">{{ $option }}</x-filament::badge>
                    @endforeach
                </div>
            @endif
        </div>
        @error('profileId')<span class="text-sm text-danger-600">{{ $message }}</span>@enderror
        @if ($profile && blank($profile['style'] ?? null))
            <span class="text-sm text-warning-600">{{ __('This profile has no style guide yet; generate one in Settings > Carousels.') }}</span>
        @endif

        <div x-data="{ copied: false }" class="flex flex-col gap-2">
            <div class="flex flex-wrap items-center gap-2">
                <x-filament::button size="sm" icon="heroicon-m-clipboard"
                    x-on:click="navigator.clipboard.writeText($refs.prompt.innerText); copied = true; setTimeout(() => copied = false, 1500)">
                    <span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy prompt') }}'"></span>
                </x-filament::button>
                <x-filament::button size="sm" color="gray" icon="heroicon-m-arrow-down-tray" wire:click="downloadImages">
                    {{ __('Download product photos') }}
                </x-filament::button>
                <span wire:loading wire:target="downloadImages" class="text-xs text-gray-500">{{ __('Downloading…') }}</span>
            </div>
            <p x-ref="prompt" class="text-sm whitespace-pre-line rounded-md bg-gray-50 p-3 dark:bg-white/5">{{ $this->prompt() }}</p>
        </div>
    </div>
</x-filament::section>
