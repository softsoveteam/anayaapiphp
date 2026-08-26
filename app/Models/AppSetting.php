<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $row = static::query()->find($key);

        return $row?->value ?? $default;
    }

    public static function setValue(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    public static function sessionMinutes(): int
    {
        $minutes = (int) static::getValue('session_minutes', 5);

        return max(1, min(180, $minutes));
    }

    public static function multipleKeywords(): bool
    {
        return filter_var(static::getValue('multiple_keywords', '0'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function employeeEarnings(): bool
    {
        return filter_var(static::getValue('employee_earnings', '0'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Tabs the employee must open this session: every keyword when the setting is on, otherwise one per site.
     */
    public static function sessionWorkItems($assignments)
    {
        $assignments = collect($assignments);

        if (static::multipleKeywords()) {
            return $assignments->values();
        }

        return $assignments->unique('site_id')->values();
    }
}
