<?php

use App\Enums\AccessMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Agent Strategy used to be a standalone screen/table. Since a Store can only ever
     * run one access strategy at a time (api, scraping or agentic), its fields are folded
     * directly into the stores table and the agent_strategies/agent_strategy_tag tables
     * are retired.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->json('agent_urls')->nullable()->after('settings');
            $table->unsignedInteger('agent_max_products')->default(10)->after('agent_urls');
            $table->decimal('agent_min_discount_percentage', 5, 2)->default(20.00)->after('agent_max_products');
        });

        Schema::create('store_tag', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'tag_id']);
        });

        if (Schema::hasTable('agent_strategies')) {
            // One store may only end up with one migrated strategy; if several existed
            // for the same store (shouldn't happen in practice), keep the most recent.
            $strategies = DB::table('agent_strategies')->orderByDesc('id')->get()->unique('store_id');

            foreach ($strategies as $strategy) {
                DB::table('stores')->where('id', $strategy->store_id)->update([
                    'access_mode' => AccessMode::Agentic->value,
                    'agent_urls' => $strategy->urls,
                    'agent_max_products' => $strategy->max_products,
                    'agent_min_discount_percentage' => $strategy->min_discount_percentage,
                ]);

                $tagIds = DB::table('agent_strategy_tag')
                    ->where('agent_strategy_id', $strategy->id)
                    ->pluck('tag_id');

                foreach ($tagIds as $tagId) {
                    DB::table('store_tag')->insertOrIgnore([
                        'store_id' => $strategy->store_id,
                        'tag_id' => $tagId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        Schema::dropIfExists('agent_strategy_tag');
        Schema::dropIfExists('agent_strategies');

        // 'auto' is retired; anything left on it (i.e. not migrated to 'agentic' above)
        // falls back to explicit scraping, which was its effective behaviour already
        // for every marketplace without a real API transport.
        DB::table('stores')->where('access_mode', 'auto')->update(['access_mode' => AccessMode::Scraping->value]);
    }

    /**
     * Reverse the migrations.
     *
     * Structural rollback only — strategy data folded back into stores is not
     * split back out into per-strategy rows.
     */
    public function down(): void
    {
        Schema::create('agent_strategies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('urls')->nullable();
            $table->unsignedInteger('max_products')->default(10);
            $table->decimal('min_discount_percentage', 5, 2)->default(20.00);
            $table->timestamps();
        });

        Schema::create('agent_strategy_tag', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_strategy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['agent_strategy_id', 'tag_id'], 'agent_strategy_tag_unique');
        });

        Schema::dropIfExists('store_tag');

        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn(['agent_urls', 'agent_max_products', 'agent_min_discount_percentage']);
        });
    }
};
