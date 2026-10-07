<div class="w-full">
    <x-product-card :product="$product" />
    @if ($product->tags->isNotEmpty())
        <div class="mt-1 px-2 text-xs text-gray-500">{{ $product->tags->pluck('name')->join(', ') }}</div>
    @endif
</div>
