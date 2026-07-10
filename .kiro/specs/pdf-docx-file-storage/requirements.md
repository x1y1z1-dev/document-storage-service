# Requirements Document

## Introduction

This document defines requirements for a web application that allows users to upload, manage, and automatically expire PDF and DOCX files. Files are stored with a 24-hour retention window, after which they are automatically deleted. Both automatic and manual deletions trigger an email notification dispatched via RabbitMQ. The application is built with Laravel (PHP 8), MySQL, RabbitMQ, Bootstrap + jQuery, and is deployable via Docker Compose.

## Glossary

- **Application**: The Laravel-based web application described in this document.
- **User**: A person interacting with the Application through a web browser.
- **File**: A document of type PDF or DOCX uploaded by a User.
- **File_Record**: A database row in MySQL that stores metadata about an uploaded File (original name, storage path, MIME type, size, upload timestamp, expiration timestamp).
- **Uploader**: The frontend component (Bootstrap + jQuery) responsible for sending Files to the Application asynchronously.
- **File_Manager**: The backend Laravel service responsible for storing, retrieving, and deleting Files and their File_Records.
- **CRUD_Page**: The web page that lists all uploaded Files and provides controls for manual deletion.
- **Scheduler**: The Laravel scheduled task that periodically checks for expired Files and triggers their deletion.
- **Notification_Service**: The Laravel service responsible for publishing deletion-event messages to RabbitMQ.
- **RabbitMQ**: The message broker used to queue email notification messages.
- **Retention_Period**: The fixed duration of 24 hours from the time of upload after which a File is eligible for automatic deletion.
- **Expiration_Timestamp**: The datetime value stored on a File_Record equal to the upload time plus the Retention_Period.
- **App_Config**: The Laravel configuration file (`config/filestorage.php`) that exposes tunable application parameters sourced from environment variables.
- **Rate_Limiter**: The Laravel middleware that restricts the number of requests a single IP address may make to a given endpoint within a time window.
- **Health_Check**: The endpoint that verifies the operational status of the Application's external dependencies (database and RabbitMQ) and returns a machine-readable JSON status report.

---

## Requirements

### Requirement 1: File Upload

**User Story:** As a User, I want to upload PDF or DOCX files asynchronously from a web page, so that I can store documents without a full page reload.

#### Acceptance Criteria

1. THE Application SHALL provide a web page containing an Uploader that accepts files with MIME types `application/pdf` and `application/vnd.openxmlformats-officedocument.wordprocessingml.document` only.
2. WHEN a User selects or drops a file on the Uploader, THE Uploader SHALL submit the file to the Application via an asynchronous HTTP request without triggering a full page reload.
3. WHEN a valid file is submitted, THE File_Manager SHALL store the file on the server filesystem and create a corresponding File_Record in the database containing: original filename, storage path, MIME type, file size in bytes, upload timestamp, and Expiration_Timestamp set to exactly the Retention_Period after the upload timestamp.
4. IF a submitted file has a MIME type other than `application/pdf` or `application/vnd.openxmlformats-officedocument.wordprocessingml.document`, THEN THE File_Manager SHALL reject the request and return an error response with HTTP status 422 and a descriptive validation message.
5. IF a submitted file exceeds the configured maximum file size, THEN THE File_Manager SHALL reject the request and return an error response with HTTP status 422 and a descriptive validation message.
6. WHEN a file is successfully stored on the server filesystem AND the corresponding File_Record is successfully created in the database, THE Application SHALL return an HTTP 201 response containing the File_Record metadata (id, original filename, MIME type, file size in bytes, upload timestamp, Expiration_Timestamp).
7. WHEN a file is successfully stored, THE Uploader SHALL display the new file entry in the file list on the current page without a full page reload, showing the original filename, file type, file size, upload timestamp, and Expiration_Timestamp.
8. IF the server encounters a storage error while saving a file to the filesystem, THEN THE File_Manager SHALL return an HTTP 500 response with a descriptive error message and SHALL NOT create a File_Record.
9. IF the file is successfully stored on the filesystem but the File_Record creation in the database fails, THEN THE File_Manager SHALL delete the stored file from the filesystem and return an HTTP 500 response with a descriptive error message.

---

### Requirement 2: File Listing

**User Story:** As a User, I want to view a list of all uploaded files on a dedicated management page, so that I can see what documents are stored and their details.

#### Acceptance Criteria

1. THE Application SHALL provide a CRUD_Page accessible via a dedicated URL route.
2. WHEN the CRUD_Page is loaded and the database is available, THE Application SHALL render a table listing all File_Records ordered by upload timestamp descending.
3. THE Application SHALL display the following columns for each File_Record: original filename, file type (PDF or DOCX), file size displayed as bytes for values below 1024, kilobytes (rounded to two decimal places) for values from 1024 to 1,048,575 bytes, and megabytes (rounded to two decimal places) for values of 1,048,576 bytes or above; upload timestamp displayed in UTC as `YYYY-MM-DD HH:MM:SS UTC`; and Expiration_Timestamp displayed in UTC as `YYYY-MM-DD HH:MM:SS UTC`.
4. WHEN the database contains zero File_Records, THE CRUD_Page SHALL display a message indicating that no files have been uploaded.
5. IF the database is unavailable when the CRUD_Page is loaded, THEN THE Application SHALL return an HTTP 500 response and display an error message indicating that the file list could not be retrieved.

---

### Requirement 3: Manual File Deletion

**User Story:** As a User, I want to manually delete a file from the CRUD_Page, so that I can remove documents I no longer need before their Retention_Period expires.

#### Acceptance Criteria

1. THE CRUD_Page SHALL display a delete control for each File_Record in the file list.
2. WHEN a User activates the delete control for a File_Record, THE Application SHALL send an asynchronous HTTP DELETE request to the File_Manager for that File_Record.
3. WHEN a DELETE request is received for a valid File_Record, THE File_Manager SHALL delete the file from the server filesystem, remove the File_Record from the database, and return an HTTP 200 response.
4. WHEN a File_Record is successfully deleted manually, THE Notification_Service SHALL publish a deletion-event message to RabbitMQ containing: the original filename, file size in bytes, upload timestamp, deletion timestamp, deletion reason of `"manual"`, and `notify_email` as specified in the application environment configuration.
5. WHEN a File_Record is successfully deleted manually, THE CRUD_Page SHALL remove the corresponding table row without a full page reload.
6. IF a DELETE request is received for a File_Record identifier that does not exist in the database, THEN THE File_Manager SHALL return an HTTP 404 response with a descriptive error message without performing any filesystem operation.
7. IF the File_Manager fails to delete the file from the filesystem, THEN THE File_Manager SHALL return an HTTP 500 response, SHALL NOT remove the File_Record from the database, and SHALL NOT publish a deletion-event message.
8. IF the DELETE request returns an HTTP 4xx or 5xx response, THEN THE CRUD_Page SHALL display an error message to the User and SHALL NOT remove the corresponding table row.

---

### Requirement 4: Automatic File Expiration

**User Story:** As an administrator, I want files to be automatically deleted 24 hours after upload, so that storage is not consumed indefinitely.

#### Acceptance Criteria

1. THE Scheduler SHALL run at a minimum frequency of once per minute to check for expired File_Records.
2. WHEN the Scheduler runs, THE File_Manager SHALL identify all File_Records whose Expiration_Timestamp is less than or equal to the current UTC datetime.
3. WHEN an expired File_Record is identified, THE File_Manager SHALL first delete the file from the server filesystem, and then, only if the filesystem deletion succeeds, remove the File_Record from the database.
4. WHEN an expired File_Record is successfully deleted (both filesystem and database), THE Notification_Service SHALL publish a deletion-event message to RabbitMQ containing: the original filename, file size, upload timestamp, deletion timestamp, and deletion reason of `"expired"`.
5. IF the File_Manager encounters a filesystem deletion error for an individual expired file, THEN THE Scheduler SHALL log the error and continue processing the remaining expired File_Records without removing the File_Record from the database for the failed file.
6. IF the filesystem deletion of an expired file succeeds but the database removal of the File_Record fails, THEN THE Scheduler SHALL log the error and retain the File_Record in the database so that it can be reattempted on the next Scheduler run, and SHALL NOT publish a deletion-event message for that file.
7. THE File_Manager SHALL set the Expiration_Timestamp of each new File_Record to exactly the Retention_Period after the upload timestamp at the time of file storage.
8. WHEN the Scheduler runs and a file exists on the filesystem but has no corresponding File_Record in the database, THE Scheduler SHALL skip that file and leave it on the filesystem.
9. IF the Notification_Service fails to publish the deletion-event message to RabbitMQ after a successful file and File_Record deletion, THEN THE Scheduler SHALL log the error and SHALL NOT roll back or undo the deletion.

---

### Requirement 5: RabbitMQ Deletion Notification

**User Story:** As an administrator, I want deletion events to be published to RabbitMQ, so that downstream consumers can send email notifications when files are removed.

#### Acceptance Criteria

1. THE Notification_Service SHALL connect to RabbitMQ using credentials and connection parameters read from the application environment configuration (`.env` file); IF the connection to RabbitMQ cannot be established, THEN THE Notification_Service SHALL log an error identifying the configured host and the failure reason, and SHALL skip the message publish for that deletion event.
2. THE Notification_Service SHALL publish deletion-event messages to a dedicated RabbitMQ queue whose name is configurable via the application environment configuration; THE Notification_Service SHALL declare the queue as durable if it does not already exist before publishing.
3. WHEN a deletion-event message is published, THE Notification_Service SHALL serialize the payload as UTF-8 encoded JSON containing the following fields: `filename` (string), `file_size_bytes` (integer), `uploaded_at` (ISO 8601 UTC datetime string), `deleted_at` (ISO 8601 UTC datetime string), `deletion_reason` (string, value is either `"manual"` or `"expired"`), and `notify_email` (string).
4. THE Notification_Service SHALL set the `notify_email` field in each message to the email address specified in the application environment configuration.
5. IF the Notification_Service fails to publish a message to RabbitMQ after a successful connection, THEN THE Notification_Service SHALL log the error including the filename and deletion reason, and SHALL NOT retry the publish attempt automatically.
6. THE Application SHALL NOT perform SMTP email delivery; delivery is the responsibility of a downstream consumer of the RabbitMQ queue.

---

### Requirement 6: Environment Configuration

**User Story:** As a developer, I want all external service credentials and application settings to be managed via a `.env` file, so that the application can be configured without modifying source code.

#### Acceptance Criteria

1. THE Application SHALL read the following configuration values from the environment: database host, database port, database name, database username, database password, RabbitMQ host, RabbitMQ port, RabbitMQ username, RabbitMQ password, RabbitMQ queue name, and notification email address.
2. THE Application SHALL provide a `.env.example` file listing all required environment variables, each with a non-empty placeholder value that indicates the expected type or format (e.g., `DB_HOST=127.0.0.1`, `RABBITMQ_PORT=5672`, `NOTIFY_EMAIL=admin@example.com`) and an inline comment describing the variable's purpose.
3. IF a required environment variable is absent, empty, or contains only whitespace at application startup, THEN THE Application SHALL log a descriptive error message that identifies the missing variable name; the application process SHALL remain running, but the integration that depends on the missing variable SHALL be non-functional until the application is restarted with the variable correctly set.

---

### Requirement 7: Docker Deployment

**User Story:** As a developer, I want to run the entire application stack using Docker Compose, so that the environment is reproducible and easy to set up.

#### Acceptance Criteria

1. THE Application SHALL include a `docker-compose.yml` file that defines services for: the Laravel application, MySQL, and RabbitMQ; the Laravel application service SHALL declare health-check dependencies on both the MySQL and RabbitMQ services so that the application container does not start until both dependencies are healthy.
2. WHEN `docker-compose up` is executed in the project root directory, THE Application SHALL start all services and the web interface SHALL return HTTP 200 from the root path on a host port that defaults to 8080 and is configurable via an environment variable.
3. THE `docker-compose.yml` SHALL mount a persistent named volume for MySQL data so that File_Records survive container restarts.
4. THE `docker-compose.yml` SHALL mount a persistent named volume for uploaded file storage so that Files survive container restarts.
5. THE Application SHALL include a `README.md` file with step-by-step instructions that cover: prerequisites (Docker and Docker Compose versions), how to copy `.env.example` to `.env` and fill in required values, the exact command to build and start all services, how to verify the application is running, and how to stop and remove all containers and volumes.

---

### Requirement 12: File Download

**User Story:** As a User, I want to download a previously uploaded file from the CRUD_Page, so that I can retrieve documents I have stored in the application.

#### Acceptance Criteria

1. THE Application SHALL provide a download endpoint accessible via `GET /files/{id}/download` that streams the stored file to the User's browser using `Storage::download()`.
2. WHEN a download request is received for a valid File_Record, THE Application SHALL return the file contents with HTTP 200, setting the `Content-Disposition` header to `attachment; filename="{original_filename}"` and the `Content-Type` header to the stored MIME type.
3. IF a download request is received for a File_Record identifier that does not exist in the database, THEN THE Application SHALL return HTTP 404 with error code `NOT_FOUND`.
4. IF the file referenced by a File_Record does not exist on the filesystem at the stored `storage_path`, THEN THE Application SHALL return HTTP 404 with a descriptive error message and SHALL log an ERROR-level entry identifying the missing file and its File_Record id.
5. THE CRUD_Page SHALL display a download control (link or button) for each File_Record in the file list.
6. THE Application SHALL enforce rate limiting on the download endpoint: IF a single IP address sends more than the configured download rate limit requests within any 60-second window, THEN THE Application SHALL return HTTP 429 with error code `RATE_LIMIT_EXCEEDED`.

---

### Requirement 13: File Listing Pagination

**User Story:** As a User, I want the file list to be paginated, so that the CRUD_Page remains usable when many files have been uploaded.

#### Acceptance Criteria

1. WHEN the CRUD_Page is loaded, THE Application SHALL render File_Records in pages of a configurable size, defaulting to 15 records per page, sourced from `config/filestorage.php`.
2. THE CRUD_Page SHALL display Bootstrap pagination controls below the file table showing at minimum: a link to the previous page, a link to the next page, and the current page number.
3. WHEN the User navigates to a page, THE Application SHALL display only the File_Records for that page, ordered by upload timestamp descending.
4. WHEN the total number of File_Records is less than or equal to the page size, THE CRUD_Page SHALL NOT render pagination controls.
5. THE Application SHALL add a `per_page` parameter to `config/filestorage.php` sourced from the `PAGINATION_PER_PAGE` environment variable with a default of 15.

---

### Requirement 14: Health Check Endpoint

**User Story:** As a developer or operator, I want a health check endpoint that reports the status of all external dependencies, so that I can monitor the application and use it as a Docker health check target.

#### Acceptance Criteria

1. THE Application SHALL provide a `GET /health` endpoint that returns a JSON response with the following structure: `{"status": "ok"|"degraded", "checks": {"database": "ok"|"error", "rabbitmq": "ok"|"error"}}`.
2. WHEN both the database and RabbitMQ are reachable, THE Application SHALL return HTTP 200 with `"status": "ok"` and both checks set to `"ok"`.
3. WHEN either the database or RabbitMQ is unreachable, THE Application SHALL return HTTP 503 with `"status": "degraded"` and the affected check set to `"error"`.
4. THE Application SHALL verify database connectivity by executing a lightweight query (e.g., `SELECT 1`) and SHALL verify RabbitMQ connectivity by attempting to open an AMQP connection using the configured credentials.
5. THE `/health` endpoint SHALL NOT be subject to CSRF verification or rate limiting.
6. THE `docker-compose.yml` Laravel application service SHALL use `GET /health` as its Docker health check URL, polling every 30 seconds with a timeout of 10 seconds and a start period of 60 seconds.

---

### Requirement 8: Application Configuration

**User Story:** As a developer, I want tunable application parameters to be centralized in a dedicated configuration file, so that I can adjust limits and behavior without searching through application code.

#### Acceptance Criteria

1. THE Application SHALL provide a dedicated configuration file `config/filestorage.php` that exposes the following parameters sourced from environment variables with documented defaults: maximum upload file size in bytes (default: 10,485,760), allowed MIME types as an array (default: `["application/pdf", "application/vnd.openxmlformats-officedocument.wordprocessingml.document"]`), file Retention_Period in hours (default: 24), upload rate limit (maximum requests per minute per IP, default: 20), delete rate limit (maximum requests per minute per IP, default: 30), download rate limit (maximum requests per minute per IP, default: 60), and pagination page size (default: 15).
2. THE File_Manager SHALL read the maximum file size and allowed MIME types exclusively from App_Config, not from hardcoded values.
3. THE Scheduler SHALL read the Retention_Period exclusively from App_Config when calculating Expiration_Timestamps, not from a hardcoded value.
4. THE Rate_Limiter SHALL read the upload, delete, and download rate limit values exclusively from App_Config.
5. WHEN App_Config is loaded and an environment variable for a configurable parameter is absent or non-numeric where a number is expected, THE Application SHALL use the documented default value for that parameter and SHALL log a warning identifying the parameter name and the default value applied.

---

### Requirement 9: Structured Application Logging

**User Story:** As a developer or administrator, I want the application to emit structured log entries for all significant operations and errors, so that I can monitor, debug, and audit the system without instrumenting the code manually.

#### Acceptance Criteria

1. THE Application SHALL write all log entries through the Laravel Log facade using the channel configured in the environment (defaulting to the `stack` channel); each log entry SHALL include at minimum: timestamp (UTC ISO 8601), log level, and a descriptive message.
2. WHEN a file is successfully uploaded and a File_Record is created, THE Application SHALL emit an INFO-level log entry containing the File_Record id, original filename, file size in bytes, and Expiration_Timestamp.
3. WHEN a file is successfully deleted (manually or by the Scheduler), THE Application SHALL emit an INFO-level log entry containing the File_Record id, original filename, deletion reason (`"manual"` or `"expired"`), and deletion timestamp.
4. WHEN a deletion-event message is successfully published to RabbitMQ, THE Application SHALL emit an INFO-level log entry containing the queue name, filename, and deletion reason.
5. WHEN a validation error occurs during file upload (invalid MIME type or file size exceeded), THE Application SHALL emit a WARNING-level log entry containing the rejected filename, the detected MIME type or size, and the violated constraint.
6. WHEN any operation fails with an exception (filesystem error, database error, RabbitMQ connection or publish error), THE Application SHALL emit an ERROR-level log entry containing the exception class, message, and the context of the operation that failed (e.g., file id and operation name).
7. WHEN the Scheduler runs, THE Application SHALL emit a DEBUG-level log entry at the start and end of each run, including the number of expired File_Records found and the counts of successfully deleted and failed records for that run.
8. THE Application SHALL NOT include sensitive values (database passwords, RabbitMQ passwords, file binary content) in any log entry.

---

### Requirement 10: Structured API Error Responses

**User Story:** As a developer integrating with the Application API, I want all error responses to follow a consistent JSON structure, so that I can handle errors programmatically without parsing free-form strings.

#### Acceptance Criteria

1. THE Application SHALL register a global exception handler that intercepts all unhandled exceptions on API routes and returns a JSON response with the following structure: `{"error": {"code": <string>, "message": <string>}}`; the HTTP status code SHALL reflect the error category (400-series for client errors, 500-series for server errors).
2. WHEN a validation error occurs (HTTP 422), THE error response SHALL include a `"details"` key inside the `"error"` object containing an object whose keys are field names and whose values are arrays of descriptive validation messages.
3. THE Application SHALL NOT expose exception stack traces, internal class names, or raw database error messages in error responses returned to the client in any environment.
4. WHEN an unhandled server-side exception occurs on an API route, THE Application SHALL return HTTP 500 with `{"error": {"code": "INTERNAL_SERVER_ERROR", "message": "An unexpected error occurred."}}` regardless of the underlying exception type.
5. THE Application SHALL use the following error codes consistently across all endpoints: `VALIDATION_ERROR` for HTTP 422 responses, `NOT_FOUND` for HTTP 404 responses, `RATE_LIMIT_EXCEEDED` for HTTP 429 responses, and `INTERNAL_SERVER_ERROR` for HTTP 500 responses.

---

### Requirement 11: Security Controls

**User Story:** As a developer, I want the application to apply security controls against common web attack vectors, so that the file storage service is not abused or compromised.

#### Acceptance Criteria

1. THE Application SHALL verify the MIME type of every uploaded file by inspecting the file's binary content using `finfo` (or equivalent server-side content detection), in addition to checking the MIME type declared in the HTTP request; IF the content-detected MIME type does not match an allowed type, THE File_Manager SHALL reject the request with HTTP 422 even if the declared MIME type is valid.
2. THE Application SHALL sanitize the original filename before storing it in the File_Record: strip or replace any path separator characters (`/`, `\`, `..`), control characters, and null bytes; the stored filename SHALL consist only of printable ASCII or Unicode characters, a single dot separator, and the original file extension.
3. THE Application SHALL store uploaded files under an internal storage path generated by the Application (e.g., a UUID-based filename) and SHALL NOT use the original filename as the filesystem path; the original filename SHALL be stored only in the File_Record database column.
4. THE Application SHALL configure the upload storage directory to deny HTTP access and deny execution of any file within it; WHEN running under Docker, the upload volume mount SHALL NOT be served directly by the web server.
5. THE Application SHALL enforce rate limiting on the file upload endpoint: IF a single IP address submits more than the configured upload rate limit requests within any 60-second window, THEN THE Application SHALL return HTTP 429 with error code `RATE_LIMIT_EXCEEDED` for all subsequent requests from that IP within the window.
6. THE Application SHALL enforce rate limiting on the file delete endpoint: IF a single IP address sends more than the configured delete rate limit requests within any 60-second window, THEN THE Application SHALL return HTTP 429 with error code `RATE_LIMIT_EXCEEDED` for all subsequent requests from that IP within the window.
7. THE Application SHALL enforce rate limiting on the file download endpoint: IF a single IP address sends more than the configured download rate limit requests within any 60-second window, THEN THE Application SHALL return HTTP 429 with error code `RATE_LIMIT_EXCEEDED` for all subsequent requests from that IP within the window.
8. THE Application SHALL set the following HTTP security headers on all responses: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, and `Referrer-Policy: strict-origin-when-cross-origin`.
8. THE Application SHALL use parameterized queries or Laravel's Eloquent ORM for all database interactions; raw SQL string interpolation using user-supplied values SHALL NOT be used anywhere in the Application.
9. THE CRUD_Page SHALL include a valid CSRF token in all state-changing requests (delete); the Application SHALL reject any state-changing request that does not carry a valid CSRF token with HTTP 419.
