<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-283 — «قسم الحوكمة والوثائق ضمن لوحة الإدارة الحالية», 9 November, and the
 * first of its eight conditions: «حفظ جميع إصدارات الوثائق المعتمدة من المرحلة
 * الأولى، مع منع تعديل النسخة المعتمدة مباشرة».
 *
 * A document is the thing that keeps its identity — «سياسة الخصوصية»,
 * «محضر الجلسة الرابعة» — and a version is one approved state of it. The
 * association never edits an approved version: they write a new one beside
 * it, and the old one becomes `superseded` and stays readable for ever. This
 * is the same shape the assessments took in T-278, for the same reason: a
 * record that can be changed after approval cannot be audited.
 *
 * The file itself goes to the existing private media store, which already
 * scans, signs and expires. Nothing about that is rebuilt here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_documents', function (Blueprint $table) {
            $table->id();
            $table->string('title_ar');
            // policy | procedure | decision | minutes | contract | license | other
            $table->string('kind');
            // public | internal | confidential | secret  (condition 2)
            $table->string('classification')->default('internal');
            // draft | pending_approval | approved | archived
            $table->string('status')->default('draft');
            $table->text('purpose_ar')->nullable();
            // Condition 6: whether staff and delegates must record that they read it.
            $table->boolean('requires_acknowledgement')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['kind', 'status']);
            $table->index('classification');
        });

        Schema::create('governance_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('governance_documents')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->text('summary_ar')->nullable();
            // draft | pending_approval | approved | superseded | rejected
            $table->string('status')->default('draft');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_note_ar')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // One number per document, and it is the number people cite.
            $table->unique(['document_id', 'version_no']);
            $table->index(['document_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_document_versions');
        Schema::dropIfExists('governance_documents');
    }
};
