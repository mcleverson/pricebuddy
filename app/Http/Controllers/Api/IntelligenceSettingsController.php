<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationMethods;
use App\Http\Controllers\Controller;
use App\Services\Helpers\NotificationsHelper;
use App\Settings\AppSettings;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Intelligence')]
class IntelligenceSettingsController extends Controller
{
    /**
     * Settings > Intelligence, for the pricebuddy-intelligence service.
     *
     * Blank fields are omitted so the service keeps its own defaults. The
     * Telegram bot is the one configured in Settings > Notifications.
     */
    public function __invoke(): JsonResponse
    {
        $settings = array_filter(
            (array) AppSettings::new()->intelligence_settings,
            fn (mixed $value): bool => $value !== null && $value !== '',
        );

        unset($settings['telegram_bot_token'], $settings['telegram_enabled'], $settings['message_prompt']);

        $botToken = NotificationsHelper::getSetting(NotificationMethods::Telegram, 'bot_token');
        if (filled($botToken)) {
            $settings['telegram_bot_token'] = $botToken;
        }

        return response()->json(['data' => $settings]);
    }
}
