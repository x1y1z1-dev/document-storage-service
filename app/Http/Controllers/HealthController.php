<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Handles the GET /health endpoint.
 *
 * Checks database (SELECT 1) and RabbitMQ (AMQP connection attempt)
 * independently and returns a machine-readable JSON status report.
 *
 * Requirements: 14.1, 14.2, 14.3, 14.4, 14.5
 */
class HealthController extends Controller
{
    /**
     * GET /health
     *
     * Returns HTTP 200 with {"status":"ok",...} when both dependencies are up.
     * Returns HTTP 503 with {"status":"degraded",...} when either is down,
     * with each individual check set to "ok" or "error".
     *
     * The endpoint has no CSRF verification or rate limiting (see routes/web.php).
     */
    public function __invoke(): JsonResponse
    {
        $dbStatus       = $this->checkDatabase();
        $rabbitmqStatus = $this->checkRabbitMQ();

        $allOk  = $dbStatus === 'ok' && $rabbitmqStatus === 'ok';
        $status = $allOk ? 'ok' : 'degraded';
        $code   = $allOk ? 200 : 503;

        return response()->json([
            'status' => $status,
            'checks' => [
                'database' => $dbStatus,
                'rabbitmq' => $rabbitmqStatus,
            ],
        ], $code);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Verifies database connectivity by executing a lightweight SELECT 1 query.
     *
     * @return 'ok'|'error'
     */
    private function checkDatabase(): string
    {
        try {
            DB::select('SELECT 1');

            return 'ok';
        } catch (\Throwable) {
            return 'error';
        }
    }

    /**
     * Verifies RabbitMQ connectivity by attempting to open an AMQP connection
     * using the configured credentials.
     *
     * @return 'ok'|'error'
     */
    private function checkRabbitMQ(): string
    {
        try {
            $connection = new AMQPStreamConnection(
                host:     (string) env('RABBITMQ_HOST', 'localhost'),
                port:     (int)    env('RABBITMQ_PORT', 5672),
                user:     (string) env('RABBITMQ_USER', 'guest'),
                password: (string) env('RABBITMQ_PASSWORD', 'guest'),
            );

            $connection->close();

            return 'ok';
        } catch (\Throwable) {
            return 'error';
        }
    }
}
