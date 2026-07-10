<?php

namespace App\Exceptions;

/**
 * Thrown when a database operation (record creation, deletion) fails.
 * The HTTP layer maps this to a 500 INTERNAL_SERVER_ERROR response.
 */
class DatabaseException extends \RuntimeException
{
}
