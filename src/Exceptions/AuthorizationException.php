<?php

namespace Metricool\Exceptions;

class AuthorizationException extends \RuntimeException
{
    public function __construct(
        string $message = 'Acceso denegado',
        private readonly int $statusCode = 403,
        private readonly string $errorCode = 'FORBIDDEN'
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
