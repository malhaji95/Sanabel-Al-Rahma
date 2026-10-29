<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-180 — «المفضلة هي الحالات التي يتم حفظها من قبل المتبرع للعودة لها, وعند
 * السداد لهم تبقى في المفضلة للعودة لها متى ما شاء», 29 October.
 *
 * The association asked for «المفضلة» on the donor's menu on 26 October and we
 * asked back what it holds. This is their answer, and the second half of it is
 * the part that decides the shape: paying does not take a family off the list.
 * A donor who funded a household in March and wants to look in on them in June
 * is the case this is for, so nothing here is cleared by a donation, a covered
 * month or a basket.
 *
 * One row per donor per family, and the pair is unique at the database level
 * rather than guarded in code (CLAUDE.md: prefer a constraint). Saving twice is
 * then simply not a thing that can happen, whatever the screen does.
 *
 * Nothing here reaches a donor's eye as a name: the list is drawn from
 * `MaskedCaseResource` like every other donor-facing list, and a file that is
 * withdrawn or anonymised simply stops appearing on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donor_favourites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['donor_id', 'beneficiary_id']);
            // The donor's own list, newest first, is the only way it is read.
            $table->index(['donor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donor_favourites');
    }
};
