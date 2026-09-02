<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('region_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->constrained()->cascadeOnDelete();
            $table->string('person_class'); // adult|child|elderly
            $table->bigInteger('amount'); // smallest unit
            $table->string('currency', 3)->default('SYP');
            $table->date('effective_from');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['region_id', 'person_class', 'effective_from']);
        });

        Schema::create('region_rent_reference', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->constrained()->cascadeOnDelete();
            $table->string('family_size_band'); // e.g. 1-3, 4-6, 7+
            $table->bigInteger('reference_rent');
            $table->string('currency', 3)->default('SYP');
            $table->date('effective_from');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['region_id', 'family_size_band', 'effective_from']);
        });

        Schema::create('adjustments_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->string('name_ar');
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('SYP');
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            $table->date('effective_from');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['key', 'region_id', 'effective_from']);
        });

        Schema::create('scoring_weights', function (Blueprint $table) {
            $table->id();
            $table->string('factor_key'); // F,M,V,H,U,D,B and sub-weights
            $table->decimal('weight', 8, 4);
            $table->date('effective_from');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['factor_key', 'effective_from']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value_json');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('scoring_weights');
        Schema::dropIfExists('adjustments_catalog');
        Schema::dropIfExists('region_rent_reference');
        Schema::dropIfExists('region_rates');
    }
};
