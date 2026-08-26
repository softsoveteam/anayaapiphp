<?php

namespace App\Enums;

enum EmployeeStatus: string
{
    case Interview = 'interview';
    case InterviewPass = 'interview_pass';
    case Onboarded = 'onboarded';
    case Joined = 'joined';
    case Rejected = 'rejected';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Interview => 'Interview',
            self::InterviewPass => 'Interview Pass',
            self::Onboarded => 'Onboarded',
            self::Joined => 'Joined',
            self::Rejected => 'Rejected',
            self::Inactive => 'Inactive',
        };
    }

    public function canLogin(): bool
    {
        return in_array($this, [self::Onboarded, self::Joined], true);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
