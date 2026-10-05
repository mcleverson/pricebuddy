<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! DB::table('settings')->where('group', 'app')->where('name', 'intelligence_settings')->exists()) {
            $this->migrator->add('app.intelligence_settings', []);
        }
    }

    public function down(): void
    {
        if (DB::table('settings')->where('group', 'app')->where('name', 'intelligence_settings')->exists()) {
            $this->migrator->delete('app.intelligence_settings');
        }
    }
};
