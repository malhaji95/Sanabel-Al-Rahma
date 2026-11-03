<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-239 — «الملف المغلق / هناك التباس فيه اتوقع», 3 November. Their
 | instinct was right, and the confusion was ours.
 |
 | The screen carried a button reading «إغلاق الملف» and announced «تم
 | إغلاق الملف», while `CaseService::close()` wrote the status `graduated`,
 | which the same screen prints as «متخرج». So the word on the button and
 | the word on the file disagreed, and worse: `graduate()` wrote the very
 | same value, so a file closed after a delivery was proved and a family
 | that had grown out of needing help were afterwards indistinguishable.
 |
 | They are two different endings and now they are two statuses. Rows
 | already written stay `graduated`, because nothing in them says which of
 | the two they were and guessing would put a word in the association's
 | mouth.
 |
 | And an ending is no longer final: `case_reopen_requests` carries who
 | asked for a file to be reopened, which of the association's own reasons
 | they gave, who decided, and where the file went back to. Two hands, like
 | every other decision on a file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_reopen_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            // The ending the file was in when the request was raised, kept on
            // the row: the file moves on and the reason it was reopened is
            // only legible beside the state it was reopened from.
            $table->string('from_status');
            // One of the association's own reasons, stored as the words they
            // wrote. A list they edit is data, so the row keeps the text
            // rather than a key that could lose its meaning.
            $table->text('reason_ar');
            $table->text('note_ar')->nullable();
            $table->string('status')->default('pending'); // pending|approved|refused
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note_ar')->nullable();
            // Where the file was put back to, written at the moment of the
            // decision. The rule behind it may move; what happened may not.
            $table->string('to_status')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'beneficiary_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_reopen_requests');
    }
};
