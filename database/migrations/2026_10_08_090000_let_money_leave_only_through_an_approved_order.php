<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | The disbursement order, as the association set it out on 6 and 8 October.
 |
 | A payment to a family is a row of its own that walks a path: the case
 | officer confirms it, the treasurer gathers the confirmed ones into one
 | order, the executive director or the deputy approves the order (as a whole
 | or line by line), the treasurer executes, and the accountant reconciles.
 |
 | The order freezes on approval: nothing is added or removed afterwards. Each
 | line keeps its own state, so one failure does not stop the rest — the shape
 | the group distribution already uses.
 |
 | There is no ceiling in figures. The ceiling is the family's own monthly
 | need, and anything beyond it carries to the next month rather than being
 | paid twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disbursement_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no')->unique();
            $table->string('status')->default('draft'); // draft|pending_approval|approved|executing|settled|cancelled
            $table->string('currency', 3)->default('SYP');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            // The association's own wording: every approval by the executive
            // director or the chairman is recorded as given "duly".
            $table->boolean('approved_duly')->default(false);

            $table->timestamp('settled_at')->nullable();
            $table->text('notes_ar')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('disbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('disbursement_order_id')->nullable()->constrained()->nullOnDelete();

            $table->bigInteger('amount');
            $table->string('currency', 3)->default('SYP');
            // The calendar month this payment answers for, so a second payment
            // in the same month is read against the same need.
            $table->date('period');

            // confirmed|in_order|approved|rejected|executed|failed|reconciled
            $table->string('status')->default('confirmed');

            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('reject_reason_ar')->nullable();

            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->string('transfer_ref')->nullable();
            $table->unsignedBigInteger('proof_media_id')->nullable();
            $table->text('failure_reason_ar')->nullable();

            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'period']);
            $table->index('beneficiary_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disbursements');
        Schema::dropIfExists('disbursement_orders');
    }
};
