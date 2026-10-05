<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Settings\AppSettings;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Crypt;

#[Group('Intelligence')]
class IntelligenceSettingsController extends Controller
{
    /**
     * Settings > Intelligence, for the pricebuddy-intelligence service.
     *
     * Blank fields are omitted so the service keeps its own defaults.
     */
    public function __invoke(): JsonResponse
    {
        $settings = array_filter(
            (array) AppSettings::new()->intelligence_settings,
            fn (mixed $value): bool => $value !== null && $value !== '',
        );

        if (filled($settings['telegram_bot_token'] ?? null)) {
            try {
                $settings['telegram_bot_token'] = Crypt::decryptString($settings['telegram_bot_token']);
            } catch (DecryptException) {
                unset($settings['telegram_bot_token']);
            }
        }

        return response()->json(['data' => $settings]);
    }
}
