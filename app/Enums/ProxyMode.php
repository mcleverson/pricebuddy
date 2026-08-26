<?php

namespace App\Enums;

enum ProxyMode: string
{
    case Disabled = 'disabled';
    case Prefer = 'prefer';
    case Required = 'required';
}
