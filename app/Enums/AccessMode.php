<?php

namespace App\Enums;

enum AccessMode: string
{
    case Auto = 'auto';
    case Api = 'api';
    case Scraping = 'scraping';
}
