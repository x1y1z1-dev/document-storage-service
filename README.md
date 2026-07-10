# PDF/DOCX File Storage Service

A Laravel 11 + PHP 8.2 web application for uploading, listing, downloading, and auto-expiring PDF and DOCX files. Files are automatically purged 24 hours after upload. All deletions — manual and automatic — publish a JSON event to RabbitMQ for downstream email notification.

---

## Table of Contents

1. [Prerequisites](#1-prerequisites)
2. [Configuration](#2-configuration)
3. [Build and Start](#3-build-and-start)
4. [Verify the Application](#4-verify-the-application)
5. [Access the Web UI](#5-access-the-web-ui)
6. [Stop and Clean Up](#6-stop-and-clean-up)
7. [Environment Variables Reference](#7-environment-variables-reference)
8. [Running Artisan Commands](#8-running-artisan-commands)
9. [Architecture Notes](#9-architecture-notes)

---

## 1. Prerequisites

| Tool | Minimum version | How to check |
|---|---|---|
| Docker | 20.10 | `docker --version` |
| Docker Compose | v2.0 (Compose V2) | `docker compose version` |
| curl | any | used only for the smoke-test below |

> **Docker Desktop users** — Docker Desktop ships with Compose V2 as both `docker compose` (no hyphen) and the `docker-compose` alias. All commands below use `docker-compose`; substitute `docker compose` if your system uses the plugin form.

---

## 2. Configuration

### 2.1 Copy the example environment file

```bash
cp .env.example .env
```

### 2.2 Fill in required values

Open `.env` in your editor. The values most important to change before first run are:

| Variable | Required | Default | Notes |
|---|---|---|---|
| `APP_KEY` | **yes** | _(empty)_ | Leave blank — the container generates it automatically on first start via `php artisan key:generate` |
| `DB_PASSWORD` | **yes** | `secret` | Change before any production or shared deployment |
| `DB_ROOT_PASSWORD` | **yes** | `rootsecret` | MySQL root password used by the container health check. Set this even if you never access MySQL directly |
| `RABBITMQ_PASSWORD` | **yes** | `guest` | Change before any production or shared deployment |
| `NOTIFY_EMAIL` | **yes** | `admin@example.com` | Email address embedded in every deletion-event message sent to RabbitMQ |

See the [full reference table](#7-environment-variables-reference) below for all variables and their defaults.

> **Note** — `DB_HOST` and `RABBITMQ_HOST` are set to the Docker Compose service names (`db` and `rabbitmq`) inside `docker-compose.yml`. Do not change them unless you are connecting to external services running outside Docker Compose.

---

## 3. Build and Start

Run the following command from the **project root** (the directory containing `docker-compose.yml`):

```bash
docker-compose up --build -d
```

This single command:

1. Builds the `app` image from the `Dockerfile` (installs PHP extensions, runs `composer install --no-dev --optimize-autoloader`).
2. Pulls the official `mysql:8.0` and `rabbitmq:3-management` images.
3. Starts all three containers in the background (`-d`).
4. Waits for MySQL and RabbitMQ to pass their health checks before the `app` container starts (allow up to **60 seconds** for the health-check `start_period` to elapse on a cold start).
5. On first boot, the entrypoint script runs `php artisan migrate --force` to create all database tables.

### Watching startup progress

```bash
docker-compose logs -f app
```

You should see output like:

```
[entrypoint] Starting PDF/DOCX File Storage Service...
[entrypoint] Running database migrations...
[entrypoint] Caching config, routes, and views...
[entrypoint] Handing off to supervisord...
```

Once supervisord starts nginx, php-fpm, and the scheduler process the application is ready.

---

## 4. Verify the Application

### Health check endpoint

```bash
curl -s http://localhost:${APP_PORT:-8080}/health
```

Expected response — HTTP 200:

```json
{
    "status": "ok",
    "checks": {
        "database": "ok",
        "rabbitmq": "ok"
    }
}
```

If either dependency is not yet ready you will receive HTTP 503 with `"status": "degraded"`. Wait a few seconds and retry — containers can take up to 60 seconds to complete startup health checks.

### Check all container statuses

```bash
docker-compose ps
```

All three services (`app`, `db`, `rabbitmq`) should show `healthy`.

---

## 5. Access the Web UI

Open your browser at:

```
http://localhost:8080
```

Replace `8080` with your `APP_PORT` value if you changed it. The page shows the full file management UI — upload, list, download, and delete.

### RabbitMQ management UI

The RabbitMQ management plugin is exposed on port 15672:

```
http://localhost:15672
```

Log in with the credentials you set for `RABBITMQ_USER` and `RABBITMQ_PASSWORD` (defaults: `guest` / `guest`).

---

## 6. Stop and Clean Up

### Stop all containers, keep data volumes

```bash
docker-compose down
```

MySQL data and uploaded files are preserved in named volumes and will be available the next time you run `docker-compose up`.

### Stop all containers and remove all volumes

> **Warning** — this permanently deletes all uploaded files, all database records, and all RabbitMQ state. This action cannot be undone.

```bash
docker-compose down -v
```

---

## 7. Environment Variables Reference

All variables are read at container startup. Change them in `.env` and run `docker-compose up -d` to apply.

### Application

| Variable | Default | Description |
|---|---|---|
| `APP_NAME` | `Laravel` | Display name of the application |
| `APP_ENV` | `local` | Laravel environment (`local`, `production`) |
| `APP_KEY` | _(empty)_ | Laravel encryption key — auto-generated on first start if blank |
| `APP_DEBUG` | `true` | Set to `false` in production |
| `APP_URL` | `http://localhost` | Public base URL used in asset and link generation |
| `APP_PORT` | `8080` | Host port mapped to the app container (e.g. `http://localhost:8080`) |
| `LOG_LEVEL` | `debug` | Laravel log verbosity (`debug`, `info`, `warning`, `error`) |

### Database (MySQL 8)

| Variable | Default | Description |
|---|---|---|
| `DB_DATABASE` | `file_storage` | MySQL database schema name |
| `DB_USERNAME` | `laravel` | MySQL application user |
| `DB_PASSWORD` | `secret` | Password for the MySQL application user — **change before production use** |
| `DB_ROOT_PASSWORD` | `rootsecret` | MySQL root password used by the container health check — **change before production use** |

### RabbitMQ

| Variable | Default | Description |
|---|---|---|
| `RABBITMQ_USER` | `guest` | RabbitMQ username |
| `RABBITMQ_PASSWORD` | `guest` | RabbitMQ password — **change before production use** |
| `RABBITMQ_QUEUE` | `file_notifications` | Durable queue name for deletion-event messages |
| `RABBITMQ_MANAGEMENT_PORT` | `15672` | Host port for the RabbitMQ management UI |

### Application Settings

| Variable | Default | Description |
|---|---|---|
| `NOTIFY_EMAIL` | `admin@example.com` | Email address embedded in every RabbitMQ deletion-event message |
| `MAX_UPLOAD_BYTES` | `10485760` | Maximum upload file size in bytes (default: 10 MB) |
| `RETENTION_HOURS` | `24` | Hours after upload before a file is eligible for automatic deletion |
| `RATE_LIMIT_UPLOAD` | `20` | Max upload requests per IP per 60-second window |
| `RATE_LIMIT_DELETE` | `30` | Max delete requests per IP per 60-second window |
| `RATE_LIMIT_DOWNLOAD` | `60` | Max download requests per IP per 60-second window |
| `PAGINATION_PER_PAGE` | `15` | Number of file records shown per page on the listing page |

---

## 8. Running Artisan Commands

To run Laravel Artisan commands inside the running `app` container:

```bash
# General form
docker-compose exec app php artisan <command>

# Examples
docker-compose exec app php artisan migrate:status
docker-compose exec app php artisan files:delete-expired
docker-compose exec app php artisan tinker
```

The `files:delete-expired` command is the same command the built-in scheduler calls every minute. You can run it manually to test the expiration logic or to force a cleanup.

---

## 9. Architecture Notes

| Component | Technology | Notes |
|---|---|---|
| Web server | nginx 1.x | Reverse proxy — forwards PHP requests to php-fpm on `127.0.0.1:9000` |
| PHP runtime | php-fpm 8.2 | Runs the Laravel application |
| Process manager | Supervisor | Manages nginx, php-fpm, and the scheduler loop in a single container |
| Scheduler | `schedule:run` loop | Fires every 60 seconds; deletes files whose `expiration_timestamp ≤ NOW()` |
| Database | MySQL 8.0 | Data persisted in the `db_data` named volume |
| Message broker | RabbitMQ 3 | Management UI at port 15672; data persisted in the `rabbitmq_data` volume |
| File storage | Local volume | Mounted at `/var/www/storage/app/uploads`; not served directly by nginx |

### Security highlights

- Files are stored under UUID-based paths — the original filename is never used on the filesystem.
- The upload directory is blocked by nginx (`return 403`) and is not web-accessible.
- Content MIME type is verified by binary inspection (`finfo`) in addition to the declared MIME type.
- Rate limiting is applied per IP: upload (20 req/min), delete (30 req/min), download (60 req/min).
- All responses carry security headers: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`.
- All database interactions go through Laravel's Eloquent ORM — no raw SQL interpolation.
