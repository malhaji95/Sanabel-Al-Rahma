<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value_json', 'updated_by'];

    protected $casts = ['value_json' => 'array'];

    /** Grace days, hold hours, thresholds, reassessment days, feature flags — all live here. */
    public static function value(string $key, mixed $default = null): mixed
    {
        $row = static::query()->where('key', $key)->first();

        return $row ? $row->value_json : $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value_json' => $value]);
    }
}
