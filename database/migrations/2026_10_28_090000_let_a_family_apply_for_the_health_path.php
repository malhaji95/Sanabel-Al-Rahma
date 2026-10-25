<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-145 — «ونحتاج يكون عنده تقديم مسار الصحي اللي هو تقديم على اطباء او مشافي
 | او صيدلية», 28 October.
 |
 | The health path has had both of its ends since T-32: a provider — a doctor,
 | a hospital, a pharmacy, a laboratory, a clinic — with a discount and an
 | agreed number of cases, and a single-use referral card that the association
 | issues and the provider redeems against proof.
 |
 | What it had no room for is the beginning: a household saying «I need to see
 | a doctor». The association was issuing cards to families who had asked by
 | telephone, with the asking kept nowhere, so nobody could answer «how many
 | asked, for what, and how long did they wait».
 |
 | One row per asking. It names the kind of provider and, in the family's own
 | words, what for; the association answers it by issuing a card, which the row
 | then points at, or by declining it with a reason. The card and the quota
 | machinery are untouched: this is the queue in front of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained('beneficiaries')->cascadeOnDelete();
            // hospital | doctor | pharmacy | lab | clinic — the provider types
            // the association already works with.
            $table->string('provider_type');
            $table->string('specialty_ar')->nullable();
            $table->text('note_ar')->nullable();
            // open | issued | declined — a string column, not a state machine.
            $table->string('status')->default('open');
            $table->foreignId('referral_id')->nullable()->constrained('referrals')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->text('decline_reason_ar')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'provider_type']);
            $table->index(['beneficiary_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_requests');
    }
};
