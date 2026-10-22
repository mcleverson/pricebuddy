<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Star rating and sales count seen on the marketplace, so the editorial
     * score can judge proven demand instead of guessing it from the title.
     */
    public function up(): void
    {
        Schema::table('carousel_candidates', function (Blueprint $table) {
            if (! Schema::hasColumn('carousel_candidates', 'rating')) {
                $table->decimal('rating', 3, 2)->nullable()->after('original_price');
            }
            if (! Schema::hasColumn('carousel_candidates', 'sales_count')) {
                $table->unsignedInteger('sales_count')->nullable()->after('rating');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carousel_candidates', function (Blueprint $table) {
            $table->dropColumn(['rating', 'sales_count']);
        });
    }
};
