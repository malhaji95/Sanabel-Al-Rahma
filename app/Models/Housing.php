<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Housing extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $table = 'housing';

    protected $fillable = [
        'beneficiary_id', 'housing_type', 'monthly_rent', 'currency', 'habitable_rooms',
        'safety_band', 'services_band', 'eviction_band', 'landlord_name_ar',
        'landlord_phone_encrypted', 'created_by',
        'type_other_ar', 'rent_period', 'bathrooms', 'has_kitchen',
        // T-169 — the impression of the house and what it lacks, beside the
        // researcher's sentence for the committee.
        'home_impression', 'missing_items', 'missing_items_other_ar',
    ];

    /*
     | T-300 — «تحويل الإيجار السنوي إلى متوسط شهري قبل استخدامه في أي
     | معادلة», 12 November.
     |
     | كم شهرًا تغطّي الدفعةُ الواحدة. والقائمة هي التي يعرضها الاستبيان
     | نفسه: كان هنا ثابتٌ يقول «monthly أو yearly», و«yearly» لا تُكتب في
     | هذا الجدول أبدًا — النموذج يكتب مفاتيح قائمة اللغة الأربعة. فكان
     | الثابت ميّتًا ومخالفًا لما يُخزَّن.
     */
    public const RENT_PERIOD_MONTHS = [
        'monthly' => 1,
        'quarterly' => 3,
        'semiannual' => 6,
        'annual' => 12,
    ];

    /** The periods the survey offers, which are the ones it stores. */
    public const RENT_PERIODS = ['monthly', 'quarterly', 'semiannual', 'annual'];

    protected $hidden = ['landlord_name_ar', 'landlord_phone_encrypted'];

    protected function casts(): array
    {
        return [
            // T-169 — more than one thing may be missing from one house.
            'missing_items' => 'array',
            'landlord_phone_encrypted' => 'encrypted',
            'monthly_rent' => 'integer',
            'habitable_rooms' => 'integer',
            'bathrooms' => 'integer',
            'has_kitchen' => 'boolean',
            'safety_band' => 'integer',
            'services_band' => 'integer',
            'eviction_band' => 'integer',
        ];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function isRenting(): bool
    {
        return $this->housing_type === 'rent';
    }

    /**
     * T-300 — what the family pays in one month, whatever period the figure
     * was written for.
     *
     * The column is called `monthly_rent` and was read as a month's rent by
     * everything that touched it, while the question beside it offered
     * «كل ثلاثة أشهر» and «سنوي». So a year's rent entered as a year's rent
     * was read as a month's: in the housing factor it pushed the rent burden
     * to its ceiling, worth up to fifty-five points of that sub-factor on a
     * family whose real rent sits below the reference — which is most of them.
     *
     * An unanswered period is a month, which is what every figure already
     * written was taken to mean.
     */
    public function monthlyRent(): int
    {
        $months = self::RENT_PERIOD_MONTHS[$this->rent_period] ?? 1;

        return intdiv(max(0, (int) $this->monthly_rent), $months);
    }
}
