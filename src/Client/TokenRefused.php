<?php

namespace Inly\CoreWeb\Client;

use RuntimeException;

/** Core gave no access token: a refusal, an outage or a redirect, never anything a visitor's input caused. */
class TokenRefused extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
