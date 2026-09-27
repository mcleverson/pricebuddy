<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Relevance profiles guide Hermes on which discovered candidates to admit:
     * tags.relevance_profile describes a niche (desired types, brands, terms,
     * exclusions, examples) and stores.discovery_profile holds the strategy's
     * own customizations on top of it. Both are nullable so existing tags and
     * stores keep today's behavior until someone fills them in.
     */
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table): void {
            $table->json('relevance_profile')->nullable()->after('weight');
        });

        Schema::table('stores', function (Blueprint $table): void {
            $table->json('discovery_profile')->nullable()->after('discovery_min_rating');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table): void {
            $table->dropColumn('relevance_profile');
        });

        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn('discovery_profile');
        });
    }
};
