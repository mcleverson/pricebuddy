<?php

namespace App\Services\Carousels;

use App\Enums\AiFeature;
use App\Exceptions\AiProviderException;
use App\Models\Carousel;
use App\Models\CarouselCandidate;
use App\Services\AiService;
use App\Services\Helpers\IntegrationHelper;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;
use RuntimeException;

/**
 * Profiles (Settings > Carousels > Profiles) and the prompt the user pastes
 * into Canva AI to design an approved carousel there.
 *
 * A profile's style guide is written once by the AI provider from a short
 * description and/or a reference image, then edited by the user. The Canva
 * prompt is assembled from it as plain text, so every carousel of a profile
 * gets the same style block and only its products change.
 */
class CarouselPrompts
{
    public const STYLE_INSTRUCTIONS = <<<'TEXT'
        You write the visual style guide of an Instagram profile that posts product carousels (portrait 1080x1350 pages:
        a cover, one page per product and a closing call to action). The guide is pasted into Canva AI before every
        carousel, so it must be concrete enough to produce the same look every time.
        Use the user's description and, when attached, the reference image (a post or design whose look they want).
        Describe the look, never the products of the reference image.
        The pages never show prices, stores or links: they spark curiosity so people ask for the link.
        description: the look in one or two sentences, the way the user would describe it in a few words.
        palette: 3 to 6 colors, each with a short role (background, text, accent...) and a hex code.
        typography: font style for titles and body (e.g. condensed bold sans-serif in uppercase); name a
        common Canva font when one fits.
        mood: the overall feeling in one or two sentences.
        layout: how the cover, the product pages and the closing page are composed (where the photo, title and
        text go), so every product page follows the same grid.
        imagery: background and photo treatment (cut-out product, texture, lighting, shadows).
        avoid: things that would break the style.
        Write every value in the requested language. Be specific and brief.
        TEXT;

    public const TEXTS_INSTRUCTIONS = <<<'TEXT'
        You write the texts of an Instagram carousel (also used in Reels) about one niche. Its products never show
        price, store or link: the goal is curiosity, so people comment or send a message asking for the links.
        headlines: 5 different cover phrases, each at most 8 words, that make people stop scrolling and swipe
        (curiosity, surprise, desire, a promise of discovery). Base them on the niche and the products listed; never
        invent features, prices, discounts or brands, and never mention price, store or link.
        cta: one short closing call to action asking people to comment or send a message to get the links.
        Write in the requested language.
        TEXT;

    /**
     * Profiles with an id and a name.
     *
     * @return list<array<string, mixed>>
     */
    public static function profiles(): array
    {
        return array_values(array_filter(
            (array) CarouselSettings::get('profiles'),
            fn (mixed $profile): bool => is_array($profile) && filled($profile['id'] ?? null) && filled($profile['name'] ?? null),
        ));
    }

    /**
     * @return ?array<string, mixed>
     */
    public static function profile(?string $id): ?array
    {
        return collect(self::profiles())->firstWhere('id', $id);
    }

    /**
     * Ask the AI provider chosen for "Carousel style" for a style guide and a
     * short description of the look (so a reference image alone fills both).
     *
     * @param  ?string  $imagePath  local path of a reference image; it needs a provider that reads images
     * @return array{description: string, style: string}
     *
     * @throws RuntimeException with a message for the user
     */
    public static function generateStyle(string $description, ?string $imagePath = null, ?string $mimeType = null): array
    {
        if (trim($description) === '' && $imagePath === null) {
            throw new RuntimeException('Describe the style or attach a reference image first.');
        }
        $provider = IntegrationHelper::resolveFeatureProvider(AiFeature::CarouselStyle);
        if ($provider === null) {
            throw new RuntimeException('No AI provider is enabled for Carousel style (Settings > AI).');
        }

        try {
            $result = AiService::new()->structured(
                self::STYLE_INSTRUCTIONS,
                fn (JsonSchema $schema): array => [
                    'description' => $schema->string()->required(),
                    'palette' => $schema->array()->items($schema->object([
                        'role' => $schema->string()->required(),
                        'hex' => $schema->string()->required(),
                    ]))->required(),
                    'typography' => $schema->string()->required(),
                    'mood' => $schema->string()->required(),
                    'layout' => $schema->string()->required(),
                    'imagery' => $schema->string()->required(),
                    'avoid' => $schema->array()->items($schema->string())->required(),
                ],
                json_encode([
                    'language' => CarouselSettings::get('language'),
                    'description' => trim($description) ?: null,
                    'reference_image' => $imagePath !== null ? 'attached' : 'none',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $provider,
                $imagePath !== null ? [Image::fromPath($imagePath, $mimeType)] : [],
            );
        } catch (AiProviderException $exception) {
            throw new RuntimeException('The AI provider failed: '.$exception->getMessage(), previous: $exception);
        }
        if (! is_array($result)) {
            throw new RuntimeException('The AI provider did not return a style guide.');
        }

        return [
            'description' => trim((string) ($result['description'] ?? '')),
            'style' => self::formatStyle($result),
        ];
    }

    /**
     * @param  array<string, mixed>  $style
     */
    public static function formatStyle(array $style): string
    {
        $palette = collect((array) ($style['palette'] ?? []))
            ->filter(fn (mixed $color): bool => is_array($color) && filled($color['hex'] ?? null))
            ->map(fn (array $color): string => trim(($color['role'] ?? '').' '.$color['hex']))
            ->implode(', ');
        $lines = [
            'Paleta' => $palette,
            'Tipografia' => $style['typography'] ?? '',
            'Clima' => $style['mood'] ?? '',
            'Layout' => $style['layout'] ?? '',
            'Fundo e fotos' => $style['imagery'] ?? '',
            'Evitar' => implode('; ', array_filter((array) ($style['avoid'] ?? []), 'is_string')),
        ];

        return collect($lines)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->map(fn (string $value, string $label): string => "{$label}: {$value}")
            ->implode("\n");
    }

    /**
     * Ask the AI provider chosen for "Carousel curation" for cover headlines
     * and a call to action, from the niche and the selected products.
     *
     * @return array{headlines: list<string>, cta: string}
     *
     * @throws RuntimeException with a message for the user
     */
    public static function generateTexts(Carousel $carousel): array
    {
        $provider = IntegrationHelper::resolveFeatureProvider(AiFeature::CarouselCuration);
        if ($provider === null) {
            throw new RuntimeException('No AI provider is enabled for Carousel curation (Settings > AI).');
        }

        try {
            $result = AiService::new()->structured(
                self::TEXTS_INSTRUCTIONS,
                fn (JsonSchema $schema): array => [
                    'headlines' => $schema->array()->items($schema->string())->required(),
                    'cta' => $schema->string()->required(),
                ],
                json_encode([
                    'language' => $carousel->language ?? 'pt-BR',
                    'niche' => $carousel->niche,
                    'products' => $carousel->selection()->pluck('title')->all(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $provider,
            );
        } catch (AiProviderException $exception) {
            throw new RuntimeException('The AI provider failed: '.$exception->getMessage(), previous: $exception);
        }

        $headlines = collect((array) ($result['headlines'] ?? []))
            ->filter(fn (mixed $headline): bool => is_string($headline) && trim($headline) !== '')
            ->map(fn (string $headline): string => Str::limit(trim($headline), 255, ''))
            ->unique()
            ->values()
            ->all();
        if ($headlines === []) {
            throw new RuntimeException('The AI provider did not return cover headlines.');
        }

        return ['headlines' => $headlines, 'cta' => Str::limit(trim((string) ($result['cta'] ?? '')), 255, '')];
    }

    /** Name of a product photo in the downloaded images, without extension. */
    public static function imageName(int $position): string
    {
        return 'produto-'.str_pad((string) $position, 2, '0', STR_PAD_LEFT);
    }

    /**
     * The Canva AI prompt for the carousel's selection: the profile's style,
     * then one page per slide. Prices, stores and links are left out on
     * purpose: the carousel sparks curiosity so people ask for the links.
     */
    public static function forCarousel(Carousel $carousel): string
    {
        $profile = self::profile($carousel->profile_id);
        $style = trim((string) ($profile['style'] ?? ''));
        $cta = trim((string) ($carousel->cta ?: ($profile['default_cta'] ?? '')));
        $products = $carousel->selection()->get();
        $pages = $products->count() + 1 + ($cta !== '' ? 1 : 0);

        $parts = [
            "Crie um carrossel para Instagram com {$pages} páginas no formato retrato 1080 x 1350 px (4:5). Todas as páginas são da mesma série: mesma paleta, mesma tipografia, mesmo fundo e o mesmo layout em todas as páginas de produto.",
            $style !== ''
                ? 'Guia de estilo'.($profile ? " ({$profile['name']})" : '').":\n{$style}"
                : 'Estilo: limpo, moderno e consistente entre as páginas.',
            "Tema: {$carousel->niche}",
            "Página 1 (capa)\nTítulo: ".($carousel->headline ?: $carousel->niche),
        ];

        foreach ($products->values() as $index => $candidate) {
            /** @var CarouselCandidate $candidate */
            $parts[] = implode("\n", array_filter([
                'Página '.($index + 2).' (produto '.($index + 1).' de '.$products->count().')',
                'Produto: '.self::withoutPrice((string) $candidate->title),
                $candidate->description ? 'Texto: '.self::withoutPrice($candidate->description) : null,
                'Imagem: use a foto enviada "'.self::imageName($index + 1).'" sem alterar o produto (formato, cores e detalhes).',
            ]));
        }

        if ($cta !== '') {
            $parts[] = "Página {$pages} (encerramento)\nChamada: {$cta}";
        }

        $parts[] = implode("\n", [
            'Regras:',
            '- Não mostre preço, desconto, loja nem link em nenhuma página: o carrossel deve gerar curiosidade para que as pessoas peçam o link.',
            '- Use somente as informações acima; não invente marcas nem características.',
            '- Pode encurtar o nome do produto, mantendo o que ele é.',
            '- Textos curtos e legíveis no celular.',
        ]);

        return implode("\n\n", $parts);
    }

    /** Some marketplace titles end with the price (e.g. "Luminária - R$ 64,02"). */
    protected static function withoutPrice(string $text): string
    {
        return trim((string) preg_replace('/\s*[-–|:]?\s*R\$\s*\d[\d.,]*/u', '', $text));
    }
}
