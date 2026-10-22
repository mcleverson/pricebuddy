<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A carousel is one curation run for a niche: products found in the
     * marketplaces are kept as candidates here, outside the catalog, and only
     * the approved selection is ingested as Products (product_id). `position`
     * is the slide order of a selected candidate; `store_reports` records how
     * each store's search went, so a store that found little never hides the rest.
     */
    public function up(): void
    {
        Schema::create('carousels', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('niche', 255);
            $table->string('language', 16)->nullable();
            $table->string('region', 8)->nullable();
            $table->string('status', 16);
            $table->json('store_reports')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('carousel_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carousel_id')->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Store::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Product::class)->nullable()->constrained()->nullOnDelete();
            $table->string('source', 16);
            $table->text('url');
            $table->string('url_normalized', 255);
            $table->string('affiliate_url', 2048)->nullable();
            $table->string('title', 1024);
            $table->string('image', 1024)->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('original_price', 12, 2)->nullable();
            $table->string('product_type', 120)->nullable();
            $table->json('scores')->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->json('reasons')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 16);
            $table->unsignedTinyInteger('position')->nullable();
            $table->timestamps();

            $table->unique(['carousel_id', 'url_normalized']);
            $table->index(['carousel_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carousel_candidates');
        Schema::dropIfExists('carousels');
    }
};
