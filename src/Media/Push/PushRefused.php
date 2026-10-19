<?php

namespace Inly\CoreWeb\Media\Push;

use RuntimeException;

/** A pushed file this site will not take, with a reason code the pushing side can act on. */
final class PushRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
