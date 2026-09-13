<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Only enforced for Api-driven discovery (each niche already gets its own
     * API query there, so a floor is deterministic). Defaults to 0 (no floor,
     * today's first-come-first-served behaviour) so existing stores are
     * unaffected until someone opts in.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->unsignedTinyInteger('discovery_min_percentage_per_tag')->default(0)->after('agent_min_discount_percentage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn('discovery_min_percentage_per_tag');
        });
    }
};
