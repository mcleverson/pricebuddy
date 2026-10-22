<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelSettings\Migrations\SettingsMigrator;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A carousel follows one profile from Settings > Carousels (its style
     * guide) and has the cover headline and closing call to action used in
     * the Canva AI prompt.
     *
     * A database that ran the earlier, unreleased Canva API migrations still
     * has their table, columns and Canva credentials in Settings > Carousels,
     * so they are dropped when present and the statuses they introduced go
     * back to approved.
     */
    public function up(): void
    {
        Schema::dropIfExists('canva_connections');

        if (DB::table('settings')->where('group', 'app')->where('name', 'carousel_settings')->exists()) {
            app(SettingsMigrator::class)->update('app.carousel_settings', fn ($settings): array => Arr::except(
                (array) $settings,
                ['canva-heading', 'canva_client_id', 'canva_client_secret', 'canva_redirect_uri'],
            ));
        }

        $legacy = array_values(array_filter(
            ['canva_brand_template_id', 'canva_design_id', 'canva_edit_url', 'exports', 'generated_at', 'scene', 'cover_texts', 'cover_media', 'cta_media'],
            fn (string $column): bool => Schema::hasColumn('carousels', $column),
        ));
        if ($legacy !== []) {
            Schema::table('carousels', fn (Blueprint $table) => $table->dropColumn($legacy));
        }
        if (Schema::hasColumn('carousel_candidates', 'media')) {
            Schema::table('carousel_candidates', fn (Blueprint $table) => $table->dropColumn('media'));
        }
        DB::table('carousels')->whereIn('status', ['generating', 'generated'])->update(['status' => 'approved']);

        Schema::table('carousels', function (Blueprint $table) {
            if (! Schema::hasColumn('carousels', 'profile_id')) {
                $table->string('profile_id', 26)->nullable()->after('region');
            }
            if (! Schema::hasColumn('carousels', 'headline')) {
                $table->string('headline', 255)->nullable()->after('profile_id');
            }
            if (! Schema::hasColumn('carousels', 'cta')) {
                $table->string('cta', 255)->nullable()->after('headline');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carousels', function (Blueprint $table) {
            $table->dropColumn(['profile_id', 'headline', 'cta']);
        });
    }
};
