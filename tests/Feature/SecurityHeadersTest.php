<?php

/*
 | T-56 — APP-06 and IAM-02 of the security roadmap: the response headers a
 | browser needs in order to defend the page, and a session cookie that cannot
 | travel in clear.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('sends the defensive headers on a public page', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');

    expect($response->headers->get('Permissions-Policy'))
        ->toContain('camera=()')
        ->toContain('microphone=()')
        // The field app needs it for the survey's GPS question.
        ->toContain('geolocation=(self)');
})->group('APP-06');

it('sends them on the API too, not only on the rendered pages', function () {
    // 401 is the point: the headers are appended globally, so even a refusal
    // carries them.
    $this->getJson('/api/cases')
        ->assertUnauthorized()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY');
})->group('APP-06');

it('states a content policy that allows no other origin', function () {
    $policy = $this->get('/')->headers->get('Content-Security-Policy');

    expect($policy)->not->toBeNull()
        ->and($policy)->toContain("default-src 'self'")
        // Nothing may be framed, and no form may be pointed elsewhere.
        ->and($policy)->toContain("frame-ancestors 'none'")
        ->and($policy)->toContain("form-action 'self'")
        ->and($policy)->toContain("object-src 'none'")
        ->and($policy)->toContain("base-uri 'self'")
        ->and($policy)->toContain('upgrade-insecure-requests')
        // The field app registers a service worker.
        ->and($policy)->toContain("worker-src 'self' blob:");
})->group('APP-02', 'PAY-06');

it('allows no third-party script or style origin at all', function () {
    $policy = $this->get('/')->headers->get('Content-Security-Policy');

    // Livewire's inline script and Alpine's `new Function` keep 'unsafe-inline'
    // and 'unsafe-eval' on script-src until the front end is built with
    // nonces. What must not creep in is an http(s) origin: that is the line
    // between "a successful injection cannot call home" and "it can".
    preg_match('/script-src ([^;]+)/', $policy, $script);
    preg_match('/style-src ([^;]+)/', $policy, $style);
    preg_match('/connect-src ([^;]+)/', $policy, $connect);

    foreach ([$script[1], $style[1], $connect[1]] as $sources) {
        expect($sources)->not->toContain('http://')->not->toContain('https://');
    }
})->group('APP-02', 'PAY-06');

it('sends HSTS over TLS and never over plain http', function () {
    expect($this->get('/')->headers->get('Strict-Transport-Security'))->toBeNull();

    $secure = $this->get('https://localhost/');

    expect($secure->headers->get('Strict-Transport-Security'))
        ->toContain('max-age=31536000')
        ->toContain('includeSubDomains')
        // Submitted once and painful to undo: their call, on their domain.
        ->not->toContain('preload');
})->group('APP-06');

it('can be put in report-only mode to see what a tighter policy would break', function () {
    config(['sanabel.security.csp.report_only' => true]);

    $response = $this->get('/');

    expect($response->headers->get('Content-Security-Policy'))->toBeNull()
        ->and($response->headers->get('Content-Security-Policy-Report-Only'))->not->toBeNull();
})->group('APP-06');

it('ships a session cookie that cannot travel in clear, and is encrypted at rest', function () {
    // Laravel's own default leaves Secure unset, so the cookie was eligible to
    // be sent over plain http. The image carries these, because creating a
    // service from the dashboard never reads render.yaml.
    $dockerfile = file_get_contents(base_path('Dockerfile'));

    expect($dockerfile)->toContain('SESSION_SECURE_COOKIE=true')
        ->toContain('SESSION_ENCRYPT=true')
        ->toContain('SESSION_SAME_SITE=lax');

    // http_only is Laravel's default and must stay on: no script reads it.
    expect(config('session.http_only'))->toBeTrue();
})->group('IAM-02');

it('names a layout on every full-page component, so the policy is on the page', function () {
    /*
     | A component rendered without the page layout is served without the
     | document shell, which is where the meta tags and the asset links live.
     | More to the point, it answers 500: Livewire falls back to its own
     | default layout, which this project does not have. Found by opening the
     | pages in a browser, which no test did; FullPageRoutesTest now opens
     | each one over HTTP, and this holds the cause rather than the symptom.
     */
    foreach (glob(base_path('app/Livewire/*.php')) as $component) {
        $source = file_get_contents($component);

        // Only the ones routed as a page; the rest are rendered inside one.
        if (! str_contains(file_get_contents(base_path('routes/web.php')), basename($component, '.php'))) {
            continue;
        }

        expect(str_contains($source, "layout('layouts.app')"))->toBeTrue(
            basename($component).' is routed as a page and names no layout',
        );
    }
})->group('APP-06');

it('reads a proxied TLS request as secure, so the cookie and HSTS are set', function () {
    // Render terminates TLS at its edge and forwards over http. Without the
    // trusted proxy the app reads every request as insecure and silently never
    // sets a Secure cookie.
    $app = file_get_contents(base_path('bootstrap/app.php'));

    expect($app)->toContain('trustProxies');
})->group('IAM-02');

/*
 | T-84 — APP-07, and AT-05 of the acceptance list: «وصول رد مستخدم إلى جلسة
 | مستخدم آخر عبر الـ cache».
 |
 | Laravel's own default on a page with a session is «no-cache, private». That
 | keeps it out of a shared cache and makes the browser revalidate, but it
 | still lets the browser write it to disk — so the back button after signing
 | out, on a shared office machine or a borrowed phone, redraws a family file
 | from the disk cache. `no-store` is the difference.
 */

/** Everything the headers must say for a response that may never be kept. */
function assertNotStored($response, string $where): void
{
    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect(str_contains($cacheControl, 'no-store'))->toBeTrue($where.' may be stored: '.$cacheControl);
    expect(str_contains($cacheControl, 'private'))->toBeTrue($where.' is not marked private: '.$cacheControl);
}

it('never lets a browser store a page served to a signed-in account', function () {
    $donor = userWithRole('donor', ['region_id' => $this->region->id]);
    $account = userWithRole('beneficiary', ['region_id' => $this->region->id]);
    $case = publishedCase($this->region);
    $case->forceFill(['user_id' => $account->getKey()])->save();
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);

    foreach ([
        ['/portal', $donor],
        ['/portal/basket', $donor],
        ['/portal/family', $account],
        ['/field', $delegate],
        // A public page becomes personal the moment it is served to an
        // account: the header carries their name and their basket.
        ['/cases', $donor],
        ['/', $donor],
    ] as [$path, $user]) {
        // Each row is a different person, so each gets their own session.
        assertNotStored($this->actingFreshly($user)->get($path), $path);
    }
})->group('APP-07');

it('never lets a browser store an internal panel screen', function () {
    passTwoFactor();

    assertNotStored(
        $this->actingAs(userWithRole('admin'))->get('/admin'),
        '/admin',
    );
})->group('APP-07');

it('never lets a browser store an API response', function () {
    $donor = userWithRole('donor', ['region_id' => $this->region->id]);
    App\Models\Donor::factory()->create(['user_id' => $donor->id]);

    assertNotStored(
        $this->actingAs($donor, 'sanctum')->getJson(route('donor.cases.index', 'monthly')),
        'donor.cases.index',
    );

    // Including a refusal: an unauthenticated 401 still carries the headers,
    // and the path rule is what covers it rather than the account rule.
    assertNotStored($this->getJson('/api/cases'), 'api/cases (401)');
})->group('APP-07');

it('never lets anything store the signed media route or a login form', function () {
    // The route that serves a family's documents. A 403 on an unsigned URL is
    // the right answer and still must not be kept: the rule is the path, not
    // the status.
    assertNotStored($this->get('/storage/whatever'), 'storage');

    foreach (['/login', '/admin/login'] as $path) {
        assertNotStored($this->get($path), $path);
    }
})->group('APP-07');

it('says Vary: Cookie, so a cache that ignores no-store still cannot cross sessions', function () {
    $response = $this->get('/login');

    expect($response->headers->get('Vary'))->toContain('Cookie');
})->group('APP-07');

it('leaves the public site and the front-end assets cacheable', function () {
    // The other half of the rule. A guest reading the news reads what everyone
    // reads, and no-store on livewire.js means a fresh download per page.
    $guest = (string) $this->get('/')->headers->get('Cache-Control');

    expect(str_contains($guest, 'no-store'))->toBeFalse('the public home page is no-store: '.$guest);

    $asset = (string) $this->get('/livewire/livewire.js')->headers->get('Cache-Control');

    expect(str_contains($asset, 'no-store'))->toBeFalse('livewire.js is no-store: '.$asset);
})->group('APP-07');

it('serves each household its own page and never the other household from a cache', function () {
    // AT-05 itself, at the layer this application controls: two accounts, one
    // URL, one process, back to back. Each must see its own file and nothing
    // of the other's — a rendered page held anywhere between them would show
    // up here as the first file number appearing in the second response.
    $first = userWithRole('beneficiary', ['region_id' => $this->region->id]);
    $second = userWithRole('beneficiary', ['region_id' => $this->region->id]);

    $firstCase = publishedCase($this->region);
    $firstCase->forceFill(['user_id' => $first->getKey()])->save();

    $secondCase = publishedCase($this->region);
    $secondCase->forceFill(['user_id' => $second->getKey()])->save();

    $firstBody = $this->actingFreshly($first)->get('/portal/family')->getContent();
    $secondBody = $this->actingFreshly($second)->get('/portal/family')->getContent();

    expect($firstBody)->toContain($firstCase->file_number)
        ->and($firstBody)->not->toContain($secondCase->file_number)
        ->and($secondBody)->toContain($secondCase->file_number)
        ->and($secondBody)->not->toContain($firstCase->file_number);
})->group('APP-07', 'AUTH-02');

/*
 | T-91 — found by walking the live demo. It was open to search engines: the
 | robots file allowed everything and no response said otherwise, so generated
 | families under the association's name were a crawl away from a search
 | result.
 */
it('tells search engines to stay away from a deployment seeded with generated families', function () {
    config(['sanabel.allow_indexing' => false]);

    $header = (string) $this->get('/')->headers->get('X-Robots-Tag');

    expect($header)->toContain('noindex')
        ->and($header)->toContain('nofollow');
})->group('APP-06');

it('says nothing of the sort on the association own deployment', function () {
    config(['sanabel.allow_indexing' => true]);

    expect($this->get('/')->headers->get('X-Robots-Tag'))->toBeNull();
})->group('APP-06');

it('follows the demo seed unless it is told otherwise', function () {
    // The association's real deployment sets DEMO_SEED=false and is found like
    // any other site; the demo container is the one that hides.
    $dockerfile = file_get_contents(base_path('Dockerfile'));

    expect($dockerfile)->toContain('DEMO_SEED=true')
        ->and(file_get_contents(base_path('config/sanabel.php')))
        ->toContain("env('SANABEL_ALLOW_INDEXING', ! env('DEMO_SEED', false))");
})->group('APP-06');

/*
 | T-199 — the front page carries a Livewire component since T-183, and
 | Livewire marks every response that boots one `no-store`. That made the
 | most-visited page on the site unstorable by every browser in front of it,
 | which is the opposite of what the counter was for.
 */
it('leaves a public page storable even when Livewire rendered it', function () {
    $control = (string) $this->get('/')->headers->get('Cache-Control');

    expect($this->get('/')->assertOk()->getContent())
        // The page really is a Livewire page; the guard is worthless if it
        // ever stops being one.
        ->toContain('wire:id')
        ->and(str_contains($control, 'no-store'))->toBeFalse($control)
        // Storable, not freely shareable: it revalidates, and no shared cache
        // may hold it.
        ->and($control)->toContain('no-cache')
        ->and($control)->toContain('private');
})->group('APP-07');

it('leaves no 1990 Expires behind to say no-store in another way', function () {
    $response = $this->get('/');

    expect($response->headers->get('Expires'))->toBeNull()
        ->and($response->headers->get('Pragma'))->toBeNull();
})->group('APP-07');

it('still refuses to store the same page once somebody has signed in', function () {
    $this->actingAs(userWithRole('donor'));

    expect((string) $this->get('/')->headers->get('Cache-Control'))->toContain('no-store');
})->group('APP-07');
