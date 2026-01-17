<?php

declare(strict_types=1);

namespace Alik\SystemIntegrity\Exceptions;

use Exception;

/**
 * Exception thrown when system integrity verification fails.
 */
final class SystemIntegrityException extends Exception
{
    public function __construct(string $message = 'System configuration error', int $code = 500, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
