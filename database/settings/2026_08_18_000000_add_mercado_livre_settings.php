<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('app.mercado_livre', [
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
        ]);
    }

    public function down(): void
    {
        $this->migrator->delete('app.mercado_livre');
    }
};
