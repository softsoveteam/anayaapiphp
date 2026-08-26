<?php

namespace App\Enums;

enum SiteStatus: string
{
    case Active = 'active';
    case Paused = 'paused';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
