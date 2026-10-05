<?php

use App\Models\Post;

/*
 | T-36 — an editor writes the news from the panel: a title, a short summary,
 | a main picture, and a body that may carry pictures of its own.
 */

beforeEach(function () {
    seedCore();
});

it('shows the main picture and the editor summary on the news page', function () {
    Post::create([
        'slug' => 'first-families',
        'title_ar' => 'تغطية أولى عشر أسر',
        'excerpt_ar' => 'اكتملت الحاجة الشهرية لعشر أسر.',
        'body_ar' => '<p>نص طويل لا يصلح أن يكون ملخصًا.</p>',
        'image' => 'news/cover.jpg',
        'status' => 'published',
        'published_at' => now(),
    ]);

    $this->get(route('news'))
        ->assertOk()
        ->assertSee('تغطية أولى عشر أسر')
        ->assertSee('اكتملت الحاجة الشهرية لعشر أسر.')
        ->assertSee('news/cover.jpg')
        // The summary stands in for the body, which belongs on the article page.
        ->assertDontSee('نص طويل لا يصلح أن يكون ملخصًا');
});

it('falls back to the opening of the body when no summary was written', function () {
    Post::create([
        'slug' => 'health-network',
        'title_ar' => 'توسيع الشبكة الصحية',
        'body_ar' => '<p>انضم مركز طبي جديد إلى شبكة الخصومات.</p>',
        'status' => 'published',
        'published_at' => now(),
    ]);

    $this->get(route('news'))
        ->assertOk()
        // Stripped of its markup, so no tag leaks into the card.
        ->assertSee('انضم مركز طبي جديد إلى شبكة الخصومات.')
        ->assertDontSee('<p>انضم', false);
});

it('renders the body as rich text, pictures included', function () {
    Post::create([
        'slug' => 'launch',
        'title_ar' => 'إطلاق المنصة',
        'body_ar' => '<h2>البداية</h2><p>بدأ العمل في درعا.</p><img src="/storage/news/inline.jpg">',
        'status' => 'published',
        'published_at' => now(),
    ]);

    $this->get(route('post', 'launch'))
        ->assertOk()
        ->assertSee('<h2>البداية</h2>', false)
        ->assertSee('news/inline.jpg', false);
});

it('drops a script an editor account tried to plant in an article', function () {
    $post = Post::create([
        'slug' => 'poisoned',
        'title_ar' => 'خبر',
        'body_ar' => '<p>نص</p><script>fetch("/evil")</script><a href="javascript:alert(1)">اضغط</a>'
            .'<img src="x" onerror="alert(2)">',
        'status' => 'published',
        'published_at' => now(),
    ]);

    expect($post->body_ar)
        ->not->toContain('<script')
        ->not->toContain('onerror')
        ->not->toContain('javascript:')
        // The legitimate markup around it survives.
        ->toContain('<p>نص</p>');

    // The page carries the application's own scripts, so the check is for the
    // planted payload, not for the word.
    $this->get(route('post', 'poisoned'))
        ->assertOk()
        ->assertDontSee('fetch("/evil")', false)
        ->assertDontSee('alert(1)', false)
        ->assertDontSee('alert(2)', false);
});
