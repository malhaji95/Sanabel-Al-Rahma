<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-89 — the blocks the home page is built from, as rows.
 *
 * The design the association approved is a page of repeated cards: two offers
 * at the top, eight ways to give, six promises, and the footer's own links.
 * Each is a title, a line of text, a picture or an icon, and somewhere to go.
 * One table rather than four: the shape is identical and `block` says which
 * band of the page a row belongs to, so adding a ninth way to give is an
 * insert from the panel and not a release.
 *
 * The hero slides are not here — they are `banners`, which already carry an
 * image, a title, a line and a destination.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_cards', function (Blueprint $table) {
            $table->id();
            // feature | tile | advantage | footer_link
            $table->string('block');
            $table->string('title_ar');
            $table->text('body_ar')->nullable();
            // A path on the public disk, as banners and pages carry it.
            $table->string('image')->nullable();
            // The name of a file under public/icons, drawn when there is no
            // picture — which is how a card stays presentable before the
            // association has supplied artwork for it.
            $table->string('icon')->nullable();
            $table->string('link')->nullable();
            $table->string('badge_ar')->nullable();
            $table->boolean('is_published')->default(true);
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['block', 'is_published', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_cards');
    }
};
