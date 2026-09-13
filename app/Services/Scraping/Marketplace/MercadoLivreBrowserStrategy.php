<?php

namespace App\Services\Scraping\Marketplace;

use App\Models\Url;
use DOMElement;
use Symfony\Component\DomCrawler\Crawler;

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

    public function agentOptions(string $url): array
    {
        return [
            ...$this->browserOptions($url),
            // Mercado Livre returns a soft block to the spoofed headless
            // browser and the real product page to Chrome running on Xvfb.
            'headless' => false,
            'native_user_agent' => true,
            'stealth_script' => false,
            // The offers page exposes dozens of product and footer links.
            // Smaller bounded observations avoid upstream LLM timeouts while
            // preserving the semantic browsing flow.
            'llm_page_segment_chars' => 4000,
            'llm_max_links_per_segment' => 30,
            // Product pages are challenged, while the structured listing
            // response still contains the image inside each product card.
            'listing_image_enrichment' => true,
            // Do not persist an incomplete discovery when the product page
            // was blocked before its declarative/accessibility image loaded.
            'require_image' => true,
        ];
    }

    public function extractListingImages(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        $images = [];
        $crawler = new Crawler($body);

        $crawler->filter('a[href]')->each(function (Crawler $link) use (&$images): void {
            $href = $link->attr('href');
            $path = is_string($href) ? (string) parse_url($href, PHP_URL_PATH) : '';

            if (! is_string($href)
                || preg_match('~/(?:p/MLB\d+|up/MLBU\d+)(?:/|$)~i', $path) !== 1) {
                return;
            }

            $image = $this->nearestProductImage($link->getNode(0));
            if ($image !== null) {
                $images[Url::normalizeForMatch($href)] = $image;
            }
        });

        return $images;
    }

    private function nearestProductImage(?\DOMNode $node): ?string
    {
        // The title link and picture are siblings inside one product card.
        // Walk only nearby ancestors so an image from another card can never
        // be associated with this URL.
        for ($depth = 0; $depth < 6 && $node !== null; $depth++, $node = $node->parentNode) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            foreach ($node->getElementsByTagName('img') as $image) {
                foreach (['src', 'data-src', 'data-lazy-src'] as $attribute) {
                    $src = trim($image->getAttribute($attribute));
                    if ($this->isMercadoLivreProductImage($src)) {
                        return $src;
                    }
                }
            }
        }

        return null;
    }

    private function isMercadoLivreProductImage(string $url): bool
    {
        if (! str_starts_with($url, 'https://')) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        return ($host === 'mlstatic.com' || str_ends_with($host, '.mlstatic.com'))
            && preg_match('~/D_[A-Z0-9_]+-[^/]+\.(?:avif|jpe?g|png|webp)$~i', $path) === 1;
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
