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
