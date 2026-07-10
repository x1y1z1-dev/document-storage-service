<?php

declare(strict_types=1);

use App\DTO\DeletionEvent;

// ---------------------------------------------------------------------------
// DeletionEvent DTO
// ---------------------------------------------------------------------------

test('DeletionEvent holds all constructor values as readonly properties', function () {
    $event = new DeletionEvent(
        filename: 'report.pdf',
        fileSizeBytes: 204800,
        uploadedAt: '2025-01-10T12:00:00Z',
        deletedAt: '2025-01-11T12:00:01Z',
        deletionReason: 'manual',
        notifyEmail: 'admin@example.com',
    );

    expect($event->filename)->toBe('report.pdf');
    expect($event->fileSizeBytes)->toBe(204800);
    expect($event->uploadedAt)->toBe('2025-01-10T12:00:00Z');
    expect($event->deletedAt)->toBe('2025-01-11T12:00:01Z');
    expect($event->deletionReason)->toBe('manual');
    expect($event->notifyEmail)->toBe('admin@example.com');
});

test('DeletionEvent is a readonly class (properties cannot be mutated)', function () {
    $event = new DeletionEvent(
        filename: 'doc.docx',
        fileSizeBytes: 1024,
        uploadedAt: '2025-01-10T00:00:00Z',
        deletedAt: '2025-01-11T00:00:00Z',
        deletionReason: 'expired',
        notifyEmail: 'ops@example.com',
    );

    $reflection = new ReflectionClass($event);
    expect($reflection->isReadOnly())->toBeTrue();
});

test('DeletionEvent supports both manual and expired deletion reasons', function (string $reason) {
    $event = new DeletionEvent(
        filename: 'file.pdf',
        fileSizeBytes: 512,
        uploadedAt: '2025-01-10T00:00:00Z',
        deletedAt: '2025-01-11T00:00:00Z',
        deletionReason: $reason,
        notifyEmail: 'test@example.com',
    );

    expect($event->deletionReason)->toBe($reason);
})->with(['manual', 'expired']);
