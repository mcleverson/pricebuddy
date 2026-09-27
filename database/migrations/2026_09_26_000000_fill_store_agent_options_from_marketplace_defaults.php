<?php

use App\Enums\AccessMode;
use App\Models\Store;
use App\Services\Scraping\MarketplaceStrategyResolver;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Copy each agentic Store's marketplace strategy defaults into
     * settings.agent_options, so the "Agent browser (advanced)" card shows the
     * values already in effect. Only keys editable on that card are copied,
     * and keys the Store already sets are never overwritten.
     */
    public function up(): void
    {
        $resolver = app(MarketplaceStrategyResolver::class);
        $editable = [...Store::AGENT_BOOLEAN_OPTIONS, ...Store::AGENT_INTEGER_OPTIONS, ...Store::AGENT_LIST_OPTIONS];

        Store::where('access_mode', AccessMode::Agentic->value)
            ->get()
            ->each(function (Store $store) use ($resolver, $editable) {
                $url = collect((array) $store->agent_urls)->pluck('url')->filter()->first();

                if (! $url) {
                    return;
                }

                $defaults = array_intersect_key(
                    $resolver->resolve($url)->agentOptions($url),
                    array_flip($editable),
                );

                if ($defaults === []) {
                    return;
                }

                $settings = (array) $store->settings;
                $current = (array) ($settings['agent_options'] ?? []);

                foreach ($defaults as $key => $value) {
                    if (($current[$key] ?? null) !== null && ($current[$key] ?? null) !== '') {
                        continue;
                    }

                    // The card's Yes/No selects use '1'/'0' option keys.
                    $current[$key] = is_bool($value) ? ($value ? '1' : '0') : $value;
                }

                $settings['agent_options'] = $current;
                $store->update(['settings' => $settings]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
