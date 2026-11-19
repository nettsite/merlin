<?php

it('sends security headers on web responses', function () {
    $response = $this->get('/login');

    $response->assertOk();

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->not->toBeNull()
        ->and($csp)->toContain("script-src 'self'")
        ->and($csp)->toContain("style-src 'self'")
        ->and($csp)->toContain("frame-ancestors 'self'")
        ->and($csp)->not->toContain('localhost:5173');

    expect($csp)->toMatch("#style-src [^;]*https://cdnjs\.cloudflare\.com/ajax/libs/font-awesome/4\.7\.0/#")
        ->and($csp)->toMatch("#font-src [^;]*https://cdnjs\.cloudflare\.com/ajax/libs/font-awesome/4\.7\.0/#")
        ->and($csp)->not->toMatch('#script-src [^;]*cdnjs#')
        ->and($csp)->toMatch('#script-src [^;]*https://cdn\.jsdelivr\.net/npm/quill@2\.0\.3/dist/#')
        ->and($csp)->toMatch('#style-src [^;]*https://cdn\.jsdelivr\.net/npm/quill@2\.0\.3/dist/#')
        ->and($csp)->not->toMatch('#https://cdn\.jsdelivr\.net/(?!npm/quill@2\.0\.3/dist/)#')
        ->and($csp)->not->toContain('grapesjs.com');

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
});
