<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->string('file_number')->unique();
            $table->text('national_id_encrypted');
            $table->string('national_id_hash')->unique();
            $table->string('first_name');
            $table->string('father_name');
            $table->string('family_name');
            $table->text('phone_encrypted')->nullable();
            $table->foreignId('region_id')->constrained()->restrictOnDelete();
            $table->string('marital_status')->nullable();
            $table->text('wallet_encrypted')->nullable();
            $table->string('support_type')->default('one_time'); // monthly|one_time
            $table->string('status')->default('draft');
            $table->timestamp('last_assessment_at')->nullable();
            $table->timestamp('next_assessment_due_at')->nullable();
            $table->string('source')->default('delegate'); // delegate|association
            $table->foreignId('merged_into_id')->nullable()->constrained('beneficiaries')->nullOnDelete();
            $table->boolean('duplicate_review_flag')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('reject_reason_ar')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'region_id']);
            $table->index('support_type');
        });

        Schema::create('household_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->string('relation');
            $table->string('name_ar');
            $table->unsignedSmallInteger('birth_year');
            $table->string('gender');
            $table->string('person_class'); // adult|child|elderly — exactly one
            $table->boolean('dependent')->default(false);
            $table->boolean('unable_to_earn')->default(false);
            $table->boolean('is_student')->default(false);
            $table->boolean('has_documented_condition')->default(false);
            $table->text('notes_ar')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('beneficiary_id');
        });

        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('SYP');
            $table->boolean('is_stable')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('beneficiary_id');
        });

        Schema::create('housing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->string('housing_type'); // rent|owned|hosted|shelter
            $table->bigInteger('monthly_rent')->default(0);
            $table->string('currency', 3)->default('SYP');
            $table->unsignedSmallInteger('habitable_rooms')->default(1);
            $table->unsignedTinyInteger('safety_band')->default(0);
            $table->unsignedTinyInteger('services_band')->default(0);
            $table->unsignedTinyInteger('eviction_band')->default(0);
            $table->string('landlord_name_ar')->nullable();
            $table->text('landlord_phone_encrypted')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('beneficiary_id');
        });

        Schema::create('health_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('household_members')->nullOnDelete();
            $table->unsignedTinyInteger('severity_band')->default(0);
            $table->unsignedTinyInteger('economic_impact_band')->default(0);
            $table->unsignedTinyInteger('care_burden_band')->default(0);
            $table->bigInteger('monthly_medical_cost')->default(0);
            $table->string('currency', 3)->default('SYP');
            $table->text('description_ar')->nullable();
            $table->unsignedBigInteger('evidence_media_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('beneficiary_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_records');
        Schema::dropIfExists('housing');
        Schema::dropIfExists('incomes');
        Schema::dropIfExists('household_members');
        Schema::dropIfExists('beneficiaries');
    }
};
