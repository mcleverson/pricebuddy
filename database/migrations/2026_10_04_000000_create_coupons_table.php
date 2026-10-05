<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\Tag;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Coupons collected by Hermes. `source` says where the coupon was found
     * (product_page: shown with the product during discovery) and `scope` what it applies to
     * (the whole store, some niches, or one product), so new coupon sources
     * fit without reshaping the table. Niches go through coupon_tag.
     */
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Store::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Product::class)->nullable()->constrained()->nullOnDelete();
            $table->string('source', 32);
            $table->string('scope', 16);
            $table->string('fingerprint', 64);
            $table->string('code', 100)->nullable();
            $table->string('title', 300);
            $table->text('description')->nullable();
            $table->string('discount_type', 32);
            $table->decimal('discount_value', 10, 2)->nullable();
            $table->decimal('minimum_order_value', 10, 2)->nullable();
            $table->date('valid_until')->nullable();
            $table->text('activation_url')->nullable();
            $table->json('categories')->nullable();
            $table->json('restrictions')->nullable();
            $table->text('niche_reason')->nullable();
            $table->string('status', 16);
            $table->decimal('confidence', 3, 2)->nullable();
            $table->text('source_url')->nullable();
            $table->text('evidence')->nullable();
            $table->uuid('hermes_run_id')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'source', 'fingerprint']);
            $table->index(['store_id', 'status']);
            $table->index(['status', 'valid_until']);
        });

        Schema::create('coupon_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Tag::class)->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['coupon_id', 'tag_id']);
            $table->index('tag_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coupon_tag');
        Schema::dropIfExists('coupons');
    }
};
