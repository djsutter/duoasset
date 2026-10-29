<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class MarketDataProviderException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'Market data provider failure.',
        int $code = 0,
        protected array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
