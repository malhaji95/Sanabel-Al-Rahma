<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToRegion;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Beneficiary extends Model
{
    use Auditable, BelongsToRegion, HasFactory, SoftDeletes, TracksCreator;

    /** Rule 3 — nothing is hard-deleted. forceDelete is not used anywhere. */
    public const STATUSES = [
        'draft', 'pending_visit', 'verified', 'pending_approval', 'approved', 'published',
        'needs_reassessment', 'suspended', 'graduated', 'rejected', 'merged',
    ];

    protected $fillable = [
        'file_number', 'national_id_encrypted', 'national_id_hash', 'first_name', 'father_name',
        'family_name', 'phone_encrypted', 'region_id', 'marital_status', 'wallet_encrypted',
        'support_type', 'urgency_deadline_at', 'documented_debt', 'status', 'last_assessment_at', 'next_assessment_due_at', 'source',
        'merged_into_id', 'duplicate_review_flag', 'approved_by', 'approved_at', 'reject_reason_ar',
        'published_at', 'created_by',
    ];

    protected $hidden = ['national_id_encrypted', 'national_id_hash', 'phone_encrypted', 'wallet_encrypted'];

    protected function casts(): array
    {
        return [
            'national_id_encrypted' => 'encrypted',
            'phone_encrypted' => 'encrypted',
            'wallet_encrypted' => 'encrypted',
            'last_assessment_at' => 'datetime',
            'next_assessment_due_at' => 'datetime',
            'approved_at' => 'datetime',
            'urgency_deadline_at' => 'datetime',
            'documented_debt' => 'integer',
            'published_at' => 'datetime',
            'duplicate_review_flag' => 'boolean',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(HouseholdMember::class);
    }

    public function incomes(): HasMany
    {
        return $this->hasMany(Income::class);
    }

    public function housing(): HasOne
    {
        return $this->hasOne(Housing::class);
    }

    public function healthRecords(): HasMany
    {
        return $this->hasMany(HealthRecord::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(DonationAllocation::class);
    }

    public function basketItems(): HasMany
    {
        return $this->hasMany(BasketItem::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function sponsorships(): HasMany
    {
        return $this->hasMany(Sponsorship::class);
    }

    public function jobProfile(): HasOne
    {
        return $this->hasOne(JobProfile::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function currentAssessment(): ?Assessment
    {
        // Honour an eager-loaded relation. Lists rank every published family and
        // ask each one for its assessment, so querying here regardless turned a
        // single `with('assessments')` into one query per family.
        if ($this->relationLoaded('assessments')) {
            return $this->assessments
                ->where('status', 'approved')
                ->sortByDesc('id')
                ->first();
        }

        return $this->assessments()->where('status', 'approved')->latest('id')->first();
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /** sha256 of the normalised national id — rule 11, exact match blocks a second file. */
    public static function hashNationalId(string $nationalId): string
    {
        $normalised = preg_replace('/\D+/', '', $nationalId) ?: $nationalId;

        return hash_hmac('sha256', $normalised, (string) config('app.key'));
    }
}
