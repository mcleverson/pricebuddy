<?php

namespace App\Services\Carousels;

use App\Models\Carousel;
use App\Settings\AppSettings;

/**
 * Settings > Carousels with the defaults applied: a blank field means "use
 * the default", the same convention as the Intelligence settings.
 */
class CarouselSettings
{
    /**
     * Editorial criteria and their initial weights (%): useful, unexpected
     * products with proven demand. image_quality is never scored (the model
     * does not see the image), so it starts at 0.
     */
    public const DEFAULT_WEIGHTS = [
        'visual_appeal' => 5,
        'originality' => 30,
        'niche_relevance' => 15,
        'usefulness' => 25,
        'image_quality' => 0,
        'commercial_potential' => 25,
    ];

    public const CRITERIA_LABELS = [
        'visual_appeal' => 'Visual appeal',
        'originality' => 'Not obvious',
        'niche_relevance' => 'Niche relevance',
        'usefulness' => 'Usefulness',
        'image_quality' => 'Image quality',
        'commercial_potential' => 'Proven demand',
    ];

    /** Starting suggestions, used as marketplace search terms; editable in Settings. */
    public const DEFAULT_NICHES = [
        'Decoração minimalista',
        'Cantinho do café',
        'Organização de cozinha',
        'Utensílios criativos de cozinha',
        'Acessórios para home office',
        'Presentes criativos',
        'Decoração de quarto',
    ];

    public const DEFAULTS = [
        'region' => 'BR',
        'language' => 'pt-BR',
        'suggestions_enabled' => true,
        'custom_niche_enabled' => true,
        'suggested_niches' => self::DEFAULT_NICHES,
        // Empty: every store that supports carousel discovery.
        'store_ids' => [],
        'candidates_per_store' => 8,
        'min_score' => 60,
        'filter_duplicates' => true,
        'require_image' => true,
        'weights' => self::DEFAULT_WEIGHTS,
        // Products per carousel.
        'min_products' => 3,
        'max_products' => 10,
        'suggested_products' => 5,
        // Instagram profiles: name, call to action and style guide (see CarouselPrompts).
        'profiles' => [],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $stored = AppSettings::new()->carousel_settings;
        $settings = self::DEFAULTS;

        foreach (self::DEFAULTS as $key => $default) {
            $value = $stored[$key] ?? null;
            if ($key === 'weights') {
                foreach (self::DEFAULT_WEIGHTS as $criterion => $weight) {
                    $storedWeight = $value[$criterion] ?? null;
                    $settings['weights'][$criterion] = (float) (is_numeric($storedWeight) ? $storedWeight : $weight);
                }
            } elseif ($value !== null && $value !== '' && $value !== []) {
                $settings[$key] = is_int($default) ? (int) $value : $value;
            }
        }

        $settings['suggested_niches'] = array_values(array_filter(
            array_map(fn (mixed $niche): string => is_string($niche) ? trim($niche) : '', (array) $settings['suggested_niches']),
            fn (string $niche): bool => $niche !== '',
        ));

        return $settings;
    }

    /**
     * Allowed products per carousel, kept within Carousel::MAX_PRODUCT_SLOTS.
     *
     * @return array{0: int, 1: int}
     */
    public static function productRange(): array
    {
        $settings = self::all();
        $min = max(1, min(Carousel::MAX_PRODUCT_SLOTS, (int) $settings['min_products']));
        $max = max($min, min(Carousel::MAX_PRODUCT_SLOTS, (int) $settings['max_products']));

        return [$min, $max];
    }

    public static function suggestedProducts(): int
    {
        [$min, $max] = self::productRange();

        return max($min, min($max, (int) self::get('suggested_products')));
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }
}
