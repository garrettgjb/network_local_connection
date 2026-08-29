<?php

namespace Local\Energy;

use RuntimeException;

class DeviceException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message, $status);
    }
}
