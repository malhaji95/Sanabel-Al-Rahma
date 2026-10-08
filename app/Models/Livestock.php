<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Animals the family keeps, and whether they bring anything in. */
class Livestock extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $table = 'livestock';

    protected $guarded = ['id'];

    public const KINDS = ['cows', 'sheep', 'other'];

    /** لا، للاستخدام الأسري فقط | نعم، دخل جزئي | نعم، مصدر دخل رئيسي */
    public const INCOME_KINDS = ['family_use', 'partial_income', 'main_income'];

    protected function casts(): array
    {
        return ['head_count' => 'integer', 'monthly_income' => 'integer'];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }
}
