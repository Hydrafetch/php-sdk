<?php

declare(strict_types=1);

namespace Hydrafetch;

use RuntimeException;

class HydrafetchException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'UNKNOWN',
        public readonly int $status = 500,
        public readonly ?string $requestId = null,
        public readonly mixed $details = null,
    ) {
        parent::__construct($message);
    }

    public function isAuth(): bool
    {
        return in_array($this->status, [401, 403], true);
    }

    public function isOutOfCredits(): bool
    {
        return $this->status === 402;
    }

    public function isInvalidRequest(): bool
    {
        return in_array($this->status, [400, 422], true);
    }

    public function isRetryable(): bool
    {
        return $this->status === 429 || $this->status >= 500;
    }

    public function isTimeout(): bool
    {
        return $this->errorCode === 'TIMEOUT';
    }
}
