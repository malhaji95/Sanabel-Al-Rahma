<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->string('kind');
            $table->string('storage_key');
            $table->string('visibility')->default('internal'); // internal|public
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delegate_id')->nullable()->constrained('users')->nullOnDelete();
            // Generated on the device. This unique index is what prevents duplicate sync.
            $table->uuid('client_uuid')->unique();
            $table->timestamp('visited_at');
            $table->text('note_ar')->nullable();
            $table->string('recommendation')->nullable();
            $table->boolean('is_reassessment')->default(false);
            $table->json('payload_json')->nullable();
            $table->boolean('conflict_flag')->default(false);
            $table->text('conflict_reason')->nullable();
            $table->timestamp('base_version_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('beneficiary_id');
        });

        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('monthly_need');
            $table->bigInteger('stable_income');
            $table->bigInteger('gap');
            $table->string('currency', 3)->default('SYP');
            $table->decimal('base_score', 6, 2);
            $table->json('factors_json');   // F,M,V,H,U,D,B as computed
            $table->json('snapshot_json');  // rates, rents, weights + versions used
            $table->date('valid_until')->nullable();
            $table->string('status')->default('draft'); // draft|approved|superseded
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['beneficiary_id', 'status']);
        });

        Schema::create('overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            // The automatic score is never erased.
            $table->decimal('auto_score', 6, 2);
            $table->decimal('new_score', 6, 2);
            $table->text('reason_ar');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->json('payload_json');
            $table->json('old_json')->nullable();
            $table->text('reason_ar');
            $table->boolean('is_material')->default(false);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending'); // pending|approved|rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note_ar')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_type', 'entity_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_requests');
        Schema::dropIfExists('overrides');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('visits');
        Schema::dropIfExists('media');
    }
};
