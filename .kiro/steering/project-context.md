# Project Context: PDF/DOCX File Storage Service

## Overview

This is a Laravel 11 + PHP 8 web application for storing PDF and DOCX files with a configurable 24-hour retention window. Files are managed through a CRUD interface and automatically purged by a scheduled task. All deletions (manual and automatic) publish a notification event to RabbitMQ for downstream email delivery.

The spec is located at: `.kiro/specs/pdf-docx-file-storage/`

---

## Technology Stack

| Layer | Technology |
|---|---|
| Backend framework | Laravel 11 (PHP 8.2+) |
| Database | MySQL 8 |
| Message broker | RabbitMQ 3.x (via `php-amqplib`) |
| Frontend | Bootstrap 5 + jQuery 3 |
| File storage | Laravel Storage facade — local disk (`storage/app/uploads/`) |
| File serving | `Storage::download()` streaming through PHP |
| Containerisation | Docker + Docker Compose |
| Testing | PHPUnit + Eris (property-based) |

---

## Project Structure (Laravel conventions)

```
app/
  Console/Commands/DeleteExpiredFilesCommand.php
  Exceptions/
    StorageException.php
    DatabaseException.php
    Handler.php                  ← global JSON error handler
  Http/
    Controllers/FileController.php
    Middleware/SecurityHeadersMiddleware.php
    Requests/FileUploadRequest.php
  Models/FileRecord.php
  Services/
    FileManager.php
    NotificationService.php
  DTO/DeletionEvent.php
config/
  filestorage.php                ← single source of truth for all tunable params
database/
  migrations/
    xxxx_create_file_records_table.php
resources/views/
  files/index.blade.php          ← CRUD page (Bootstrap + jQuery)
routes/
  web.php
storage/app/uploads/             ← upload target, NOT web-accessible
docker/
  nginx/
  php/
docker-compose.yml
.env.example
```

---

## Key Architecture Decisions

1. **Local disk storage only** — no S3, no cloud. Files go to `storage/app/uploads/{uuid}.{ext}`. Original filenames are stored only in the DB, never used as filesystem paths.

2. **UUID-based file paths** — every stored file gets a `Str::uuid()` name. No original filename component in the storage path (security: prevents path traversal, filename collision).

3. **Filename sanitization** — original filename is stripped of null bytes, control characters (`\x00–\x1F`, `\x7F`), and path separators (`/`, `\`, `..`) before being saved to the DB.

4. **Content-based MIME detection** — MIME is validated twice: once via Laravel's `mimes:` rule (extension + declared MIME) and once via PHP `finfo(FILEINFO_MIME_TYPE)` on the actual file bytes. Both must pass.

5. **Thin controllers** — `FileController` handles HTTP in/out only. All domain logic lives in `FileManager` and `NotificationService`.

6. **Notification decoupling** — the app never sends email. It publishes a JSON event to a durable RabbitMQ queue. Failures are logged and silently swallowed — they never roll back or block a deletion.

7. **Deletion ordering** — filesystem first, then database. If FS delete fails, the DB record is retained and the scheduler retries on the next run.

8. **Config-driven parameters** — `config/filestorage.php` is the single source of truth for: max upload size (10 MB), allowed MIME types, retention period (24 h), upload rate limit (20/min), delete rate limit (30/min).

---

## Database

### Table: `file_records`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | auto-increment |
| `original_filename` | VARCHAR(255) | sanitized, display only |
| `storage_path` | VARCHAR(512) UNIQUE | `uploads/{uuid}.{ext}` |
| `mime_type` | VARCHAR(128) | content-detected |
| `file_size_bytes` | BIGINT UNSIGNED | |
| `upload_timestamp` | DATETIME | UTC |
| `expiration_timestamp` | DATETIME | UTC, indexed |
| `created_at` / `updated_at` | TIMESTAMP | Eloquent timestamps |

---

## API Endpoints

| Method | Route | Description |
|---|---|---|
| `GET` | `/` | CRUD page (Blade view, paginated) |
| `POST` | `/files` | Upload file (async, returns JSON 201) |
| `GET` | `/files/{id}/download` | Download file (streamed via Storage::download()) |
| `DELETE` | `/files/{id}` | Delete file (async, returns JSON 200) |
| `GET` | `/health` | Health check (DB + RabbitMQ status, no CSRF/rate limit) |

Rate limits applied via Laravel throttle middleware: upload 20 req/min, download 60 req/min, delete 30 req/min per IP.

---

## RabbitMQ Message Payload

```json
{
  "filename": "report.pdf",
  "file_size_bytes": 204800,
  "uploaded_at": "2025-01-10T12:00:00Z",
  "deleted_at": "2025-01-11T12:00:01Z",
  "deletion_reason": "expired",
  "notify_email": "admin@example.com"
}
```

Queue is declared as **durable**. Connection parameters come from `.env`. The app does NOT consume messages — publishing only.

---

## Environment Variables

```dotenv
# Database
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=file_storage
DB_USERNAME=laravel
DB_PASSWORD=secret

# RabbitMQ
RABBITMQ_HOST=rabbitmq
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_QUEUE=file_notifications

# Application
NOTIFY_EMAIL=admin@example.com
MAX_UPLOAD_BYTES=10485760
RETENTION_HOURS=24
RATE_LIMIT_UPLOAD=20
RATE_LIMIT_DELETE=30
RATE_LIMIT_DOWNLOAD=60
PAGINATION_PER_PAGE=15
APP_PORT=8080
```

---

## Security Controls

- MIME validated by binary content (`finfo`), not just extension
- Files stored under UUID paths — original name never hits the filesystem
- Upload directory not served by nginx
- CSRF token required on all state-changing requests (HTTP 419 on violation)
- Rate limiting on upload and delete endpoints (HTTP 429 on violation)
- Security headers on all responses: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`
- All DB queries via Eloquent ORM — no raw SQL interpolation

---

## Error Response Format (all API endpoints)

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The file field is required.",
    "details": {
      "file": ["The file field is required."]
    }
  }
}
```

Error codes: `VALIDATION_ERROR` (422), `NOT_FOUND` (404), `RATE_LIMIT_EXCEEDED` (429), `INTERNAL_SERVER_ERROR` (500).

Stack traces are **never** exposed to clients.

---

## Logging

All logging goes through the Laravel `Log` facade using the channel set in `.env` (default: `stack`).

| Event | Level |
|---|---|
| Successful upload | INFO |
| Successful deletion | INFO |
| RabbitMQ publish success | INFO |
| Validation rejection (MIME/size) | WARNING |
| Missing/invalid env variable | WARNING |
| Any exception (FS, DB, RabbitMQ) | ERROR |
| Scheduler run start/end + summary | DEBUG |

Passwords and file binary content are **never** logged.

---

## Correctness Properties Summary

19 formal correctness properties are defined in `design.md`. Each maps to at least one property-based test using the [Eris](https://github.com/giorgiosironi/eris) library (PHPUnit-compatible), minimum 100 iterations per property. Key invariants:

- Upload atomicity: filesystem and DB record are always consistent
- Deletion ordering: filesystem deleted before DB record removed
- Scheduler fault isolation: one record failure never blocks the rest
- Notification failures never roll back deletions
- All error responses follow the standard JSON shape
