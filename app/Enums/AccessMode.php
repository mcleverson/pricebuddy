<?php

namespace App\Enums;

enum AccessMode: string
{
    case Api = 'api';
    case Scraping = 'scraping';
    case Agentic = 'agentic';
}
