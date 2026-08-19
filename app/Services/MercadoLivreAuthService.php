<?php

namespace App\Services;

use App\Services\Helpers\SettingsHelper;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MercadoLivreAuthService
{
    public function __construct(
        protected string $authBaseUrl = 'https://auth.mercadolivre.com.br',
        protected string $apiBaseUrl = 'https://api.mercadolibre.com',
    ) {}

    public static function new(): self
    {
        return resolve(static::class);
    }

    public function getAuthorizationUrl(): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
        ]);

        return rtrim($this->authBaseUrl, '/').'/authorization?'.$query;
    }

    public function exchangeCode(string $code): void
    {
        $this->requestTokens([
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ]);
    }

    public function getAccessToken(): string
    {
        $tokens = $this->getTokens();

        $accessToken = $tokens['access_token'] ?? null;
        $refreshToken = $tokens['refresh_token'] ?? null;
        $expiresAt = $tokens['expires_at'] ?? null;

        if (empty($accessToken) && empty($refreshToken)) {
            throw new RuntimeException('Mercado Livre is not authorized. Please visit /mercado-livre/auth to authorize the application.');
        }

        if ($this->tokenIsValid($expiresAt)) {
            return $accessToken;
        }

        if (empty($refreshToken)) {
            throw new RuntimeException('Mercado Livre access token has expired and no refresh token is available. Please re-authorize the application.');
        }

        $this->refreshAccessToken($refreshToken);

        return $this->getTokens()['access_token'];
    }

    public function refreshAccessToken(string $refreshToken): void
    {
        $this->requestTokens([
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'refresh_token' => $refreshToken,
        ]);
    }

    protected function requestTokens(array $payload): void
    {
        try {
            $response = Http::timeout(10)
                ->asForm()
                ->post(rtrim($this->apiBaseUrl, '/').'/oauth/token', $payload)
                ->throw();
        } catch (ConnectionException $e) {
            Log::error('Mercado Livre OAuth request timed out', [
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Mercado Livre OAuth request timed out. Please try again later.');
        } catch (RequestException $e) {
            $status = $e->response?->status();

            Log::error('Mercado Livre OAuth request failed', [
                'status' => $status,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException("Mercado Livre OAuth request failed with status {$status}.");
        } catch (Throwable $e) {
            Log::error('Mercado Livre OAuth request failed', [
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Mercado Livre OAuth request failed.');
        }

        $data = $response->json();

        if (! is_array($data) || empty($data['access_token']) || empty($data['refresh_token']) || ! isset($data['expires_in'])) {
            Log::error('Mercado Livre OAuth response is invalid');

            throw new RuntimeException('Mercado Livre OAuth response is invalid.');
        }

        $this->persistTokens(
            $data['access_token'],
            $data['refresh_token'],
            (int) $data['expires_in']
        );
    }

    protected function persistTokens(string $accessToken, string $refreshToken, int $expiresIn): void
    {
        $expiresAt = Carbon::now()->addSeconds($expiresIn)->toDateTimeString();

        SettingsHelper::setSetting('mercado_livre', [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => $expiresAt,
        ]);
    }

    protected function getTokens(): array
    {
        return SettingsHelper::getSetting('mercado_livre', [
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
        ]);
    }

    protected function tokenIsValid(?string $expiresAt): bool
    {
        if (empty($expiresAt)) {
            return false;
        }

        try {
            return Carbon::parse($expiresAt)->subMinutes(1)->isFuture();
        } catch (Throwable) {
            return false;
        }
    }

    protected function clientId(): string
    {
        return (string) config('services.mercado_livre.client_id');
    }

    protected function clientSecret(): string
    {
        return (string) config('services.mercado_livre.client_secret');
    }

    protected function redirectUri(): string
    {
        return (string) config('services.mercado_livre.redirect_uri');
    }
}
