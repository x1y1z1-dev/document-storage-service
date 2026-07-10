<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

// ---------------------------------------------------------------------------
// Security Headers Middleware (Requirement 11.8)
// ---------------------------------------------------------------------------

test('security headers are present on the index page response', function () {
    $response = $this->get('/');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

test('security headers are present on the health endpoint response', function () {
    $response = $this->get('/health');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

test('X-Content-Type-Options is nosniff', function () {
    $response = $this->get('/health');

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

test('X-Frame-Options is DENY', function () {
    $response = $this->get('/health');

    expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
});

test('Referrer-Policy is strict-origin-when-cross-origin', function () {
    $response = $this->get('/health');

    expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
});
