<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 | T-243 — «كيف ستتعاملون مع الملفات القديمة التي سُجلت متخرج دون وجود سبب
 | يثبت التخرج. ما بدنا تظهر بتقاريرنا أسر متخرجة من الفقر وهي فعليًا مجرد
 | ملفات انتهت إجراءاتها», 4 November.
 |
 | The answer is not a guess. Before T-239 there were two methods writing
 | the status `graduated`: `CaseService::close()` and
 | `CaseService::graduate()`. The second has never had a caller — not a
 | button, not a command, not a seeder, and the status is not an editable
 | field on any form. So the only road to `graduated` that ever existed was
 | the «إغلاق الملف» button, which demands a delivery with proof before it
 | will do anything.
 |
 | Every row reading `graduated` today is therefore a closure that was
 | written with the wrong word, and this migration gives it the right one.
 | Nothing is being decided about those families: they are being described
 | the way they were actually ended.
 |
 | After this, `graduated` means what the association means by it and
 | nothing else, so a report that counts it counts families that grew out
 | of needing help — which is exactly what they asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('beneficiaries')->where('status', 'graduated')->update(['status' => 'closed']);
    }

    public function down(): void
    {
        /*
         | Deliberately empty. Once the two words are apart there is nothing
         | on a row that says which of them it used to be, so putting every
         | closure back under «متخرج» would re-create the very confusion
         | this was written to end.
         */
    }
};
