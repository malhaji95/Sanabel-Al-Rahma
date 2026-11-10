<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-290 — conditions 3 and 4 of the governance section, 9 November:
 * «تحديد صلاحيات الاطلاع والرفع والتعديل والتنزيل والاعتماد لكل وثيقة، وليس
 * حسب التصنيف العام فقط», and «تحديد جهة الاعتماد بحسب نوع الوثيقة
 * والصلاحيات النظامية، وليس حصر الاعتماد برئيس مجلس الأمناء».
 *
 * Two tables, because the association asked for two different things.
 *
 * A grant is the exception: this role may do this one thing to this one
 * document, whatever its classification would otherwise allow. The
 * classification stays the floor and the grant lifts a single document above
 * it, which is what «وليس حسب التصنيف العام فقط» asks for.
 *
 * A route is the rule: documents of this kind are approved by this role. It
 * is per kind and not per document, because an association that must name an
 * approver for every minute of every meeting will stop naming them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_document_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('governance_documents')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            // view | upload | edit | download | approve
            $table->string('ability');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One row per role per ability per document, and no second one.
            $table->unique(['document_id', 'role_id', 'ability']);
        });

        Schema::create('governance_approval_routes', function (Blueprint $table) {
            $table->id();
            // The document kind this route decides, from GovernanceDocument::KINDS.
            $table->string('kind');
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['kind', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_approval_routes');
        Schema::dropIfExists('governance_document_grants');
    }
};
