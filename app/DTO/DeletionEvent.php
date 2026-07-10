<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Immutable Data Transfer Object representing a file deletion event.
 *
 * Passed to NotificationService::publish() to serialize and dispatch
 * a JSON message to RabbitMQ for downstream email notification.
 *
 * Field formats:
 *   - $filename        Original (sanitized) filename stored in the File_Record.
 *   - $fileSizeBytes   File size in bytes as stored in the File_Record.
 *   - $uploadedAt      ISO 8601 UTC datetime string (e.g. "2025-01-10T12:00:00Z").
 *   - $deletedAt       ISO 8601 UTC datetime string (e.g. "2025-01-11T12:00:01Z").
 *   - $deletionReason  Either "manual" (user-initiated) or "expired" (scheduler-initiated).
 *   - $notifyEmail     Recipient email address from NOTIFY_EMAIL environment variable.
 */
readonly class DeletionEvent
{
    public function __construct(
        public string $filename,
        public int    $fileSizeBytes,
        public string $uploadedAt,      // ISO 8601 UTC
        public string $deletedAt,       // ISO 8601 UTC
        public string $deletionReason,  // "manual" | "expired"
        public string $notifyEmail,
    ) {}
}
