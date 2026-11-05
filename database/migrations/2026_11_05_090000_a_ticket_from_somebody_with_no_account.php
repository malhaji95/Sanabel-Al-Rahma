<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | T-257 — «تواصل معنا», the association's own interactive mock-up of
 | 5 November.
 |
 | Their page is not a page: it is a ticket desk. A visitor opens a ticket
 | and gets a reference, follows it with that reference and the contact
 | they left, and a separate path carries a confidential report that
 | general support must not be able to read at all.
 |
 | Most of that already exists here as `complaints`: a unique reference, a
 | category, an owner, a status, a resolution, and the rule that a
 | complaint is never assigned to the person it is about. What it has
 | never had is somebody with no account. `submitted_by` points at a user,
 | and the whole point of a contact page is the visitor who has not signed
 | up for anything.
 |
 | So the reporter's own details go on the row: a name, a telephone number
 | encrypted at rest like every other number in this system (rule 10) with
 | a hash beside it so a ticket can be found without decrypting the table,
 | an address, and what they said they are. And `is_confidential`, which
 | is the one column the whole separation hangs on.
 |
 | Two rows written by `disputeReceipt()` are corrected on the way past.
 | It has been writing `category = financial` and `status = open` since it
 | was built, and neither word is in either list — so those tickets have
 | been drawing `sanabel.complaint_status.open` on the panel, in English,
 | where a status should be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->string('reporter_name_ar')->nullable()->after('submitted_by');
            $table->text('reporter_phone_encrypted')->nullable()->after('reporter_name_ar');
            $table->string('reporter_phone_hash')->nullable()->after('reporter_phone_encrypted');
            $table->string('reporter_email')->nullable()->after('reporter_phone_hash');
            $table->string('reporter_role')->nullable()->after('reporter_email');
            $table->boolean('is_confidential')->default(false)->after('category');
            $table->foreignId('attachment_media_id')->nullable()->after('resolution_ar')
                ->constrained('media')->nullOnDelete();

            $table->index('reporter_phone_hash');
            $table->index(['is_confidential', 'status']);
        });

        DB::table('complaints')->where('category', 'financial')->update(['category' => 'payment']);
        DB::table('complaints')->where('status', 'open')->update(['status' => 'new']);
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attachment_media_id');
            $table->dropIndex(['reporter_phone_hash']);
            $table->dropIndex(['is_confidential', 'status']);
            $table->dropColumn([
                'reporter_name_ar', 'reporter_phone_encrypted', 'reporter_phone_hash',
                'reporter_email', 'reporter_role', 'is_confidential',
            ]);
        });
    }
};
