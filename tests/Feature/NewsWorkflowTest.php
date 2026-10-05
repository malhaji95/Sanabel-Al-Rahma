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

/*
 | Who may put a piece in front of a reader, and what happens to a piece that
 | is changed after it was signed off (decision of 5 October).
 */

it('refuses to let an editor approve or publish their own piece', function () {
    $this->actingAs(userWithRole('content_manager'));

    $post = Post::create([
        'slug' => 'editor-piece',
        'title_ar' => 'خبر من المحرر',
        'body_ar' => '<p>نص</p>',
        'status' => 'draft',
    ]);

    foreach (Post::SIGNED_OFF as $status) {
        expect(fn () => $post->update(['status' => $status]))
            ->toThrow(RuntimeException::class);
    }

    // As far as review, and no further.
    $post->update(['status' => 'review']);

    expect($post->refresh()->status)->toBe('review')
        ->and($post->reviewed_by)->not->toBeNull()
        ->and($post->is_published)->toBeFalse();
});

it('records who approved a piece when it goes out', function () {
    $approver = userWithRole('admin');

    $post = Post::create([
        'slug' => 'approved-piece',
        'title_ar' => 'خبر معتمد',
        'body_ar' => '<p>نص</p>',
        'status' => 'review',
    ]);

    $this->actingAs($approver);
    $post->update(['status' => 'published']);

    expect($post->refresh()->is_published)->toBeTrue()
        ->and($post->approved_by)->toBe($approver->id)
        ->and($post->approved_at)->not->toBeNull();

    $this->get(route('news'))->assertOk()->assertSee('خبر معتمد');
});

it('sends a published piece back to review when an editor changes its text or picture', function () {
    $approver = userWithRole('admin');
    $editor = userWithRole('content_manager');

    foreach (['title_ar' => 'عنوان جديد', 'excerpt_ar' => 'وصف جديد', 'body_ar' => '<p>نص آخر</p>', 'image' => 'news/new.jpg'] as $field => $value) {
        $this->actingAs($approver);

        $post = Post::create([
            'slug' => 'live-'.$field,
            'title_ar' => 'خبر منشور',
            'body_ar' => '<p>نص</p>',
            'status' => 'published',
        ]);

        $this->actingAs($editor);
        $post->update([$field => $value]);

        expect($post->refresh()->status)->toBe('review')
            ->and($post->is_published)->toBeFalse()
            // The old approval does not carry over to the new text.
            ->and($post->approved_by)->toBeNull()
            ->and($post->approved_at)->toBeNull();

        $this->get(route('post', $post->slug))->assertNotFound();
    }
});

it('leaves a published piece alone when the edit is not content', function () {
    $post = Post::create([
        'slug' => 'ordering',
        'title_ar' => 'خبر منشور',
        'body_ar' => '<p>نص</p>',
        'status' => 'published',
    ]);

    $this->actingAs(userWithRole('content_manager'));
    $post->update(['sort_order' => 5]);

    expect($post->refresh()->status)->toBe('published')
        ->and($post->is_published)->toBeTrue();
});

it('keeps a piece live when the approver is the one amending it', function () {
    $post = Post::create([
        'slug' => 'amended',
        'title_ar' => 'خبر منشور',
        'body_ar' => '<p>نص</p>',
        'status' => 'published',
    ]);

    $approver = userWithRole('admin');
    $this->actingAs($approver);
    $post->update(['body_ar' => '<p>نص مصحَّح</p>']);

    expect($post->refresh()->status)->toBe('published')
        ->and($post->is_published)->toBeTrue()
        // Their edit is itself the approval, so the signature is theirs.
        ->and($post->approved_by)->toBe($approver->id);

    $this->get(route('post', 'amended'))->assertOk()->assertSee('نص مصحَّح', false);
});
