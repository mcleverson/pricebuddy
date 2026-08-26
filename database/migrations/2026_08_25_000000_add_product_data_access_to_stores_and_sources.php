<?php

use App\Enums\AccessMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->string('marketplace_id')->nullable()->after('name');
            $table->string('access_mode')->default(AccessMode::Scraping->value)->after('marketplace_id');
        });

        Schema::table('product_sources', function (Blueprint $table): void {
            $table->string('marketplace_id')->nullable()->after('name');
            $table->string('access_mode')->default(AccessMode::Scraping->value)->after('marketplace_id');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn(['marketplace_id', 'access_mode']);
        });

        Schema::table('product_sources', function (Blueprint $table): void {
            $table->dropColumn(['marketplace_id', 'access_mode']);
        });
    }
};
