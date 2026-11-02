<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | T-210 — «كل كفالة يظهر فيها مبلغها الشهري المحدد ومدتها والمبلغ المتبقي
 | لتغطيتها، ولا نريد أن يظهر مبلغ احتياج الأسرة الكامل على أنه قيمة
 | الكفالة» and «تحديد مبلغ كل نوع من الكفالات يكون من صلاحيات الإدارة وفق
 | ضوابط معتمدة», 1 November.
 |
 | A sponsorship has a price of its own, and until now it did not. The donor
 | screens read a family's monthly need, which is the whole of what that
 | household is short — a figure that has nothing to do with what the
 | association charges for sponsoring an orphan, and showing one as the
 | other is exactly what they wrote to stop.
 |
 | One row per kind, not per family: «تحديد مبلغ كل نوع من الكفالات». The
 | amount is an integer in the smallest unit beside its currency (rule 9),
 | the duration is in whole months, and both are held above zero by the
 | database rather than by a form (§3). The kind is unique, so there is one
 | published price for «كفالة يتيم» and not two.
 |
 | No soft delete here, and no delete button on the screen. Rule 3 guards
 | the records of people — a household, its file, a donation — and this is a
 | published price. A withdrawn price is switched off rather than removed,
 | which keeps the unique key on `kind` honest: a deleted row would hold the
 | word «يتيم» for ever and refuse the next one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsorship_rates', function (Blueprint $table) {
            $table->id();
            // One of Beneficiary::CASE_CLASSIFICATIONS. Unique: one price.
            $table->string('kind')->unique();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 8);
            // «ومدتها» — the duration the association offers, in months.
            $table->unsignedSmallInteger('months');
            $table->boolean('is_active')->default(true);
            $table->text('notes_ar')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // A price of nothing and a duration of no months are not decisions
        // anybody meant to make, so neither is storable.
        DB::statement('alter table sponsorship_rates add constraint sponsorship_rates_amount_positive check (amount > 0)');
        DB::statement('alter table sponsorship_rates add constraint sponsorship_rates_months_positive check (months > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsorship_rates');
    }
};
