<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value_json', 'updated_by'];

    protected $casts = ['value_json' => 'array'];

    /**
     * Rows already read in this request. A donor list asks for the same two or
     * three settings once per card, and without this each card went back to the
     * database for them — the query count grew with the number of families.
     *
     * @var array<string,mixed>|null
     */
    private static ?array $loaded = null;

    protected static function booted(): void
    {
        // Any write to the table invalidates what the request is holding.
        static::saved(fn () => self::forgetLoaded());
        static::deleted(fn () => self::forgetLoaded());
    }

    public static function forgetLoaded(): void
    {
        self::$loaded = null;
    }

    /** Grace days, hold hours, thresholds, reassessment days, feature flags — all live here. */
    public static function value(string $key, mixed $default = null): mixed
    {
        // One query for the whole table: it holds a couple of dozen rows, and a
        // request that wants one setting usually wants several.
        self::$loaded ??= static::query()->pluck('value_json', 'key')->all();

        return array_key_exists($key, self::$loaded) ? self::$loaded[$key] : $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value_json' => $value]);
    }
}
