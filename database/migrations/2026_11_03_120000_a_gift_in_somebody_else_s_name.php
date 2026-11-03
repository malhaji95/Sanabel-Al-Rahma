<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | T-241 — «باب الهدايا», 3 November, modelled on the association's own
 | reference (نجاح) step by step.
 |
 | Two tables and no third. `gift_cards` holds the designs, which are the
 | association's own artwork and therefore data: added, reordered and
 | withdrawn from a screen. `gifts` holds what a donor filled in, one row
 | per card, so «إمكانية إضافة أكثر من بطاقة إهداء ضمن العملية نفسها» is
 | several rows against one transfer rather than a second kind of donation.
 |
 | Nothing new happens to the money. A gift is an ordinary platform
 | donation — the same reference, the same verification by the finance desk,
 | the same rule 1 — and the gift row hangs off it. A gift aimed at a
 | project allocates to that campaign on verification, which is «الحفاظ على
 | تخصيص التبرعات» with the machinery that was already there.
 |
 | The recipient's telephone number is personal data belonging to somebody
 | who never signed up for anything, so it is encrypted at rest like the
 | file-holder's own (rule 10). The token is what the card is read by: a
 | private address, not a guessable id.
 |
 | The card is issued only after the transfer is verified, which is their
 | requirement 9 and also the only honest order: a card that says money was
 | given on your behalf must not exist before the money is confirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('image_path')->nullable();
            $table->boolean('is_published')->default(true);
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gift_card_id')->nullable()->constrained('gift_cards')->nullOnDelete();
            // Null is «إهداء عام»; a campaign is «إهداء مخصص لمشروع».
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('giver_name_ar');
            $table->string('recipient_name_ar');
            // «رقم الهاتف مع مفتاح الدولة» — the key beside the number, so a
            // number is never guessed at from the association's own country.
            $table->string('recipient_country_code', 8);
            $table->text('recipient_phone_encrypted');
            // «إمكانية إظهار مبلغ التبرع للمُهدى إليه أو إخفائه».
            $table->boolean('show_amount')->default(true);
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('SYP');
            // Null is «إهداء مباشر»; a time is «جدولة الإهداء».
            $table->timestamp('send_at')->nullable();
            $table->string('status')->default('pending'); // pending|issued
            $table->timestamp('issued_at')->nullable();
            $table->string('token', 40)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'send_at']);
        });

        // Rule: a database constraint over application logic. A gift of
        // nothing is not a gift, and a card that costs nothing would still
        // print «تم التبرع بمبلغ ٠».
        DB::statement(
            'ALTER TABLE gifts ADD CONSTRAINT gifts_amount_positive CHECK (amount > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('gifts');
        Schema::dropIfExists('gift_cards');
    }
};
