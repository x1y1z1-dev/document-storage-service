<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\DeletionEvent;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class NotificationService
{
    public function __construct(
        private ?AMQPStreamConnection $connection = null,
        private ?AMQPChannel $channel = null,
    ) {}

    /**
     * Publishes a deletion event to RabbitMQ.
     *
     * Declares the queue as durable if it does not exist.
     * Logs and swallows all exceptions — never propagates to callers.
     *
     * @param DeletionEvent $event
     */
    public function publish(DeletionEvent $event): void
    {
        try {
            $this->connect();

            $queue = (string) env('RABBITMQ_QUEUE', 'file_notifications');

            // Declare the queue as durable (passive=false, durable=true, exclusive=false, auto_delete=false)
            $this->channel->queue_declare(
                queue:       $queue,
                passive:     false,
                durable:     true,
                exclusive:   false,
                auto_delete: false,
            );

            $payload = $this->buildPayload($event);

            $message = new AMQPMessage($payload, [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT, // delivery_mode = 2
                'content_type'  => 'application/json',
            ]);

            $this->channel->basic_publish($message, '', $queue);

            Log::info('RabbitMQ deletion event published.', [
                'queue'            => $queue,
                'filename'         => $event->filename,
                'deletion_reason'  => $event->deletionReason,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to publish deletion event to RabbitMQ.', [
                'filename'        => $event->filename,
                'deletion_reason' => $event->deletionReason,
                'exception'       => $e->getMessage(),
            ]);
            // Intentionally swallowed — must never propagate to callers.
        }
    }

    /**
     * Opens the AMQP connection and channel; idempotent.
     *
     * Only connects if not already connected. Throws on failure so that
     * publish() can catch and log it as an error.
     *
     * @throws \RuntimeException
     */
    private function connect(): void
    {
        if ($this->connection !== null && $this->connection->isConnected()) {
            return;
        }

        $host     = (string) env('RABBITMQ_HOST', 'localhost');
        $port     = (int)    env('RABBITMQ_PORT', 5672);
        $user     = (string) env('RABBITMQ_USER', 'guest');
        $password = (string) env('RABBITMQ_PASSWORD', 'guest');

        $this->connection = new AMQPStreamConnection(
            host:     $host,
            port:     $port,
            user:     $user,
            password: $password,
        );

        $this->channel = $this->connection->channel();
    }

    /**
     * Builds the JSON payload from a DeletionEvent DTO.
     *
     * Returns a valid UTF-8 JSON string containing exactly the required fields:
     *   filename, file_size_bytes, uploaded_at, deleted_at, deletion_reason, notify_email.
     */
    public function buildPayload(DeletionEvent $event): string
    {
        $payload = [
            'filename'         => $event->filename,
            'file_size_bytes'  => $event->fileSizeBytes,
            'uploaded_at'      => $event->uploadedAt,
            'deleted_at'       => $event->deletedAt,
            'deletion_reason'  => $event->deletionReason,
            'notify_email'     => $event->notifyEmail,
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
