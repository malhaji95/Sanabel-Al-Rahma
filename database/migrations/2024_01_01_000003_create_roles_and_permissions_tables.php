<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // beneficiary, delegate, ...
            $table->string('name_ar');
            $table->boolean('is_read_only')->default(false);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // create_case, verify_payment, ...
            $table->string('name_ar');
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->string('scope')->default('all'); // all|own|area
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('email')->constrained()->nullOnDelete();
            $table->foreignId('region_id')->nullable()->after('role_id')->constrained()->nullOnDelete();
            $table->foreignId('association_id')->nullable()->after('region_id');
            $table->string('phone_encrypted')->nullable()->after('association_id');
            $table->boolean('is_active')->default(true)->after('phone_encrypted');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropConstrainedForeignId('region_id');
            $table->dropColumn(['association_id', 'phone_encrypted', 'is_active', 'deleted_at']);
        });
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
