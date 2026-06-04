<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientQuantityException extends RuntimeException
{
    public function __construct(
        string $asset,
        string $requested,
        string $available,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $message = sprintf(
            'Insufficient quantity for %s: tried to dispose %s but only %s available.',
            $asset,
            $requested,
            $available
        );

        parent::__construct($message, $code, $previous);
    }
}
