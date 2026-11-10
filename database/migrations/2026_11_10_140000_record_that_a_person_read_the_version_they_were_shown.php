<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-292 — condition 6, 9 November: «إظهار السياسات والتعليمات المعتمدة
 * للموظفين والمندوبين، وتوثيق إقرارهم بالاطلاع على رقم الإصدار وتاريخه».
 *
 * The version number is the whole point. «قرأت السياسة» is worth nothing a
 * year later, because nobody can say which policy they read. So the
 * acknowledgement is of one version, and a new version asks everybody again
 * — which is also the only honest way to know who has not yet seen the
 * change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('governance_document_versions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Copied, not joined: the number they acknowledged, as it read then.
            $table->unsignedInteger('version_no');
            $table->timestamp('acknowledged_at');

            $table->unique(['version_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_acknowledgements');
    }
};
