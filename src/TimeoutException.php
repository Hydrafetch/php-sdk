<?php

declare(strict_types=1);

namespace Hydrafetch;

class TimeoutException extends HydrafetchException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'TIMEOUT', 408);
    }
}
