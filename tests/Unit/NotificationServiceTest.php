<?php

declare(strict_types=1);

use App\DTO\DeletionEvent;
use App\Services\NotificationService;

// ---------------------------------------------------------------------------
// NotificationService::buildPayload()
// ---------------------------------------------------------------------------

test('buildPayload returns valid UTF-8 JSON with all required fields', function () {
    $service = new NotificationService();

    $event = new DeletionEvent(
        filename: 'report.pdf',
        fileSizeBytes: 204800,
        uploadedAt: '2025-01-10T12:00:00Z',
        deletedAt: '2025-01-11T12:00:01Z',
        deletionReason: 'manual',
        notifyEmail: 'admin@example.com',
    );

    $payload = $service->buildPayload($event);

    // Must be valid JSON
    $decoded = json_decode($payload, true);
    expect(json_last_error())->toBe(JSON_ERROR_NONE);

    // Must contain exactly the required fields
    expect($decoded)->toHaveKeys([
        'filename',
        'file_size_bytes',
        'uploaded_at',
        'deleted_at',
        'deletion_reason',
        'notify_email',
    ]);
});

test('buildPayload maps DTO fields to the correct JSON keys', function () {
    $service = new NotificationService();

    $event = new DeletionEvent(
        filename: 'document.docx',
        fileSizeBytes: 98304,
        uploadedAt: '2025-03-01T08:00:00Z',
        deletedAt: '2025-03-02T08:00:00Z',
        deletionReason: 'expired',
        notifyEmail: 'notify@test.com',
    );

    $decoded = json_decode($service->buildPayload($event), true);

    expect($decoded['filename'])->toBe('document.docx');
    expect($decoded['file_size_bytes'])->toBe(98304);
    expect($decoded['uploaded_at'])->toBe('2025-03-01T08:00:00Z');
    expect($decoded['deleted_at'])->toBe('2025-03-02T08:00:00Z');
    expect($decoded['deletion_reason'])->toBe('expired');
    expect($decoded['notify_email'])->toBe('notify@test.com');
});

test('buildPayload preserves file_size_bytes as an integer not a string', function () {
    $service = new NotificationService();

    $event = new DeletionEvent(
        filename: 'test.pdf',
        fileSizeBytes: 512000,
        uploadedAt: '2025-01-01T00:00:00Z',
        deletedAt: '2025-01-02T00:00:00Z',
        deletionReason: 'manual',
        notifyEmail: 'a@b.com',
    );

    $decoded = json_decode($service->buildPayload($event), true);

    expect($decoded['file_size_bytes'])->toBeInt();
    expect($decoded['file_size_bytes'])->toBe(512000);
});

test('buildPayload payload is valid UTF-8', function () {
    $service = new NotificationService();

    $event = new DeletionEvent(
        filename: 'fichier_été.pdf',  // non-ASCII filename
        fileSizeBytes: 100,
        uploadedAt: '2025-01-01T00:00:00Z',
        deletedAt: '2025-01-02T00:00:00Z',
        deletionReason: 'manual',
        notifyEmail: 'a@b.com',
    );

    $payload = $service->buildPayload($event);

    // mb_detect_encoding with strict mode returns false when not valid UTF-8
    expect(mb_detect_encoding($payload, 'UTF-8', strict: true))->toBe('UTF-8');
});

test('buildPayload contains no extra fields beyond the six required', function () {
    $service = new NotificationService();

    $event = new DeletionEvent(
        filename: 'x.pdf',
        fileSizeBytes: 1,
        uploadedAt: '2025-01-01T00:00:00Z',
        deletedAt: '2025-01-02T00:00:00Z',
        deletionReason: 'expired',
        notifyEmail: 'x@x.com',
    );

    $decoded = json_decode($service->buildPayload($event), true);

    expect(array_keys($decoded))->toBe([
        'filename',
        'file_size_bytes',
        'uploaded_at',
        'deleted_at',
        'deletion_reason',
        'notify_email',
    ]);
});

test('publish does not throw when RabbitMQ is unreachable', function () {
    // NotificationService must swallow all connection errors — never propagate to callers
    $service = new NotificationService();

    $event = new DeletionEvent(
        filename: 'test.pdf',
        fileSizeBytes: 100,
        uploadedAt: '2025-01-01T00:00:00Z',
        deletedAt: '2025-01-02T00:00:00Z',
        deletionReason: 'manual',
        notifyEmail: 'a@b.com',
    );

    // This should not throw even if RabbitMQ is not running in the test environment
    expect(fn () => $service->publish($event))->not->toThrow(\Throwable::class);
});
