<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Recreated: this migration had already run on existing databases but its
     * file was missing from the repository, so the column may already exist.
     */
    public function up(): void
    {
        if (Schema::hasColumn('prices', 'product_commission')) {
            return;
        }

        Schema::table('prices', function (Blueprint $table) {
            $table->float('product_commission')->nullable()->after('price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->dropColumn('product_commission');
        });
    }
};
