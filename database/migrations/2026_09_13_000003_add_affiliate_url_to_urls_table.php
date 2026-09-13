<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Caches the affiliate link for marketplaces where a valid link can only be
     * obtained by calling their API (e.g. Shopee), so Url::buyUrl doesn't need a
     * live network call on every page view. Refreshed opportunistically inside
     * Url::scrape(), the same cadence that already refreshes price.
     */
    public function up(): void
    {
        Schema::table('urls', function (Blueprint $table): void {
            $table->string('affiliate_url', 2048)->nullable()->after('url');
            $table->timestamp('affiliate_url_synced_at')->nullable()->after('affiliate_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('urls', function (Blueprint $table): void {
            $table->dropColumn(['affiliate_url', 'affiliate_url_synced_at']);
        });
    }
};
