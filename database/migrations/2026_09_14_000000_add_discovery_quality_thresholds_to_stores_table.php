<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Shared by both discovery paths: Api providers (e.g. Shopee) read these
     * against the marketplace's own sales/rating fields, while Hermes
     * (Agentic) has the LLM report the same signals when visibly shown on
     * the page. Defaults to 0 (no minimum) so existing stores are unaffected
     * until someone opts in via the Store form.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->unsignedInteger('discovery_min_sales')->default(0)->after('discovery_min_percentage_per_tag');
            $table->decimal('discovery_min_rating', 3, 2)->default(0)->after('discovery_min_sales');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn(['discovery_min_sales', 'discovery_min_rating']);
        });
    }
};
