<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Every operational decision the association owns, as data.
 *
 * T-86 — audited. Rule 4 lists a permission change among the writes that must
 * be logged, and one of these rows is exactly that: `anonymise_admin_allowed`
 * decides whether the system administrator may take part in erasing a family's
 * personal data. The screen has told the association since T-75 that «كل فتح
 * وإغلاق مسجَّل باسم من فعله ووقته», and until now nothing wrote that line
 * down — found while writing the training guide for the screen, which is the
 * second time a guide has caught the system claiming more than it did.
 *
 * The rest of the rows ride along, which is right: a changed reservation
 * window or funding share changes what donors are asked for, and knowing who
 * changed it and when is the same question.
 */
class Setting extends Model
{
    use Auditable;
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
        self::loadOnce();

        return array_key_exists($key, self::$loaded) ? self::$loaded[$key] : $default;
    }

    /**
     * A token that changes whenever any setting does.
     *
     * T-87 — the public lists are cached for a minute, and several of these
     * rows decide what a donor is shown: the funding share, the sufficiency
     * sentence, the four digits of a household's account. A switch the
     * association closes has to take effect when they close it, not a minute
     * later, so the token goes into the cache key and closing one orphans
     * every page that was drawn under the old answer.
     *
     * It is a hash of the values themselves rather than of when they last
     * changed: the timestamp columns keep whole seconds, and two writes inside
     * one second would have left the token standing still.
     */
    public static function fingerprint(): string
    {
        self::loadOnce();

        $values = self::$loaded;
        ksort($values);

        return md5(serialize($values));
    }

    /** Named for the clash: Model::load() is an instance method. */
    private static function loadOnce(): void
    {
        // One query for the whole table: it holds a couple of dozen rows, and a
        // request that wants one setting usually wants several.
        self::$loaded ??= static::query()->pluck('value_json', 'key')->all();
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value_json' => $value]);
    }
}
