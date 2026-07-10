<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// config/filestorage.php (Requirement 8.1)
// ---------------------------------------------------------------------------

test('filestorage config has max_upload_bytes with default 10MB', function () {
    $value = config('filestorage.max_upload_bytes');

    expect($value)->toBeInt();
    expect($value)->toBe(10_485_760);
});

test('filestorage config has allowed_mime_types containing pdf and docx', function () {
    $types = config('filestorage.allowed_mime_types');

    expect($types)->toBeArray();
    expect($types)->toContain('application/pdf');
    expect($types)->toContain('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    expect($types)->toHaveCount(2);
});

test('filestorage config has retention_hours with default 24', function () {
    $value = config('filestorage.retention_hours');

    expect($value)->toBeInt();
    expect($value)->toBe(24);
});

test('filestorage config has rate_limit_upload with default 20', function () {
    $value = config('filestorage.rate_limit_upload');

    expect($value)->toBeInt();
    expect($value)->toBe(20);
});

test('filestorage config has rate_limit_delete with default 30', function () {
    $value = config('filestorage.rate_limit_delete');

    expect($value)->toBeInt();
    expect($value)->toBe(30);
});

test('filestorage config has rate_limit_download with default 60', function () {
    $value = config('filestorage.rate_limit_download');

    expect($value)->toBeInt();
    expect($value)->toBe(60);
});

test('filestorage config has pagination_per_page with default 15', function () {
    $value = config('filestorage.pagination_per_page');

    expect($value)->toBeInt();
    expect($value)->toBe(15);
});

test('filestorage config has all seven required keys', function () {
    $keys = array_keys(config('filestorage'));

    expect($keys)->toContain('max_upload_bytes');
    expect($keys)->toContain('allowed_mime_types');
    expect($keys)->toContain('retention_hours');
    expect($keys)->toContain('rate_limit_upload');
    expect($keys)->toContain('rate_limit_delete');
    expect($keys)->toContain('rate_limit_download');
    expect($keys)->toContain('pagination_per_page');
});
