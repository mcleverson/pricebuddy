<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! DB::table('settings')->where('group', 'app')->where('name', 'carousel_settings')->exists()) {
            $this->migrator->add('app.carousel_settings', []);
        }
    }

    public function down(): void
    {
        if (DB::table('settings')->where('group', 'app')->where('name', 'carousel_settings')->exists()) {
            $this->migrator->delete('app.carousel_settings');
        }
    }
};
