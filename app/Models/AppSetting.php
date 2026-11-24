<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property string $key
 * @property string|null $value
 */
#[Fillable(['key', 'value'])]
class AppSetting extends Model
{
    /**
     * Retrieve a setting value by key with fallback to default.
     */
    public static function getValue(string $key, ?string $default = null): ?string
    {
        $settings = Cache::remember('lodis_app_settings', 300, function (): array {
            return self::query()->pluck('value', 'key')->all();
        });

        if (array_key_exists($key, $settings) && $settings[$key] !== null && $settings[$key] !== '') {
            return (string) $settings[$key];
        }

        return $default;
    }

    /**
     * Set or update a setting value and flush the settings cache.
     */
    public static function setValue(string $key, ?string $value): self
    {
        $setting = self::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );

        Cache::forget('lodis_app_settings');

        return $setting;
    }
}
