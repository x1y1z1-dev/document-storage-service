<?php

declare(strict_types=1);

use App\Exceptions\DatabaseException;
use App\Exceptions\StorageException;

// ---------------------------------------------------------------------------
// Custom Exception Classes
// ---------------------------------------------------------------------------

test('StorageException extends RuntimeException', function () {
    $e = new StorageException('Storage failed');

    expect($e)->toBeInstanceOf(\RuntimeException::class);
    expect($e->getMessage())->toBe('Storage failed');
});

test('DatabaseException extends RuntimeException', function () {
    $e = new DatabaseException('DB failed');

    expect($e)->toBeInstanceOf(\RuntimeException::class);
    expect($e->getMessage())->toBe('DB failed');
});

test('StorageException carries a previous exception when provided', function () {
    $original = new \RuntimeException('Original error');
    $wrapper  = new StorageException('Wrapped', previous: $original);

    expect($wrapper->getPrevious())->toBe($original);
});

test('DatabaseException carries a previous exception when provided', function () {
    $original = new \PDOException('PDO error');
    $wrapper  = new DatabaseException('DB wrapped', previous: $original);

    expect($wrapper->getPrevious())->toBe($original);
});
