<?php

namespace App\Enums;

enum ComputerStatus: string
{
    case Available = 'available';
    case Assigned = 'assigned';
    case Maintenance = 'maintenance';
    case Retired = 'retired';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
