<?php

namespace App\Enums;

enum AiFeature: string
{
    case Extraction = 'extraction';
    case Healing = 'healing';
    case CarouselCuration = 'carousel_curation';
    case CarouselStyle = 'carousel_style';

    public function label(): string
    {
        return match ($this) {
            self::Extraction => 'Extraction',
            self::Healing => 'Healing',
            self::CarouselCuration => 'Carousel curation',
            self::CarouselStyle => 'Carousel style',
        };
    }
}
