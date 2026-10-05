<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | A content account published straight to the public site, and a story about a
 | family could go up with nobody having agreed to it. A post now walks a path —
 | draft, review, approved, published, archived — and one that names a family
 | cannot be published until that family's consent is on the record.
 |
 | is_published stays as the derived flag the public queries already read, so
 | the status is the single decision and the flag follows it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('status')->default('draft')->after('is_published');
            $table->foreignId('reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->foreignId('approved_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');

            // The family whose story or picture this piece uses, and the consent
            // that lets it. Null for a post about nobody in particular.
            $table->foreignId('beneficiary_id')->nullable()->after('approved_at')->constrained()->nullOnDelete();
            $table->string('consent_signed_by_ar')->nullable()->after('beneficiary_id');
            $table->date('consent_signed_on')->nullable()->after('consent_signed_by_ar');
            $table->foreignId('consent_media_id')->nullable()->after('consent_signed_on');

            $table->index('status');
        });

        // Whatever was already live stays live.
        DB::table('posts')->where('is_published', true)->update(['status' => 'published']);
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('beneficiary_id');
            $table->dropColumn([
                'status', 'reviewed_at', 'approved_at',
                'consent_signed_by_ar', 'consent_signed_on', 'consent_media_id',
            ]);
        });
    }
};
