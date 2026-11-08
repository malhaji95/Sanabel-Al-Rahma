<?php

use App\Models\Post;

/*
 | T-271 — found in the pre-launch security review of 8 November.
 |
 | `Post::sanitize()` guarded the news body with a deny-list that matched the
 | literal text «javascript:». It was got past four ways, each the same
 | trick: the browser decodes an attribute before it reads the scheme, and
 | the regex did not.
 |
 | All four were confirmed in Chromium — `a.protocol` read `javascript:` on
 | the sanitised output — so this was a live stored cross-site scripting
 | hole on a public page, reachable by anyone who may write a news item, and
 | fired at whoever clicked the link, an administrator included.
 |
 | The guard is an allow-list now, applied to the decoded value. These hold
 | both halves: that the four payloads lose their attribute, and that an
 | ordinary link still works.
 */
dataset('dangerous links', [
    'the plain one' => ['<a href="javascript:alert(1)">x</a>'],
    'an entity for the colon' => ['<a href="javascript&#58;alert(1)">x</a>'],
    'an entity for the first letter' => ['<a href="&#106;avascript:alert(1)">x</a>'],
    'a tab inside the word' => ['<a href="java&#9;script:alert(1)">x</a>'],
    'a newline inside the word' => ["<a href=\"java\nscript:alert(1)\">x</a>"],
    'the scheme in capitals' => ['<a href="JaVaScRiPt:alert(1)">x</a>'],
    'vbscript, entity-encoded' => ['<a href="vbscript&#58;msgbox(1)">x</a>'],
    'a data url on an image' => ['<img src="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">'],
    'a protocol-relative url' => ['<a href="//evil.example/x">x</a>'],
]);

it('leaves no address a browser would run', function (string $html) {
    $clean = Post::sanitize($html);

    // The tag may stay; the address may not.
    expect($clean)->not->toContain('href=')
        ->and($clean)->not->toContain('src=');
})->with('dangerous links');

dataset('ordinary links', [
    'an outside page' => ['<a href="https://example.org/news">خبر</a>', 'https://example.org/news'],
    'one of our own' => ['<a href="/how-we-work">كيف نعمل</a>', '/how-we-work'],
    'an anchor on the page' => ['<a href="#detail">تفصيل</a>', '#detail'],
    'an email address' => ['<a href="mailto:info@example.org">راسلنا</a>', 'mailto:info@example.org'],
    'a picture of ours' => ['<img src="/images/news/one.webp">', '/images/news/one.webp'],
    'a relative path' => ['<a href="news/one">واحد</a>', 'news/one'],
]);

it('keeps a link the editor meant', function (string $html, string $url) {
    expect(Post::sanitize($html))->toContain($url);
})->with('ordinary links');

it('still strips the things it always stripped', function () {
    $clean = Post::sanitize(
        '<script>alert(1)</script><p onclick="alert(1)">نص</p><style>body{}</style><iframe src="x"></iframe>'
    );

    expect($clean)->not->toContain('<script')
        ->and($clean)->not->toContain('onclick')
        ->and($clean)->not->toContain('<style')
        ->and($clean)->not->toContain('<iframe')
        // And keeps the words themselves.
        ->and($clean)->toContain('نص');
});

it('sanitises on the way into the table, not on the way out', function () {
    seedCore();

    $post = Post::create([
        'title_ar' => 'خبر',
        'slug' => 'khabar-'.uniqid(),
        'body_ar' => '<p>نص</p><a href="javascript&#58;alert(1)">x</a>',
        'is_published' => true,
    ]);

    // Stored clean, so a reader who never passes through the view is safe
    // too — an export, an API, a feed.
    expect($post->refresh()->body_ar)->not->toContain('href=')
        ->and($post->body_ar)->toContain('نص');
});
