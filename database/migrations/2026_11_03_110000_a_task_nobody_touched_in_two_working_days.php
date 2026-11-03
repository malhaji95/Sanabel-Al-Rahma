<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-240 — «نعتمد مدة يومين عمل … إذا انتهت المدة دون معالجة، يصل تنبيه
 | لمسؤول البيانات، وإشعار للمدير التنفيذي ونائبه … وتسجيل وقت التنبيه
 | والإجراءات المتخذة في سجل النظام، وإيقاف التنبيه تلقائيًا عند إنجاز
 | المهمة», 3 November.
 |
 | One column, because one is enough. The alert is computed from the queue
 | itself on every run, so it stops of its own accord the moment the
 | request is decided — a request that is no longer pending is never
 | found. What the column adds is that it is sent once: `alerted_at` is
 | the time the association asked to have recorded, and it is also what
 | keeps the same row from waking four people every morning.
 |
 | The audit log holds the rest: the alert as an entry of its own, and the
 | decision afterwards as the entry `Auditable` has always written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_requests', function (Blueprint $table) {
            $table->timestamp('alerted_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('change_requests', function (Blueprint $table) {
            $table->dropColumn('alerted_at');
        });
    }
};
