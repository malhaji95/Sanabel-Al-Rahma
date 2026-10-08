<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Whoever keeps the household, when that is not the head of it. */
class Caregiver extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $guarded = ['id'];

    protected $hidden = ['national_id_encrypted'];

    /** أب، أم، زوج، زوجة، ابن، بنت، أخ، أخت، زوج بنت، أخ زوج، الجيران، غيرهم */
    public const RELATIONS = [
        'father', 'mother', 'husband', 'wife', 'son', 'daughter',
        'brother', 'sister', 'son_in_law', 'brother_in_law', 'neighbours', 'other',
    ];

    protected function casts(): array
    {
        return [
            'national_id_encrypted' => 'encrypted',
            'smokes' => 'boolean',
            'neighbours_help' => 'boolean',
            'monthly_support' => 'integer',
        ];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }
}
