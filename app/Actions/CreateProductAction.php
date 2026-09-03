<?php

namespace App\Actions;

use App\Models\Product;
use App\Services\ScrapeUrl;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateProductAction
{
    public function __invoke(array $attributes): Product
    {
        if (auth()->guest()) {
            throw new AuthorizationException('User is required to create a product.');
        }

        if (empty(data_get($attributes, 'title'))) {
            throw new InvalidArgumentException('Product title is required.');
        }

        $image = data_get($attributes, 'image');
        $userId = data_get($attributes, 'user_id', auth()->id());

        return Product::create(array_merge($attributes, [
            'title' => Str::limit(data_get($attributes, 'title'), ScrapeUrl::MAX_STR_LENGTH),
            'image' => $image ? Str::limit($image, ScrapeUrl::MAX_STR_LENGTH - 1, '') : null,
            'user_id' => $userId,
            'favourite' => true,
        ]));
    }
}
