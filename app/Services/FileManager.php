<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\DeletionEvent;
use App\Exceptions\DatabaseException;
use App\Exceptions\StorageException;
use App\Models\FileRecord;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FileManager
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Stores the uploaded file and creates a FileRecord.
     *
     * Flow:
     *   1. Content-detect MIME via finfo; reject with 422 if not allowed.
     *   2. Sanitize original filename.
     *   3. Generate UUID-based storage path.
     *   4. Write file to disk; throw StorageException on failure.
     *   5. Create FileRecord; on failure roll back disk write and throw DatabaseException.
     *   6. Log INFO on success.
     *
     * @throws ValidationException  If content-detected MIME is not allowed (422).
     * @throws StorageException     If the filesystem write fails.
     * @throws DatabaseException    If the DB insert fails (filesystem rolled back).
     */
    public function store(UploadedFile $file): FileRecord
    {
        // 1. Content-level MIME detection
        $detectedMime = $this->detectMime($file);

        $allowedMimes = config('filestorage.allowed_mime_types', []);

        if (! in_array($detectedMime, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                'file' => [
                    "The uploaded file's content type '{$detectedMime}' is not allowed. "
                    . 'Only PDF and DOCX files are accepted.',
                ],
            ]);
        }

        // 2. Sanitize filename for storage in DB
        $originalFilename = $this->sanitizeFilename($file->getClientOriginalName());

        // 3. Build UUID-based storage path
        $storagePath = $this->buildStoragePath($file);

        // 4. Write to disk
        $written = Storage::putFileAs(
            dirname($storagePath),
            $file,
            basename($storagePath),
        );

        if ($written === false) {
            throw new StorageException(
                "Failed to write uploaded file to storage path '{$storagePath}'."
            );
        }

        // 5. Create DB record (with rollback on failure)
        $uploadTimestamp     = Carbon::now('UTC');
        $retentionHours      = (int) config('filestorage.retention_hours', 24);
        $expirationTimestamp = $uploadTimestamp->copy()->addHours($retentionHours);

        try {
            $record = FileRecord::create([
                'original_filename'   => $originalFilename,
                'storage_path'        => $storagePath,
                'mime_type'           => $detectedMime,
                'file_size_bytes'     => $file->getSize(),
                'upload_timestamp'    => $uploadTimestamp,
                'expiration_timestamp' => $expirationTimestamp,
            ]);
        } catch (\Throwable $e) {
            // Roll back filesystem write
            try {
                Storage::delete($storagePath);
            } catch (\Throwable $rollbackException) {
                Log::error('FileManager: failed to roll back storage file after DB insert failure.', [
                    'storage_path'      => $storagePath,
                    'rollback_exception' => $rollbackException->getMessage(),
                ]);
            }

            throw new DatabaseException(
                "Failed to create FileRecord for '{$originalFilename}': {$e->getMessage()}",
                previous: $e
            );
        }

        // 6. Log success
        Log::info('FileManager: file uploaded successfully.', [
            'record_id'            => $record->id,
            'original_filename'    => $originalFilename,
            'file_size_bytes'      => $record->file_size_bytes,
            'expiration_timestamp' => $expirationTimestamp->toIso8601String(),
        ]);

        return $record;
    }

    /**
     * Deletes a single file from the filesystem and removes its FileRecord.
     * After successful DB removal, publishes a deletion event ("manual").
     *
     * If Storage::delete() fails, a StorageException is thrown and the DB
     * record is NOT removed.
     *
     * @throws StorageException  If the filesystem delete fails.
     */
    public function delete(FileRecord $fileRecord): void
    {
        // 1. Delete from filesystem first
        $deleted = Storage::delete($fileRecord->storage_path);

        if ($deleted === false) {
            throw new StorageException(
                "Failed to delete file at storage path '{$fileRecord->storage_path}'."
            );
        }

        // 2. Remove DB record
        $fileRecord->delete();

        // 3. Publish deletion notification
        $event = new DeletionEvent(
            filename:       $fileRecord->original_filename,
            fileSizeBytes:  (int) $fileRecord->file_size_bytes,
            uploadedAt:     Carbon::parse($fileRecord->upload_timestamp)->toIso8601String(),
            deletedAt:      Carbon::now('UTC')->toIso8601String(),
            deletionReason: 'manual',
            notifyEmail:    (string) env('NOTIFY_EMAIL', ''),
        );

        $this->notificationService->publish($event);

        // 4. Log success
        Log::info('FileManager: file deleted successfully.', [
            'record_id'        => $fileRecord->id,
            'original_filename' => $fileRecord->original_filename,
            'deletion_reason'  => 'manual',
            'deleted_at'       => Carbon::now('UTC')->toIso8601String(),
        ]);
    }

    /**
     * Finds all expired FileRecords and deletes each one.
     *
     * Per-record errors are logged but do NOT abort the batch.
     *
     * Returns a summary: ['found' => int, 'deleted' => int, 'failed' => int].
     */
    public function deleteExpired(): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, FileRecord> $records */
        $records = FileRecord::expired()->get();

        $found   = count($records);
        $deleted = 0;
        $failed  = 0;

        Log::debug('FileManager: starting expired-file deletion batch.', [
            'expired_count' => $found,
        ]);

        foreach ($records as $record) {
            // Step 1 — delete from filesystem
            try {
                $fsDeleted = Storage::delete($record->storage_path);

                if ($fsDeleted === false) {
                    throw new StorageException(
                        "Storage::delete() returned false for path '{$record->storage_path}'."
                    );
                }
            } catch (\Throwable $e) {
                Log::error('FileManager: failed to delete expired file from filesystem.', [
                    'record_id'     => $record->id,
                    'storage_path'  => $record->storage_path,
                    'exception'     => $e->getMessage(),
                ]);
                $failed++;
                continue; // DB record retained; skip to next
            }

            // Step 2 — remove DB record
            try {
                $record->delete();
            } catch (\Throwable $e) {
                Log::error('FileManager: failed to delete expired FileRecord from database.', [
                    'record_id'  => $record->id,
                    'exception'  => $e->getMessage(),
                ]);
                $failed++;
                continue; // record retained; no notification sent
            }

            // Step 3 — publish deletion notification (failures logged, no rollback)
            try {
                $event = new DeletionEvent(
                    filename:       $record->original_filename,
                    fileSizeBytes:  (int) $record->file_size_bytes,
                    uploadedAt:     Carbon::parse($record->upload_timestamp)->toIso8601String(),
                    deletedAt:      Carbon::now('UTC')->toIso8601String(),
                    deletionReason: 'expired',
                    notifyEmail:    (string) env('NOTIFY_EMAIL', ''),
                );

                $this->notificationService->publish($event);
            } catch (\Throwable $e) {
                // NotificationService already swallows internally, but guard here too.
                Log::error('FileManager: notification publish threw unexpectedly for expired record.', [
                    'record_id' => $record->id,
                    'exception' => $e->getMessage(),
                ]);
                // No rollback — deletion already committed.
            }

            $deleted++;
        }

        Log::debug('FileManager: expired-file deletion batch complete.', [
            'found'   => $found,
            'deleted' => $deleted,
            'failed'  => $failed,
        ]);

        return [
            'found'   => $found,
            'deleted' => $deleted,
            'failed'  => $failed,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Generates the internal UUID-based storage path for a new file.
     * Format: uploads/{uuid}.{ext}
     *
     * The original filename NEVER appears in the returned path.
     */
    private function buildStoragePath(UploadedFile $file): string
    {
        $uuid = Str::uuid()->toString();
        $ext  = strtolower($file->getClientOriginalExtension());

        return "uploads/{$uuid}.{$ext}";
    }

    /**
     * Sanitizes the original filename before persisting to the database.
     *
     * Steps:
     *   1. Strip null bytes and control characters (ASCII 0–31, 127).
     *   2. Strip path separators (/, \) and traversal sequences (..).
     *   3. Trim whitespace.
     *   4. Fallback to "unnamed" if the result is empty.
     */
    private function sanitizeFilename(string $name): string
    {
        // 1. Strip null bytes and control characters (ASCII 0x00–0x1F and 0x7F)
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $name);

        // 2. Strip path separators and traversal sequences
        $name = (string) preg_replace('/[\/\\\\]/', '', $name);
        $name = str_replace('..', '', $name);

        // 3. Trim surrounding whitespace
        $name = trim($name);

        // 4. Fallback for empty result
        if ($name === '') {
            $name = 'unnamed';
        }

        return $name;
    }

    /**
     * Detects the binary content MIME type using PHP's finfo extension.
     *
     * @return string The detected MIME type string.
     */
    private function detectMime(UploadedFile $file): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return (string) $finfo->file($file->getRealPath());
    }
}
