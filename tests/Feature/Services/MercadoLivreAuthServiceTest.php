<?php

namespace Tests\Feature\Services;

use App\Services\Helpers\SettingsHelper;
use App\Services\MercadoLivreAuthService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class MercadoLivreAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.mercado_livre.client_id', 'test-client-id');
        config()->set('services.mercado_livre.client_secret', 'test-client-secret');
        config()->set('services.mercado_livre.redirect_uri', 'http://localhost/mercado-livre/callback');
    }

    public function test_valid_token_is_returned_without_refresh(): void
    {
        SettingsHelper::setSetting('mercado_livre', [
            'access_token' => 'valid-access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => Carbon::now()->addHour()->toDateTimeString(),
        ]);

        Http::fake([]);

        $service = MercadoLivreAuthService::new();
        $token = $service->getAccessToken();

        $this->assertSame('valid-access-token', $token);
        Http::assertNothingSent();
    }

    public function test_expired_token_refreshes_using_refresh_token(): void
    {
        SettingsHelper::setSetting('mercado_livre', [
            'access_token' => 'expired-access-token',
            'refresh_token' => 'old-refresh-token',
            'expires_at' => Carbon::now()->subHour()->toDateTimeString(),
        ]);

        Http::fake([
            'https://api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 21600,
            ]),
        ]);

        $service = MercadoLivreAuthService::new();
        $token = $service->getAccessToken();

        $this->assertSame('new-access-token', $token);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.mercadolibre.com/oauth/token'
                && $request->data()['grant_type'] === 'refresh_token'
                && $request->data()['refresh_token'] === 'old-refresh-token';
        });
    }

    public function test_refresh_response_is_persisted_atomically(): void
    {
        SettingsHelper::setSetting('mercado_livre', [
            'access_token' => 'expired-access-token',
            'refresh_token' => 'old-refresh-token',
            'expires_at' => Carbon::now()->subHour()->toDateTimeString(),
        ]);

        Http::fake([
            'https://api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 21600,
            ]),
        ]);

        MercadoLivreAuthService::new()->getAccessToken();

        $tokens = SettingsHelper::getSetting('mercado_livre');

        $this->assertSame('new-access-token', $tokens['access_token']);
        $this->assertSame('new-refresh-token', $tokens['refresh_token']);
        $this->assertNotNull($tokens['expires_at']);
    }

    public function test_throws_when_not_authorized(): void
    {
        SettingsHelper::setSetting('mercado_livre', [
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
        ]);

        $this->expectException(RuntimeException::class);

        MercadoLivreAuthService::new()->getAccessToken();
    }

    public function test_exchanges_authorization_code_for_tokens(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'code-access-token',
                'refresh_token' => 'code-refresh-token',
                'expires_in' => 21600,
            ]),
        ]);

        $service = MercadoLivreAuthService::new();
        $service->exchangeCode('authorization-code');

        $tokens = SettingsHelper::getSetting('mercado_livre');

        $this->assertSame('code-access-token', $tokens['access_token']);
        $this->assertSame('code-refresh-token', $tokens['refresh_token']);
        $this->assertNotNull($tokens['expires_at']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.mercadolibre.com/oauth/token'
                && $request->data()['grant_type'] === 'authorization_code'
                && $request->data()['code'] === 'authorization-code';
        });
    }
}
