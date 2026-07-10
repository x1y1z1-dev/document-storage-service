# Implementation Plan: PDF/DOCX File Storage Service

## Overview

Implement a Laravel 11 + PHP 8.2 web application that allows users to upload, list, download, and manually delete PDF and DOCX files. Files are automatically purged after a configurable 24-hour retention window. All deletions publish a JSON event to RabbitMQ for downstream email notification. The stack includes MySQL 8, Bootstrap 5 + jQuery 3, and Docker Compose for deployment.

Implementation follows a dependency-first order: infrastructure and configuration → data models → domain services → HTTP layer → frontend → scheduled tasks → Docker → docs.

---

## Tasks

- [x] 1. Project infrastructure and configuration
  - [x] 1.1 Scaffold `config/filestorage.php` with all tunable parameters
    - Define keys: `max_upload_bytes`, `allowed_mime_types`, `retention_hours`, `rate_limit_upload`, `rate_limit_delete`, `rate_limit_download`, `pagination_per_page`
    - Read each key from its corresponding env variable with the documented default (10 MB, 24 h, 20/30/60 req/min, 15 records/page)
    - Log a WARNING when an env variable is absent or non-numeric and the default is applied
    - _Requirements: 8.1, 8.5, 6.1_

  - [x] 1.2 Create `.env.example` with all required environment variables
    - Include `DB_*`, `RABBITMQ_*`, `NOTIFY_EMAIL`, `MAX_UPLOAD_BYTES`, `RETENTION_HOURS`, `RATE_LIMIT_*`, `PAGINATION_PER_PAGE`, `APP_PORT`
    - Add an inline comment on every variable describing its purpose
    - Include non-empty placeholder values indicating the expected type/format
    - _Requirements: 6.2_

  - [x] 1.3 Define custom exception classes `StorageException` and `DatabaseException`
    - Create `app/Exceptions/StorageException.php` and `app/Exceptions/DatabaseException.php`, both extending `\RuntimeException`
    - _Requirements: 10.1_

  - [x] 1.4 Register the global JSON exception handler in `app/Exceptions/Handler.php`
    - Map `ValidationException` → HTTP 422 / `VALIDATION_ERROR` with `details` object
    - Map `ModelNotFoundException` → HTTP 404 / `NOT_FOUND`
    - Map `ThrottleRequestsException` → HTTP 429 / `RATE_LIMIT_EXCEEDED`
    - Map all other `\Throwable` on API routes → HTTP 500 / `INTERNAL_SERVER_ERROR`
    - Never expose stack traces, class names, or raw DB error messages to the client
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5_

  - [x] 1.5 Implement `SecurityHeadersMiddleware` and register it globally
    - Set `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin` on every response
    - Register in the HTTP kernel's global middleware stack
    - _Requirements: 11.8_

  - [ ]* 1.6 Write property test for security headers on all responses (Property 19)
    - **Property 19: Security headers are present on all HTTP responses**
    - **Validates: Requirement 11.8**
    - Use Eris to generate requests to all registered routes; assert all three headers are present in every response

- [x] 2. Database migration and Eloquent model
  - [x] 2.1 Create the `file_records` database migration
    - Columns: `id` (BIGINT UNSIGNED PK auto-increment), `original_filename` (VARCHAR 255), `storage_path` (VARCHAR 512 UNIQUE), `mime_type` (VARCHAR 128), `file_size_bytes` (BIGINT UNSIGNED), `upload_timestamp` (DATETIME), `expiration_timestamp` (DATETIME), `created_at`/`updated_at` (TIMESTAMP)
    - Add index on `expiration_timestamp`
    - _Requirements: 1.3, 4.2_

  - [x] 2.2 Implement the `FileRecord` Eloquent model
    - Set `$fillable`, `$casts` (cast `file_size_bytes` to integer, cast timestamp columns to `datetime`)
    - Add `scopeExpired()` scope: `WHERE expiration_timestamp <= NOW()`
    - _Requirements: 1.3, 4.2_

  - [ ]* 2.3 Write property test for file listing order (Property 6)
    - **Property 6: File list is always ordered by upload timestamp descending**
    - **Validates: Requirement 2.2**
    - Generate N random `FileRecord` instances with random timestamps; assert ordering returned by the listing query is strictly descending

  - [ ]* 2.4 Write property test for pagination correctness (Property 22)
    - **Property 22: Pagination returns the correct page of records in the correct order**
    - **Validates: Requirements 13.1, 13.3**
    - Generate N records with random timestamps; vary page number P and page size S; assert slice count equals `min(S, N−(P−1)×S)` and order is descending

- [x] 3. DeletionEvent DTO and NotificationService
  - [x] 3.1 Implement the `DeletionEvent` readonly DTO
    - Fields: `filename` (string), `fileSizeBytes` (int), `uploadedAt` (ISO 8601 UTC string), `deletedAt` (ISO 8601 UTC string), `deletionReason` (`"manual"` | `"expired"`), `notifyEmail` (string)
    - Located at `app/DTO/DeletionEvent.php`
    - _Requirements: 3.4, 4.4, 5.3_

  - [x] 3.2 Implement `NotificationService` with lazy AMQP connection
    - Read connection params from env (`RABBITMQ_HOST`, `RABBITMQ_PORT`, `RABBITMQ_USER`, `RABBITMQ_PASSWORD`, `RABBITMQ_QUEUE`)
    - `connect()`: open `AMQPStreamConnection` and channel; idempotent
    - `buildPayload(DeletionEvent)`: serialize to UTF-8 JSON with all required fields
    - `publish(DeletionEvent)`: declare queue as durable, publish message; log and swallow all exceptions (never propagates to caller)
    - Log INFO on successful publish; log ERROR on connection or publish failure including filename and deletion reason
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 9.4, 9.6_

  - [ ]* 3.3 Write property test for notification payload correctness (Property 9)
    - **Property 9: Notification payload is complete and correct for any deletion event**
    - **Validates: Requirements 3.4, 4.4, 5.3, 5.4**
    - Use Eris to generate random `DeletionEvent` instances; call `buildPayload()`; assert JSON is valid UTF-8, contains exactly the required fields with correct types, and `deletion_reason` is `"manual"` or `"expired"`

- [x] 4. FileManager service
  - [x] 4.1 Implement `FileManager::sanitizeFilename()` and `FileManager::buildStoragePath()`
    - `sanitizeFilename()`: strip null bytes, control chars (ASCII 0–31, 127), path separators (`/`, `\`, `..`), trim whitespace; fallback to `"unnamed"` for empty result
    - `buildStoragePath()`: return `"uploads/{Str::uuid()}.{ext}"` where ext is lowercased client extension; original filename never appears in the path
    - _Requirements: 11.2, 11.3_

  - [ ]* 4.2 Write property test for filename sanitization (Property 16)
    - **Property 16: Filename sanitization removes all dangerous characters**
    - **Validates: Requirement 11.2**
    - Use Eris to generate adversarial strings with path separators, null bytes, and control characters; assert output contains none of those characters

  - [ ]* 4.3 Write property test for UUID-based storage paths (Property 17)
    - **Property 17: Uploaded files are always stored under UUID-based paths**
    - **Validates: Requirement 11.3**
    - Generate random filenames; call `buildStoragePath()`; assert output matches `uploads/{UUID}.{ext}` and contains no substring derived from the original filename

  - [x] 4.4 Implement `FileManager::store()`
    - Detect content MIME via `finfo(FILEINFO_MIME_TYPE)`; reject with `ValidationException` (422) if not in allowed list
    - Call `sanitizeFilename()` and `buildStoragePath()`
    - `Storage::put(path, file)` — throw `StorageException` on failure (no DB record created)
    - `FileRecord::create(...)` with all required fields; `expiration_timestamp = upload_timestamp + retention_hours`; throw `DatabaseException` on failure and roll back `Storage::delete(path)`
    - Log INFO on success (record id, filename, size, expiration_timestamp)
    - _Requirements: 1.3, 1.6, 1.8, 1.9, 4.7, 9.2_

  - [ ]* 4.5 Write property test for store() completeness (Property 1)
    - **Property 1: File storage produces a complete and correctly-computed record**
    - **Validates: Requirements 1.3, 1.6, 4.7**
    - Generate random valid filenames, sizes (1–max), and allowed MIME types; call `store()`; assert all `FileRecord` fields are populated and `expiration_timestamp = upload_timestamp + retention_hours`

  - [ ]* 4.6 Write property test for invalid MIME rejection (Property 2)
    - **Property 2: Invalid MIME type or content mismatch is always rejected**
    - **Validates: Requirements 1.4, 11.1**
    - Generate files with non-allowed content MIME types; assert 422 response, no `FileRecord` created, no bytes persisted

  - [ ]* 4.7 Write property test for oversized file rejection (Property 3)
    - **Property 3: Oversized files are always rejected**
    - **Validates: Requirement 1.5**
    - Generate file sizes from `(max_upload_bytes+1)` to `(max_upload_bytes×10)`; assert 422 response, no `FileRecord` created

  - [ ]* 4.8 Write property test for FS failure leaving no DB record (Property 4)
    - **Property 4: Filesystem failure leaves no orphaned database record**
    - **Validates: Requirement 1.8**
    - Mock `Storage::put()` to throw; call `store()` with any valid input; assert no `FileRecord` with that filename or path exists in the database

  - [ ]* 4.9 Write property test for DB failure triggering FS rollback (Property 5)
    - **Property 5: Database failure triggers filesystem rollback**
    - **Validates: Requirement 1.9**
    - Mock `FileRecord::create()` to throw after FS write; call `store()`; assert `Storage::delete()` was called with the same path that was written

  - [x] 4.10 Implement `FileManager::delete()`
    - `Storage::delete(storagePath)` — throw `StorageException` on failure; DB record is NOT removed on failure
    - `fileRecord->delete()` on success
    - Call `NotificationService::publish(DeletionEvent, "manual")` after successful DB removal
    - Log INFO on success (record id, filename, deletion reason, timestamp)
    - _Requirements: 3.3, 3.4, 3.7, 9.3_

  - [ ]* 4.11 Write property test for successful deletion atomicity (Property 8)
    - **Property 8: Successful deletion removes both the filesystem file and the database record**
    - **Validates: Requirements 3.3, 4.3**
    - Generate random `FileRecord` instances; mock Storage and DB; assert `Storage::delete()` is called before `fileRecord->delete()`

  - [ ]* 4.12 Write property test for FS failure preserving DB record (Property 10)
    - **Property 10: Filesystem deletion failure preserves the database record and suppresses notification**
    - **Validates: Requirements 3.7, 4.5 (partial)**
    - Mock `Storage::delete()` to throw; assert `FileRecord` still exists in DB and `NotificationService::publish()` was NOT called

  - [x] 4.13 Implement `FileManager::deleteExpired()`
    - Query `FileRecord::expired()->get()`
    - For each record: delete FS first; on FS failure, log ERROR and skip DB delete (retain record); then delete DB record; on DB failure, log ERROR and retain record; then call `NotificationService::publish(DeletionEvent, "expired")`; on notification failure, log ERROR, no rollback
    - Return summary array `['found' => int, 'deleted' => int, 'failed' => int]`
    - Log DEBUG at start (expired count found) and end (deleted/failed counts)
    - _Requirements: 4.2, 4.3, 4.4, 4.5, 4.6, 4.9, 9.3, 9.7_

  - [ ]* 4.14 Write property test for scheduler identifying correct expired subset (Property 11)
    - **Property 11: Scheduler identifies expired records and only expired records**
    - **Validates: Requirement 4.2**
    - Generate mixed collections of expired and non-expired records; assert `deleteExpired()` attempts deletion for every `expiration_timestamp ≤ NOW()` and none where `expiration_timestamp > NOW()`

  - [ ]* 4.15 Write property test for scheduler batch fault tolerance (Property 12)
    - **Property 12: Scheduler batch fault tolerance — one failure does not block the rest**
    - **Validates: Requirement 4.5**
    - Generate N expired records; mock FS to fail for random subset M; assert all N records are attempted, N−M succeed, M FileRecords are retained

  - [ ]* 4.16 Write property test for DB failure retaining record and suppressing notification (Property 13)
    - **Property 13: Database removal failure after filesystem deletion retains the record and suppresses notification**
    - **Validates: Requirement 4.6**
    - Mock `FileRecord::delete()` to throw after `Storage::delete()` succeeds; assert `FileRecord` remains in DB and `NotificationService::publish()` was NOT called

  - [ ]* 4.17 Write property test for notification failure not rolling back deletion (Property 14)
    - **Property 14: Notification failure after successful deletion does not roll back the deletion**
    - **Validates: Requirement 4.9**
    - Mock `NotificationService::publish()` to throw; assert `FileRecord` is absent from DB and file is absent from filesystem

- [x] 5. HTTP layer — controllers, requests, and routes
  - [x] 5.1 Implement `FileUploadRequest` form request
    - Validation rules driven by `config('filestorage.*')`: `required|file|max:{maxKb}|mimes:pdf,docx`
    - Custom 422 messages for MIME and size violations
    - Log WARNING on validation rejection (filename, detected MIME or size, violated constraint)
    - _Requirements: 1.4, 1.5, 8.2, 9.5_

  - [x] 5.2 Implement `FileController::store()` (POST /files)
    - Accept `FileUploadRequest`, call `FileManager::store()`
    - Return HTTP 201 JSON with `FileRecord` metadata (id, original_filename, mime_type, file_size_bytes, upload_timestamp, expiration_timestamp)
    - On `StorageException` or `DatabaseException`, return HTTP 500 via global handler
    - Apply `throttle:upload` middleware (rate limit from `config('filestorage.rate_limit_upload')`)
    - _Requirements: 1.3, 1.6, 1.8, 1.9, 11.5_

  - [x] 5.3 Implement `FileController::destroy()` (DELETE /files/{id})
    - Find `FileRecord` via route model binding (404 if not found via `ModelNotFoundException`)
    - Call `FileManager::delete()`; return HTTP 200 JSON on success
    - On `StorageException`, return HTTP 500 via global handler (record retained)
    - Apply `throttle:delete` middleware
    - _Requirements: 3.1, 3.2, 3.3, 3.6, 3.7, 11.6_

  - [x] 5.4 Implement `FileController::download()` (GET /files/{id}/download)
    - Find `FileRecord` via route model binding (404 if not found)
    - Check physical file exists at `storage_path`; if missing, log ERROR (record id, path) and return 404 with `NOT_FOUND`
    - Return `Storage::download(storagePath, originalFilename)` as `StreamedResponse` with correct `Content-Disposition` and `Content-Type`
    - Apply `throttle:download` middleware
    - _Requirements: 12.1, 12.2, 12.3, 12.4, 12.6_

  - [ ]* 5.5 Write property test for download returning correct file and headers (Property 20)
    - **Property 20: Download returns the correct file with correct headers for any existing FileRecord**
    - **Validates: Requirements 12.1, 12.2**
    - Generate random `FileRecord` + file content; assert HTTP 200, `Content-Disposition: attachment; filename="{original_filename}"`, `Content-Type` matching stored `mime_type`, correct binary body

  - [ ]* 5.6 Write property test for download 404 scenarios (Property 21)
    - **Property 21: Download returns 404 for any non-existent FileRecord or missing physical file**
    - **Validates: Requirements 12.3, 12.4**
    - Generate non-existent IDs and existing-record-but-missing-file scenarios; assert HTTP 404 with `NOT_FOUND` error code

  - [x] 5.7 Implement `FileController::index()` (GET /)
    - Query `FileRecord::orderBy('upload_timestamp', 'DESC')->paginate(config('filestorage.pagination_per_page'))`
    - Return Blade view passing paginator; return HTTP 500 if DB is unavailable
    - _Requirements: 2.1, 2.2, 2.4, 2.5, 13.1, 13.3_

  - [x] 5.8 Implement `HealthController` (GET /health)
    - Check DB: execute `DB::select('SELECT 1')`
    - Check RabbitMQ: attempt `AMQPStreamConnection` open using configured credentials
    - Return HTTP 200 `{"status":"ok",...}` when both are up; HTTP 503 `{"status":"degraded",...}` when either is down; set individual check to `"ok"` or `"error"` accordingly
    - No CSRF, no rate limiting on this route
    - _Requirements: 14.1, 14.2, 14.3, 14.4, 14.5_

  - [ ]* 5.9 Write property test for health check reflecting true connectivity state (Property 23)
    - **Property 23: Health check reflects the true connectivity state of all dependencies**
    - **Validates: Requirements 14.2, 14.3, 14.4**
    - Mock DB and RabbitMQ in all four reachability combinations (both up, DB down, RMQ down, both down); assert response status, HTTP code, and per-check fields are correct in each case

  - [x] 5.10 Define all routes in `routes/web.php`
    - Wrap all routes in `middleware(['web', 'security.headers'])` group
    - Apply `throttle:upload` to `POST /files`, `throttle:delete` to `DELETE /files/{fileRecord}`, `throttle:download` to `GET /files/{fileRecord}/download`
    - Register `/health` with `Route::withoutMiddleware(VerifyCsrfToken::class)` — no CSRF, no rate limit
    - Register custom throttle limiters in `AppServiceProvider` (or `RouteServiceProvider`) reading values from `config('filestorage.*')`
    - _Requirements: 11.5, 11.6, 12.6, 14.5_

  - [ ]* 5.11 Write property test for rate limiting enforcement (Property 18)
    - **Property 18: Rate limiting is enforced on upload, delete, and download endpoints**
    - **Validates: Requirements 11.5, 11.6, 12.6**
    - Generate request sequences exceeding configured limits for each endpoint; assert HTTP 429 with `RATE_LIMIT_EXCEEDED` for requests over the threshold

  - [ ]* 5.12 Write property test for error response structure (Property 15)
    - **Property 15: Error responses always follow the required JSON structure with no sensitive data**
    - **Validates: Requirements 10.1, 10.2, 10.3, 10.5**
    - Trigger `ValidationException`, `ModelNotFoundException`, `ThrottleRequestsException`, and arbitrary exceptions on API routes; assert each response matches `{"error":{"code":...,"message":...}}`, uses the correct code for the HTTP status, and exposes no stack trace or internal detail

- [x] 6. Checkpoint — core domain and HTTP layer
  - Ensure all tests pass (unit + property tests for tasks 1–5), ask the user if questions arise.

- [x] 7. Frontend Blade view and jQuery
  - [x] 7.1 Implement `resources/views/files/index.blade.php` CRUD page
    - Extend a base layout with Bootstrap 5 CDN; render the file table with columns: original filename, file type (PDF/DOCX), formatted file size, upload timestamp (UTC `YYYY-MM-DD HH:MM:SS UTC`), expiration timestamp (UTC format), and action controls (download link, delete button)
    - Show empty-state message when no records exist
    - Render Bootstrap pagination controls below the table (hide when total ≤ page size)
    - Include CSRF meta tag and include CSRF token in all jQuery AJAX requests
    - _Requirements: 1.7, 2.2, 2.3, 2.4, 3.1, 12.5, 13.2, 13.4_

  - [x] 7.2 Implement the jQuery Uploader (async file upload)
    - Attach `change`/drop handler on the file input; submit via `FormData` / `$.ajax` to `POST /files` without page reload
    - On HTTP 201 response, prepend the new file row to the table (update pagination count if applicable); hide empty-state message
    - On HTTP 422, display validation error message to the user
    - On HTTP 429, display rate-limit message to the user
    - On HTTP 500, display generic error message to the user
    - _Requirements: 1.1, 1.2, 1.7_

  - [x] 7.3 Implement the jQuery delete handler (async delete)
    - Attach click handler to each delete button; send `$.ajax` DELETE to `/files/{id}` with CSRF token
    - On HTTP 200, remove the table row without page reload
    - On HTTP 4xx/5xx, display error message to the user and keep the row
    - _Requirements: 3.2, 3.5, 3.8_

  - [x] 7.4 Implement `formatFileSize()` Blade view helper or service
    - Return `"{n} B"` for `n < 1024`, `"{n} KB"` (2 dp) for `1024 ≤ n < 1_048_576`, `"{n} MB"` (2 dp) for `n ≥ 1_048_576`
    - Register as a global Blade directive or a standalone PHP function
    - _Requirements: 2.3_

  - [ ]* 7.5 Write property test for file size formatter (Property 7)
    - **Property 7: File size formatter produces correct output for all byte values**
    - **Validates: Requirement 2.3**
    - Use Eris to generate integers from 0 to 100 MB; assert correct unit selection, correct rounding to two decimal places, and boundary values (0, 1023, 1024, 1 048 575, 1 048 576)

- [x] 8. Scheduled task — automatic file expiration
  - [x] 8.1 Implement `DeleteExpiredFilesCommand` Artisan command
    - Signature: `files:delete-expired`
    - Call `FileManager::deleteExpired()`; log DEBUG at start (count of expired records found) and end (deleted/failed counts)
    - Return `Command::SUCCESS`
    - _Requirements: 4.1, 9.7_

  - [x] 8.2 Register the command in the Laravel scheduler
    - In `app/Console/Kernel.php` (or `routes/console.php` for Laravel 11): `$schedule->command('files:delete-expired')->everyMinute()`
    - _Requirements: 4.1_

- [ ] 9. Checkpoint — full application integration
  - Ensure all feature tests and property tests pass, ask the user if questions arise.

- [x] 10. Docker and deployment configuration
  - [x] 10.1 Write the `Dockerfile` for the Laravel application
    - Base image: `php:8.2-fpm`; install `pdo_mysql`, `finfo`/`fileinfo`, `amqp` or `php-amqplib` dependencies
    - Copy application files; run `composer install --no-dev --optimize-autoloader`
    - Set working directory to `/var/www`
    - _Requirements: 7.1_

  - [x] 10.2 Write nginx configuration for the Laravel application
    - Serve from port 80 inside the container; root set to `/var/www/public`
    - Deny direct HTTP access to `storage/app/uploads/` (returns 403/404)
    - _Requirements: 11.4_

  - [x] 10.3 Write `docker-compose.yml`
    - Services: `app` (Laravel, port `${APP_PORT:-8080}:80`), `db` (MySQL 8), `rabbitmq` (RabbitMQ 3 with management plugin)
    - `app` service declares `depends_on` with `condition: service_healthy` for both `db` and `rabbitmq`
    - `app` service uses `GET /health` as its Docker health check (poll every 30 s, timeout 10 s, start period 60 s)
    - `db` service mounts a named volume for MySQL data
    - `app` service mounts a named volume for `storage/app/uploads/`
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 14.6_

  - [x] 10.4 Write `README.md` with deployment instructions
    - Prerequisites (Docker and Docker Compose minimum versions)
    - How to copy `.env.example` to `.env` and fill in required values
    - Exact command to build and start all services (`docker-compose up --build -d`)
    - How to verify the application is running (curl `GET /health`)
    - How to stop and remove all containers and volumes (`docker-compose down -v`)
    - _Requirements: 7.5_

- [~] 11. Final checkpoint — end-to-end verification
  - Ensure all tests pass (unit, property-based, integration, smoke), Docker Compose stack starts cleanly, and `GET /health` returns HTTP 200 with `{"status":"ok"}`. Ask the user if questions arise.

---

## Notes

- Tasks marked with `*` are optional and can be skipped for a faster MVP
- Each task references specific requirements for traceability
- All 23 correctness properties from the design document are covered by property-based tests using the [Eris](https://github.com/giorgiosironi/eris) library with a minimum of 100 iterations each
- Each property test is annotated with the format `// Feature: pdf-docx-file-storage, Property {N}: {property_text}`
- The design uses PHP (not pseudocode), so no language selection step was required
- Deletion ordering is always: filesystem first, then database; this is enforced in both `FileManager::delete()` and `FileManager::deleteExpired()`
- The `/health` endpoint is exempt from CSRF and rate limiting; all other state-changing routes enforce CSRF (HTTP 419 on violation)

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "1.3"] },
    { "id": 1, "tasks": ["1.4", "1.5", "2.1", "3.1"] },
    { "id": 2, "tasks": ["1.6", "2.2", "3.2"] },
    { "id": 3, "tasks": ["2.3", "2.4", "3.3", "4.1"] },
    { "id": 4, "tasks": ["4.2", "4.3", "4.4"] },
    { "id": 5, "tasks": ["4.5", "4.6", "4.7", "4.8", "4.9", "4.10"] },
    { "id": 6, "tasks": ["4.11", "4.12", "4.13"] },
    { "id": 7, "tasks": ["4.14", "4.15", "4.16", "4.17", "5.1"] },
    { "id": 8, "tasks": ["5.2", "5.3", "5.4", "5.7", "5.8"] },
    { "id": 9, "tasks": ["5.5", "5.6", "5.9", "5.10"] },
    { "id": 10, "tasks": ["5.11", "5.12", "7.4"] },
    { "id": 11, "tasks": ["7.1", "8.1"] },
    { "id": 12, "tasks": ["7.2", "7.3", "7.5", "8.2"] },
    { "id": 13, "tasks": ["10.1", "10.2"] },
    { "id": 14, "tasks": ["10.3"] },
    { "id": 15, "tasks": ["10.4"] }
  ]
}
```
