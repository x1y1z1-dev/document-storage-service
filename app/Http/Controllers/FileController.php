<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\FileUploadRequest;
use App\Models\FileRecord;
use App\Services\FileManager;
use App\Services\NotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function __construct(
        private FileManager $fileManager,
        private NotificationService $notificationService,
    ) {}

    // -------------------------------------------------------------------------
    // GET /
    // -------------------------------------------------------------------------

    /**
     * Render the CRUD page with all FileRecords ordered by upload_timestamp
     * DESC, paginated.
     *
     * Requirements: 2.1, 2.2, 2.4, 2.5, 13.1, 13.3
     */
    public function index(): View|JsonResponse
    {
        try {
            $files = FileRecord::orderBy('upload_timestamp', 'DESC')
                ->paginate(config('filestorage.pagination_per_page'));
        } catch (\Throwable $e) {
            Log::error('FileController: failed to query FileRecords for index page.', [
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => [
                    'code'    => 'INTERNAL_SERVER_ERROR',
                    'message' => 'Unable to retrieve files. Please try again later.',
                ],
            ], 500);
        }

        return view('files.index', compact('files'));
    }

    // -------------------------------------------------------------------------
    // POST /files
    // -------------------------------------------------------------------------

    /**
     * Accept a validated upload, persist the file via FileManager, and return
     * HTTP 201 with the new FileRecord's metadata.
     *
     * StorageException and DatabaseException bubble to the global handler
     * which converts them to HTTP 500.
     *
     * Requirements: 1.3, 1.6, 1.8, 1.9, 11.5
     */
    public function store(FileUploadRequest $request): JsonResponse
    {
        $record = $this->fileManager->store($request->file('file'));

        return response()->json([
            'id'                   => $record->id,
            'original_filename'    => $record->original_filename,
            'mime_type'            => $record->mime_type,
            'file_size_bytes'      => $record->file_size_bytes,
            'upload_timestamp'     => $record->upload_timestamp->toIso8601String(),
            'expiration_timestamp' => $record->expiration_timestamp->toIso8601String(),
        ], 201);
    }

    // -------------------------------------------------------------------------
    // DELETE /files/{fileRecord}
    // -------------------------------------------------------------------------

    /**
     * Delete the given file and its database record.
     *
     * Route model binding handles 404 via ModelNotFoundException when the
     * FileRecord is not found.
     *
     * StorageException bubbles to the global handler (HTTP 500); the record
     * is retained when storage deletion fails.
     *
     * Requirements: 3.1, 3.2, 3.3, 3.6, 3.7, 11.6
     */
    public function destroy(FileRecord $fileRecord): JsonResponse
    {
        $this->fileManager->delete($fileRecord);

        return response()->json(['message' => 'File deleted successfully.']);
    }

    // -------------------------------------------------------------------------
    // GET /files/{fileRecord}/download
    // -------------------------------------------------------------------------

    /**
     * Stream the stored file to the browser.
     *
     * If the physical file is absent from the filesystem (i.e. the DB record
     * exists but the file was removed externally), log an ERROR and return
     * HTTP 404 so the client gets a meaningful error instead of a broken
     * download.
     *
     * Requirements: 12.1, 12.2, 12.3, 12.4, 12.6
     */
    public function download(FileRecord $fileRecord): StreamedResponse|JsonResponse
    {
        $path       = $fileRecord->storage_path;
        $diskRoot   = Storage::path('');
        $physicalPath = rtrim($diskRoot, '/') . '/' . ltrim($path, '/');

        // Storage::exists() returns true for directories too — explicitly check it's a file
        if (! Storage::exists($path) || ! is_file($physicalPath)) {
            Log::error('FileController: physical file missing or invalid for FileRecord.', [
                'record_id'    => $fileRecord->id,
                'storage_path' => $path,
            ]);

            return response()->json([
                'error' => [
                    'code'    => 'NOT_FOUND',
                    'message' => 'The requested file could not be found on the server.',
                ],
            ], 404);
        }

        $filename = $fileRecord->original_filename;
        $mime     = $fileRecord->mime_type;

        return response()->streamDownload(
            function () use ($path): void {
                $stream = Storage::readStream($path);
                if ($stream !== null) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            $filename,
            [
                'Content-Type'        => $mime,
                'Content-Disposition' => 'attachment; filename="' . addslashes($filename) . '"',
            ],
        );
    }
}
