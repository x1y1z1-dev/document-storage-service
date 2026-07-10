<?php

namespace App\Exceptions;

/**
 * Thrown when a filesystem operation (upload, delete) fails.
 * The HTTP layer maps this to a 500 INTERNAL_SERVER_ERROR response.
 */
class StorageException extends \RuntimeException
{
}
