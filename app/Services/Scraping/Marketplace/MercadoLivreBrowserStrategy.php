<?php

namespace App\Services\Scraping\Marketplace;

class MercadoLivreBrowserStrategy extends AbstractMarketplaceBrowserStrategy
{
    public function key(): string
    {
        return 'mercado_livre';
    }

    public function domains(): array
    {
        return [
            'mercadolivre.com.br',
            'mercadolivre.com',
            'mercadolibre.com.br',
            'mercadolibre.com',
        ];
    }

    public function browserOptions(string $url): array
    {
        return [
            'locale' => 'pt-BR',
            'timezone' => 'America/Sao_Paulo',
        ];
    }

    public function detectBlockedResponse(array $errors, string $body, ?string $finalUrl = null): ?string
    {
        $reason = parent::detectBlockedResponse($errors, $body, $finalUrl);

        if ($reason !== null) {
            return $reason;
        }

        $candidateUrl = strtolower((string) ($finalUrl ?: ''));
        if (str_contains($candidateUrl, '/gz/account-verification')
            || str_contains(strtolower($body), '/gz/account-verification')) {
            return 'account_verification';
        }

        return null;
    }
}
