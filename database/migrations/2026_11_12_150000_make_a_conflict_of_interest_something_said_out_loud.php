<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-305 — «عدم وجود آلية لمنع تضارب المصالح», 12 November, which the
 * association marked as the gravest of the six.
 *
 * The file already refuses two things: a supervisor may not endorse a visit
 * they made themselves, and an approver may not approve a file they created.
 * Those guard a person against their own hand. Neither of them says anything
 * about the hand of a cousin.
 *
 * So a person who works a file says, before the file moves, whether anything
 * ties them to the household — and if something does, they may record what
 * they saw and may not be the one who verifies it or approves it. That is
 * the whole of it: the disclosure is the obligation, and the bar follows
 * from the answer rather than from anybody's suspicion.
 *
 * And a bar that cannot be lifted is a bar that gets worked around. It lifts
 * for a named person, by somebody else holding a key of their own, with a
 * reason written down — and every part of that is in the audit log, where
 * the oversight committee reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conflict_disclosures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The answer itself. Not nullable: this table exists to hold an
            // answer, and a row without one would be the silence it replaces.
            $table->boolean('has_relationship');
            $table->string('relation_type')->nullable();
            $table->string('relation_note_ar')->nullable();
            $table->timestamp('declared_at');

            $table->timestamps();
            $table->softDeletes();

            // One standing answer per person per file. Changing it rewrites
            // the row, and the audit log keeps what it said before.
            $table->unique(['beneficiary_id', 'user_id']);
        });

        Schema::create('conflict_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            // The person the bar is lifted for. Named, so a waiver cannot
            // quietly open the file to everybody.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by')->constrained('users');
            $table->text('reason_ar');
            $table->timestamp('granted_at');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['beneficiary_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conflict_waivers');
        Schema::dropIfExists('conflict_disclosures');
    }
};
