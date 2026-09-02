<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Fund extends Model
{
    use HasFactory;

    public const OPERATIONAL = 'operational';
    public const RESTRICTED = 'restricted';
    public const ZAKAT = 'zakat';
    public const MEMBERSHIP = 'membership';

    protected $fillable = ['key', 'name_ar', 'can_fund_families'];

    protected $casts = ['can_fund_families' => 'boolean'];

    public static function byKey(string $key): self
    {
        return static::where('key', $key)->firstOrFail();
    }
}
