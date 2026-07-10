<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// formatFileSize() helper (Requirement 2.3)
// ---------------------------------------------------------------------------

// Ensure the helper is loaded (AppServiceProvider loads it in production)
require_once __DIR__ . '/../../app/Helpers/FileHelpers.php';

test('formatFileSize returns bytes for values below 1024', function () {
    expect(formatFileSize(0))->toBe('0 B');
    expect(formatFileSize(1))->toBe('1 B');
    expect(formatFileSize(512))->toBe('512 B');
    expect(formatFileSize(1023))->toBe('1023 B');
});

test('formatFileSize returns KB for values from 1024 to 1048575', function () {
    expect(formatFileSize(1024))->toBe('1 KB');
    expect(formatFileSize(1536))->toBe('1.5 KB');
    expect(formatFileSize(1048575))->toBe('1024 KB');
});

test('formatFileSize returns MB for values at or above 1048576', function () {
    expect(formatFileSize(1048576))->toBe('1 MB');
    expect(formatFileSize(1572864))->toBe('1.5 MB');
    expect(formatFileSize(10485760))->toBe('10 MB');
});

test('formatFileSize rounds KB to two decimal places', function () {
    // 1025 bytes → 1025/1024 = 1.0009765625 → rounds to 1 KB
    expect(formatFileSize(1025))->toBe('1 KB');
    // 1126 bytes → 1126/1024 = 1.099609375 → rounds to 1.1 KB
    expect(formatFileSize(1126))->toBe('1.1 KB');
});

test('formatFileSize rounds MB to two decimal places', function () {
    // 1050624 bytes → 1050624/1048576 = 1.001953125 → rounds to 1 MB
    expect(formatFileSize(1050624))->toBe('1 MB');
});

test('formatFileSize boundary — 1023 is bytes, 1024 is KB', function () {
    expect(formatFileSize(1023))->toEndWith(' B');
    expect(formatFileSize(1024))->toEndWith(' KB');
});

test('formatFileSize boundary — 1048575 is KB, 1048576 is MB', function () {
    expect(formatFileSize(1048575))->toEndWith(' KB');
    expect(formatFileSize(1048576))->toEndWith(' MB');
});
