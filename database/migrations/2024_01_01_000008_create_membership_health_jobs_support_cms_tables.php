<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('membership_no')->unique();
            $table->string('name_ar');
            $table->string('category'); // set from settings
            $table->string('status')->default('active'); // active|overdue|suspended|expired
            $table->date('joined_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('period'); // YYYY-MM or YYYY
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('SYP');
            $table->date('due_date');
            $table->string('status')->default('due'); // due|paid|overdue
            $table->unsignedBigInteger('payment_media_id')->nullable();
            // Membership money can never be family coverage — always the membership fund.
            $table->foreignId('fund_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['member_id', 'period']);
        });

        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name_ar');
            $table->string('type'); // hospital|doctor|pharmacy|lab
            $table->string('specialty_ar')->nullable();
            $table->foreignId('region_id')->constrained()->restrictOnDelete();
            $table->string('discount_type'); // percentage|fixed
            $table->integer('discount_value');
            $table->date('valid_until')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->string('status')->default('issued'); // issued|used|expired|revoked
            $table->timestamp('used_at')->nullable();
            $table->unsignedBigInteger('proof_media_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('job_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->string('trade_key');
            $table->text('summary_ar');
            $table->foreignId('region_id')->constrained()->restrictOnDelete();
            $table->string('availability')->nullable();
            $table->string('status')->default('pending'); // pending|published|hidden
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('job_requests', function (Blueprint $table) {
            $table->id();
            $table->string('requester_name_ar');
            $table->text('contact_encrypted');
            $table->string('trade_key');
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description_ar')->nullable();
            $table->string('status')->default('new'); // new|handled|closed
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no')->unique();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_ar');
            $table->text('body_ar')->nullable();
            $table->string('category');
            $table->foreignId('against_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('new'); // new|assigned|resolved|closed
            // A complaint is never assigned to the person it is about.
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_ar')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('channel'); // in_app|email
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->string('template_key');
            $table->json('payload_json')->nullable(); // never personal data
            $table->string('status')->default('queued'); // queued|sent|failed|read
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_id', 'status']);
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title_ar');
            $table->text('body_ar')->nullable();
            $table->string('image')->nullable();
            $table->boolean('is_published')->default(false);
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title_ar');
            $table->text('body_ar')->nullable();
            $table->string('image')->nullable();
            $table->boolean('is_published')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title_ar');
            $table->text('body_ar')->nullable();
            $table->string('image')->nullable();
            $table->string('link')->nullable();
            $table->boolean('is_published')->default(false);
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        foreach ([
            'banners', 'posts', 'pages', 'app_notifications', 'complaints', 'job_requests',
            'job_profiles', 'referrals', 'providers', 'subscriptions', 'members',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
