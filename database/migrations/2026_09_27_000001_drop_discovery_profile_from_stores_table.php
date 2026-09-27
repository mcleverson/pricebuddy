<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Relevance rules moved entirely to the niche (tags.relevance_profile), so
     * the same rules apply to every store and discovery path. The store-level
     * profile duplicated them and was never filled in.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn('discovery_profile');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->json('discovery_profile')->nullable()->after('discovery_min_rating');
        });
    }
};
