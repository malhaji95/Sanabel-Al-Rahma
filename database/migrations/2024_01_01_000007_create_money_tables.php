<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funds', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // operational|restricted|zakat|membership
            $table->string('name_ar');
            $table->boolean('can_fund_families')->default(true);
            $table->timestamps();
        });

        Schema::create('donors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name_ar');
            $table->text('phone_encrypted')->nullable();
            $table->string('email')->nullable();
            $table->text('wallet_encrypted')->nullable();
            $table->unsignedInteger('donations_count')->default(0);
            $table->string('badge')->default('none'); // none|silver|gold
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title_ar');
            $table->text('body_ar')->nullable();
            $table->bigInteger('goal_amount');
            $table->bigInteger('collected_amount')->default(0);
            $table->bigInteger('reserved_amount')->default(0);
            $table->string('currency', 3)->default('SYP');
            $table->text('wallet_encrypted')->nullable();
            $table->text('surplus_policy_text_ar')->nullable(); // mandatory before publishing
            $table->boolean('is_published')->default(false);
            $table->string('status')->default('active'); // active|funded|awaiting_execution|completed|cancelled
            $table->foreignId('fund_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('baskets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donor_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('open'); // open|reserved|paid|expired
            $table->timestamp('reserved_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'reserved_until']);
        });

        Schema::create('basket_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('basket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('SYP');
            $table->timestamps();

            $table->unique(['basket_id', 'beneficiary_id']);
            $table->index('beneficiary_id');
        });

        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donor_id')->constrained()->restrictOnDelete();
            $table->string('route')->default('platform'); // direct|platform
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('SYP');
            // Rule 1: never relaxed.
            $table->string('transaction_ref')->unique();
            $table->unsignedBigInteger('receipt_media_id')->nullable();
            $table->string('status')->default('pending'); // pending|verified|rejected|reversed
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->foreignId('fund_id')->constrained()->restrictOnDelete();
            $table->foreignId('basket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('donations')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'donor_id']);
        });

        Schema::create('donation_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('beneficiary_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('SYP');
            $table->timestamps();

            $table->index('beneficiary_id');
            $table->index('campaign_id');
        });

        Schema::create('sponsorships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('SYP');
            $table->date('start_date');
            $table->date('end_date'); // required — rule 8
            $table->string('status')->default('active'); // active|completed|lapsed|cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sponsorship_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sponsorship_id')->constrained()->cascadeOnDelete();
            $table->string('period'); // YYYY-MM
            $table->date('due_date');
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('SYP');
            $table->string('status')->default('due'); // due|paid|overdue
            $table->foreignId('donation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->unique(['sponsorship_id', 'period']);
        });

        Schema::create('distributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->constrained()->restrictOnDelete();
            $table->string('title_ar');
            $table->bigInteger('total_amount');
            $table->bigInteger('per_family_amount');
            $table->string('currency', 3)->default('SYP');
            $table->json('criteria_json')->nullable();
            $table->json('list_json')->nullable(); // frozen at approval — never regenerated
            $table->string('status')->default('draft'); // draft|approved|executing|completed|partial
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('distribution_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('SYP');
            $table->string('status')->default('pending'); // pending|executed|failed
            $table->text('failure_reason_ar')->nullable();
            $table->unsignedBigInteger('proof_media_id')->nullable();
            $table->timestamps();

            $table->unique(['distribution_id', 'beneficiary_id']);
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('donation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // cash|provider_invoice|goods|confirmation
            $table->unsignedBigInteger('proof_media_id')->nullable();
            $table->text('note_ar')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('beneficiary_id');
        });
    }

    public function down(): void
    {
        foreach ([
            'deliveries', 'distribution_items', 'distributions', 'sponsorship_installments',
            'sponsorships', 'donation_allocations', 'donations', 'basket_items', 'baskets',
            'campaigns', 'donors', 'funds',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
