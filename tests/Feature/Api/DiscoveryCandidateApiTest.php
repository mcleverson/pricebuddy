<?php

namespace Tests\Feature\Api;

use App\Enums\ApiAbility;
use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tag;
use App\Models\Url;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiscoveryCandidateApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'domains' => [['domain' => 'example.com']],
        ]);

        $token = $this->user->createToken(
            'discovery-test',
            [ApiAbility::DiscoveryCandidatesIngest->value],
        )->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$token]);
    }

    public function test_can_ingest_a_candidate_with_optional_fields(): void
    {
        $response = $this->postJson('/api/discovery/candidates', [
            'url' => 'https://example.com/product-123',
            'title' => 'Example product',
            'price' => 99.90,
            'original_price' => 129.90,
            'image' => 'https://example.com/product.jpg',
            'store_id' => $this->store->id,
            'rating' => 4.5,
            'seller' => 'Example seller',
            'external_id' => 'external-123',
            'source' => 'hermes',
        ]);

        $response->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('message', 'Product candidate ingested')
            ->assertJsonPath('data.title', 'Example product');

        $product = Product::query()->where('title', 'Example product')->firstOrFail();
        $url = $product->urls()->firstOrFail();
        $price = $url->prices()->firstOrFail();

        $this->assertSame($this->user->id, $product->user_id);
        $this->assertSame($this->store->id, $url->store_id);
        $this->assertSame(99.9, (float) $price->price);
        $this->assertSame(129.9, (float) $price->original_price);
    }

    public function test_candidate_can_be_injected_with_tags(): void
    {
        $response = $this->postJson('/api/discovery/candidates', [
            'url' => 'https://example.com/tagged-product',
            'title' => 'Tagged product',
            'price' => 49.90,
            'store_id' => $this->store->id,
            'tags' => ['eletronicos'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('data.title', 'Tagged product');

        $product = Product::query()->where('title', 'Tagged product')->firstOrFail();
        $this->assertTrue($product->tags->contains('name', 'eletronicos'));
        $this->assertDatabaseHas('tags', [
            'name' => 'eletronicos',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_price_cache_is_built_after_candidate_ingestion(): void
    {
        $this->postJson('/api/discovery/candidates', [
            'url' => 'https://example.com/cached-product',
            'title' => 'Cached product',
            'price' => 199.90,
            'original_price' => 299.90,
            'store_id' => $this->store->id,
        ])->assertCreated();

        $product = Product::query()->where('title', 'Cached product')->firstOrFail();
        $this->assertNotNull($product->price_cache);
        $this->assertNotEmpty($product->price_cache);
        $this->assertSame(199.9, (float) $product->getPriceCache()->first()->getPrice());
        $this->assertSame(299.9, (float) $product->getPriceCache()->first()->getOriginalPrice());
    }

    public function test_optional_fields_can_be_absent(): void
    {
        $response = $this->postJson('/api/discovery/candidates', [
            'url' => 'https://example.com/minimal-product',
            'title' => 'Minimal product',
            'price' => 10,
            'store_id' => $this->store->id,
        ]);

        $response->assertCreated()->assertJsonPath('created', true);

        $this->assertDatabaseHas('products', ['title' => 'Minimal product']);
        $this->assertDatabaseHas('prices', [
            'price' => 10,
            'original_price' => null,
        ]);
    }

    public function test_repeated_candidate_does_not_create_a_duplicate_product(): void
    {
        Http::fake();

        $payload = [
            'url' => 'https://www.example.com/product-123?utm_source=hermes',
            'title' => 'Example product',
            'price' => 99.90,
            'original_price' => 129.90,
            'store_id' => $this->store->id,
        ];

        $this->postJson('/api/discovery/candidates', $payload)->assertCreated();
        $this->postJson('/api/discovery/candidates', [
            ...$payload,
            'url' => 'https://example.com/product-123',
            'price' => 79.90,
            'original_price' => 99.90,
        ])->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('message', 'Product already exists');

        $this->assertSame(1, Product::query()->where('user_id', $this->user->id)->count());
        $this->assertSame(1, Url::query()->where('url_normalized', 'example.com/product-123')->count());
        $this->assertSame(2, Price::query()->count());
        $this->assertDatabaseHas('prices', [
            'price' => 79.90,
            'original_price' => 99.90,
        ]);
        Http::assertNothingSent();
    }

    public function test_invalid_candidate_is_rejected(): void
    {
        $response = $this->postJson('/api/discovery/candidates', [
            'url' => 'not-a-url',
            'price' => -1,
            'store_id' => $this->store->id,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['url', 'title', 'price']);
    }

    public function test_endpoint_requires_authentication(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer invalid-token'])
            ->postJson('/api/discovery/candidates', []);

        $response->assertUnauthorized();
    }

    public function test_endpoint_requires_the_discovery_ability(): void
    {
        $token = $this->user->createToken('wrong-scope', [ApiAbility::UserDetail->value])->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/discovery/candidates', []);

        $response->assertForbidden();
    }

    public function test_existing_product_and_new_url_attach_to_the_same_product(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);

        $response = $this->postJson('/api/discovery/candidates', [
            'url' => 'https://example.com/another-product-page',
            'title' => 'External title',
            'price' => 49.90,
            'store_id' => $this->store->id,
            'product_id' => $product->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('url_created', true)
            ->assertJsonPath('data.id', $product->id);

        $this->assertSame(1, Product::query()->where('user_id', $this->user->id)->count());
        $this->assertSame(1, $product->fresh()->urls()->count());
    }

    public function test_existing_product_and_existing_url_record_a_new_price(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $url = Url::factory()->create([
            'product_id' => $product->id,
            'store_id' => $this->store->id,
            'url' => 'https://example.com/existing-product-page',
        ]);
        $url->updatePrice(100, ['original_price' => 150]);

        $response = $this->postJson('/api/discovery/candidates', [
            'url' => 'https://www.example.com/existing-product-page?utm_source=hermes',
            'title' => 'Updated external title',
            'price' => 75,
            'original_price' => 120,
            'store_id' => $this->store->id,
            'product_id' => $product->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('url_created', false)
            ->assertJsonPath('data.id', $product->id);

        $this->assertSame(1, $product->fresh()->urls()->count());
        $this->assertSame(2, $url->prices()->count());
        $this->assertDatabaseHas('prices', [
            'url_id' => $url->id,
            'price' => 75,
            'original_price' => 120,
        ]);
    }

    public function test_two_different_urls_with_the_same_product_id_create_one_product(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);

        foreach (['first-page', 'second-page'] as $page) {
            $this->postJson('/api/discovery/candidates', [
                'url' => "https://example.com/{$page}",
                'title' => 'Same product',
                'price' => 20,
                'store_id' => $this->store->id,
                'product_id' => $product->id,
            ])->assertOk()
                ->assertJsonPath('created', false)
                ->assertJsonPath('url_created', true);
        }

        $this->assertSame(1, Product::query()->where('user_id', $this->user->id)->count());
        $this->assertSame(2, $product->fresh()->urls()->count());
    }

    public function test_product_id_from_another_user_is_rejected(): void
    {
        $otherProduct = Product::factory()->create();

        $response = $this->postJson('/api/discovery/candidates', [
            'url' => 'https://example.com/other-user-product',
            'title' => 'Product',
            'price' => 20,
            'store_id' => $this->store->id,
            'product_id' => $otherProduct->id,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['product_id']);
    }

    public function test_nonexistent_product_id_is_rejected(): void
    {
        $response = $this->postJson('/api/discovery/candidates', [
            'url' => 'https://example.com/missing-product',
            'title' => 'Product',
            'price' => 20,
            'store_id' => $this->store->id,
            'product_id' => 999999,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['product_id']);
    }

    public function test_url_belonging_to_another_product_is_not_reassigned(): void
    {
        $owner = Product::factory()->create(['user_id' => $this->user->id]);
        $target = Product::factory()->create(['user_id' => $this->user->id]);
        $url = Url::factory()->create([
            'product_id' => $owner->id,
            'store_id' => $this->store->id,
            'url' => 'https://example.com/shared-product-page',
        ]);

        $response = $this->postJson('/api/discovery/candidates', [
            'url' => 'https://www.example.com/shared-product-page/',
            'title' => 'Product',
            'price' => 20,
            'store_id' => $this->store->id,
            'product_id' => $target->id,
        ]);

        $response->assertStatus(409);
        $this->assertSame($owner->id, $url->fresh()->product_id);
        $this->assertSame(0, $url->prices()->count());
    }

    public function test_product_id_ingestion_does_not_fetch_the_page(): void
    {
        Http::fake();
        $product = Product::factory()->create(['user_id' => $this->user->id]);

        $this->postJson('/api/discovery/candidates', [
            'url' => 'https://example.com/no-fetch',
            'title' => 'External product',
            'price' => 30,
            'store_id' => $this->store->id,
            'product_id' => $product->id,
        ])->assertOk();

        Http::assertNothingSent();
    }
}
