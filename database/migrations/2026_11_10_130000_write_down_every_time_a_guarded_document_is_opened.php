<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-291 — condition 5, 9 November: «تسجيل فتح وتنزيل الوثائق السرية
 * ومحاولات الوصول غير المصرح بها، إضافة إلى التعديلات والاعتمادات».
 *
 * The edits and the approvals are already in the audit log, which rule 4
 * says is for writes. A read is not a write, and the same rule says reads
 * are not logged in phase 1. So this is its own book rather than a widening
 * of that one: the rule stays as it is and the association gets what they
 * asked for.
 *
 * It holds who, which document, which version, what they did, and when. A
 * refusal is written here too — «محاولات الوصول غير المصرح بها» is the half
 * of this that matters most, because a successful read by the right person
 * tells you nothing and a refused one tells you something.
 *
 * Rule 10: no personal data. The actor is an id, like every other actor
 * reference in the system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('governance_documents')->cascadeOnDelete();
            $table->foreignId('version_id')->nullable()->constrained('governance_document_versions')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // opened | downloaded | refused
            $table->string('action');
            // The ability that was asked for, when one was refused.
            $table->string('ability')->nullable();
            $table->string('classification');
            $table->timestamp('created_at')->nullable();

            $table->index(['document_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_access_logs');
    }
};
