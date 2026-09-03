<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DiscoveryCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'url:http,https'],
            'title' => ['required', 'string', 'max:1024'],
            'price' => ['required', 'numeric', 'gt:0'],
            'original_price' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'image' => ['sometimes', 'nullable', 'url:http,https', 'max:1024'],
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'product_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('user_id', auth()->id())),
            ],

            // Tags / niche to attach to the newly created product.
            // Accepts an array of tag IDs or tag names (strings).
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['sometimes', 'nullable', 'string'],

            // Accepted for forward compatibility with external discovery clients.
            // The current schema has no fields for these values, so they are not persisted yet.
            'rating' => ['sometimes', 'nullable', 'numeric', 'between:0,5'],
            'seller' => ['sometimes', 'nullable', 'string', 'max:255'],
            'external_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
