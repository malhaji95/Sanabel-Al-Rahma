<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-322 — phase two, item 10: «استيراد ملفّات المحاسب».
 |
 | The accountant holds a bank statement and the platform holds a queue of
 | donors who say they transferred. Matching the two was done by eye, one
 | reference at a time, and a reference read wrong is money verified that
 | never arrived.
 |
 | Two tables. The head is the upload — who sent it, what came of it, who
 | approved it. The rows are what the file said, each with the verdict the
 | matching reached, so the preview is a reading of the file and not a second
 | guess at it.
 |
 | Nothing here creates a donation. `donations.donor_id` is required and a
 | bank statement names no donor, so a statement row can only ever match a
 | donation that already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donation_imports', function (Blueprint $table) {
            $table->id();
            $table->string('original_name');
            $table->string('status')->default('staged'); // staged|approved|discarded
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            // The hand that approves is never the hand that uploaded.
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('discard_reason_ar')->nullable();
            $table->json('summary_json')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('donation_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donation_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->string('transaction_ref')->nullable();
            $table->bigInteger('amount')->nullable();
            $table->string('currency')->nullable();
            $table->date('received_on')->nullable();
            /*
             | ready | incomplete | repeated_in_file | already_verified |
             | amount_mismatch | no_match
             */
            $table->string('outcome');
            $table->foreignId('donation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['donation_import_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_import_rows');
        Schema::dropIfExists('donation_imports');
    }
};
