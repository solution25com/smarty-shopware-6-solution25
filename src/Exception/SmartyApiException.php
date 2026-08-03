<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Exception;

use RuntimeException;
use Throwable;

class SmartyApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        private readonly ?string $responseBody = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}
