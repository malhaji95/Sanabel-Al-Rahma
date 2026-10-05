<?php

use App\Models\Post;

/*
 | The path a piece walks before it is public, and the consent that lets a
 | family's story be told at all (decisions of 4 October).
 */

beforeEach(function () {
    seedCore();
});

it('keeps a piece off the public site until it is published', function () {
    foreach (['draft', 'review', 'approved', 'archived'] as $status) {
        $post = Post::create([
            'slug' => 'piece-'.$status,
            'title_ar' => 'خبر '.$status,
            'body_ar' => '<p>نص</p>',
            'status' => $status,
        ]);

        expect($post->is_published)->toBeFalse();

        $this->get(route('news'))->assertOk()->assertDontSee('خبر '.$status);
        $this->get(route('post', $post->slug))->assertNotFound();
    }
});

it('puts a piece on the public site the moment it is published', function () {
    $post = Post::create([
        'slug' => 'launched',
        'title_ar' => 'إطلاق المنصة',
        'body_ar' => '<p>نص</p>',
        'status' => 'approved',
    ]);

    $this->get(route('news'))->assertOk()->assertDontSee('إطلاق المنصة');

    $post->update(['status' => 'published']);

    expect($post->refresh()->is_published)->toBeTrue()
        // The reader's date is set on the way out, not left empty.
        ->and($post->published_at)->not->toBeNull();

    $this->get(route('news'))->assertOk()->assertSee('إطلاق المنصة');
});

it('takes an archived piece back off the site', function () {
    $post = Post::create([
        'slug' => 'old-news',
        'title_ar' => 'خبر قديم',
        'body_ar' => '<p>نص</p>',
        'status' => 'published',
    ]);

    $this->get(route('post', 'old-news'))->assertOk();

    $post->update(['status' => 'archived']);

    $this->get(route('post', 'old-news'))->assertNotFound();
});

it('refuses to publish a family story without that family consent', function () {
    $case = publishedCase(regionWithRates());

    $post = Post::create([
        'slug' => 'a-family',
        'title_ar' => 'قصة أسرة',
        'body_ar' => '<p>نص</p>',
        'status' => 'approved',
        'beneficiary_id' => $case->id,
    ]);

    expect(fn () => $post->update(['status' => 'published']))
        ->toThrow(RuntimeException::class);

    // A name alone is not consent; the date and the paper are part of it.
    expect(fn () => $post->update([
        'status' => 'published',
        'consent_signed_by_ar' => 'أبو محمد',
    ]))->toThrow(RuntimeException::class);

    $post->update([
        'status' => 'published',
        'consent_signed_by_ar' => 'أبو محمد',
        'consent_signed_on' => now()->subDay(),
        'consent_media_id' => 7,
    ]);

    expect($post->refresh()->is_published)->toBeTrue();
});

it('leaves a piece about nobody in particular alone', function () {
    $post = Post::create([
        'slug' => 'general',
        'title_ar' => 'خبر عام',
        'body_ar' => '<p>نص</p>',
        'status' => 'published',
    ]);

    expect($post->is_published)->toBeTrue()
        ->and($post->beneficiary_id)->toBeNull();
});

it('shows an unpublished piece to an editor and to nobody else', function () {
    Post::create([
        'slug' => 'still-a-draft',
        'title_ar' => 'مسودة',
        'body_ar' => '<p>نص لم يُنشر بعد</p>',
        'status' => 'draft',
    ]);

    // A visitor who guesses the address gets nothing.
    $this->get(route('post.preview', 'still-a-draft'))->assertRedirect();

    $this->actingAs(userWithRole('content_manager'))
        ->get(route('post.preview', 'still-a-draft'))
        ->assertOk()
        ->assertSee('نص لم يُنشر بعد', false)
        ->assertSee(__('sanabel.post.preview_notice'));

    // A delegate has no business reading unpublished content.
    $this->actingAs(userWithRole('delegate'))
        ->get(route('post.preview', 'still-a-draft'))
        ->assertForbidden();
});
