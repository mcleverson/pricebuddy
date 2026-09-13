<?php

namespace App\Enums;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;

enum AccessMode: string implements HasColor
{
    case Api = 'api';
    case Scraping = 'scraping';
    case Agentic = 'agentic';

    public function getColor(): array
    {
        return match ($this) {
            self::Api => Color::Blue,
            self::Scraping => Color::Pink,
            self::Agentic => Color::Amber,
        };
    }
}
