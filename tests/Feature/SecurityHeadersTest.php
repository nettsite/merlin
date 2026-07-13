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

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
});
