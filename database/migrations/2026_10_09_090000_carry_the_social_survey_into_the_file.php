<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | The association's own social survey (Sanabil_v54), brought into the file.
 |
 | Their sheet carries 104 columns and a second sheet of 19 for the dependants.
 | Twenty seven of them we already held; the rest are here. What their system
 | computes (ages, totals, counts) is not stored again, and what belongs to
 | their own review workflow (researcher opinion, dossier PDF, form serial)
 | stays out: this system has its own path for that.
 |
 | Two rules shaped the money columns. Every one of them is recorded, never
 | computed from: the need engine does not read a tractor's value or a cow's
 | milk until the association says it should, the way previous_aid_ar is
 | recorded and ignored. And every identity number and every telephone number
 | belonging to anybody at all — a wife, a carer, a dependant, a doctor, a
 | creditor — is encrypted at rest like the head of household's, because rule
 | 2 does not care whose number it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->string('gender')->nullable()->after('family_name');
            $table->date('birth_date')->nullable()->after('gender');
            $table->string('town_ar')->nullable()->after('region_id');
            $table->decimal('latitude', 10, 7)->nullable()->after('town_ar');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');

            $table->boolean('works')->nullable()->after('marital_status');
            $table->string('workplace_ar')->nullable()->after('works');

            $table->boolean('has_quran_memorizers')->nullable()->after('workplace_ar');
            $table->unsignedSmallInteger('quran_memorizer_count')->nullable()->after('has_quran_memorizers');

            // What the family themselves propose, and what it would cost.
            $table->text('livelihood_proposal_ar')->nullable()->after('previous_aid_ar');
            $table->text('proposal_skills_ar')->nullable()->after('livelihood_proposal_ar');
            $table->bigInteger('proposal_cost')->nullable()->after('proposal_skills_ar');

            $table->string('surveyed_by_ar')->nullable()->after('proposal_cost');
            $table->text('furnishing_ar')->nullable()->after('surveyed_by_ar');
        });

        Schema::table('household_members', function (Blueprint $table) {
            $table->text('national_id_encrypted')->nullable()->after('name_ar');
            $table->string('relation_other_ar')->nullable()->after('relation');
            $table->date('birth_date')->nullable()->after('birth_year');
            $table->string('marital_status')->nullable()->after('birth_date');
            $table->string('spouse_name_ar')->nullable()->after('marital_status');
            // طالب، موظف، باحث عن عمل، متقاعد، يتيم …
            $table->string('occupation')->nullable()->after('spouse_name_ar');
            $table->string('school_grade_ar')->nullable()->after('occupation');
            $table->boolean('works')->nullable()->after('school_grade_ar');
            $table->string('workplace_ar')->nullable()->after('works');
            $table->boolean('is_quran_memorizer')->default(false)->after('workplace_ar');
            $table->text('certificate_ar')->nullable()->after('is_quran_memorizer');
            $table->text('skills_ar')->nullable()->after('certificate_ar');
        });

        Schema::table('housing', function (Blueprint $table) {
            $table->string('type_other_ar')->nullable()->after('housing_type');
            $table->string('rent_period')->nullable()->after('monthly_rent');
            $table->unsignedSmallInteger('bathrooms')->nullable()->after('habitable_rooms');
            $table->boolean('has_kitchen')->nullable()->after('bathrooms');
        });

        Schema::table('health_records', function (Blueprint $table) {
            $table->boolean('ongoing_treatment')->nullable()->after('description_ar');
            $table->string('treatment_place_ar')->nullable()->after('ongoing_treatment');
            $table->text('medicines_ar')->nullable()->after('treatment_place_ar');
            $table->string('doctor_name_ar')->nullable()->after('medicines_ar');
            $table->text('doctor_phone_encrypted')->nullable()->after('doctor_name_ar');
        });

        // A debt the family carries, one row per creditor.
        Schema::create('debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->string('creditor_name_ar');
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('SYP');
            $table->string('creditor_address_ar')->nullable();
            $table->text('creditor_phone_encrypted')->nullable();
            $table->unsignedSmallInteger('months')->nullable();
            $table->text('reason_ar')->nullable();
            $table->boolean('has_due_date')->default(false);
            $table->date('due_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('beneficiary_id');
        });

        // Another association or body already supporting this family.
        Schema::create('support_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->string('name_ar');
            $table->bigInteger('amount')->nullable();
            $table->string('currency', 3)->default('SYP');
            $table->text('notes_ar')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('beneficiary_id');
        });

        // Whoever is keeping the household, when it is not the head of it.
        Schema::create('caregivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->string('name_ar')->nullable();
            $table->string('relation')->nullable();
            $table->string('relation_other_ar')->nullable();
            $table->text('national_id_encrypted')->nullable();
            $table->boolean('smokes')->nullable();
            $table->string('income_source_ar')->nullable();
            $table->bigInteger('monthly_support')->nullable();
            $table->boolean('neighbours_help')->nullable();
            $table->text('notes_ar')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('beneficiary_id');
        });

        // Land, a house, a shop, a car, a tractor: what the family owns, and
        // what they reckon it is worth. Recorded; the engine does not read it.
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('kind_other_ar')->nullable();
            $table->text('details_ar')->nullable();
            $table->bigInteger('estimated_value')->nullable();
            $table->string('currency', 3)->default('SYP');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('beneficiary_id');
        });

        Schema::create('livestock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('kind_other_ar')->nullable();
            $table->unsignedInteger('head_count')->default(0);
            // family_use | partial_income | main_income
            $table->string('income_kind')->nullable();
            $table->bigInteger('monthly_income')->nullable();
            $table->string('currency', 3)->default('SYP');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('beneficiary_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livestock');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('caregivers');
        Schema::dropIfExists('support_sources');
        Schema::dropIfExists('debts');

        Schema::table('health_records', function (Blueprint $table) {
            $table->dropColumn(['ongoing_treatment', 'treatment_place_ar', 'medicines_ar',
                'doctor_name_ar', 'doctor_phone_encrypted']);
        });

        Schema::table('housing', function (Blueprint $table) {
            $table->dropColumn(['type_other_ar', 'rent_period', 'bathrooms', 'has_kitchen']);
        });

        Schema::table('household_members', function (Blueprint $table) {
            $table->dropColumn(['national_id_encrypted', 'relation_other_ar', 'birth_date',
                'marital_status', 'spouse_name_ar', 'occupation', 'school_grade_ar', 'works',
                'workplace_ar', 'is_quran_memorizer', 'certificate_ar', 'skills_ar']);
        });

        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropColumn(['gender', 'birth_date', 'town_ar', 'latitude', 'longitude',
                'works', 'workplace_ar', 'has_quran_memorizers', 'quran_memorizer_count',
                'livelihood_proposal_ar', 'proposal_skills_ar', 'proposal_cost',
                'surveyed_by_ar', 'furnishing_ar']);
        });
    }
};
