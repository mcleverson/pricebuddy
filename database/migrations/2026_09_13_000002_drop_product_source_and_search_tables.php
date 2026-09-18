<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Manual product search (Product Source, and the SearXNG-backed "Search via the
     * web" tab that shared its pipeline) is retired in favor of the Store's own
     * access_mode: discovery now happens via agentic (Hermes) or scraping.
     */
    public function up(): void
    {
        Schema::dropIfExists('product_sources');
        Schema::dropIfExists('url_research');
    }

    /**
     * Reverse the migrations.
     *
     * Structural rollback only — search cache/result data is not restored.
     */
    public function down(): void
    {
        Schema::create('product_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('search_url');
            $table->string('type');
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->json('extraction_strategy');
            $table->json('settings')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('weight')->default(0);
            $table->text('notes')->nullable();
            $table->string('marketplace_id')->nullable();
            $table->string('access_mode')->default('scraping');
            $table->timestamps();
        });

        Schema::create('url_research', function (Blueprint $table) {
            $table->id();
            $table->string('url', 2048);
            $table->longText('html')->nullable();
            $table->string('title', 2048)->nullable();
            $table->string('image', 2048)->nullable();
            $table->float('price')->nullable();
            $table->integer('store_id')->nullable();
            $table->json('strategies')->nullable();
            $table->float('execution_time')->nullable()->comment('Duration in seconds');
            $table->timestamps();
        });
    }
};
