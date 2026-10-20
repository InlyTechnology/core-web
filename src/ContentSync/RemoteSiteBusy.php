<?php

namespace Inly\CoreWeb\ContentSync;

use Throwable;

/** The remote site asked this machine to wait (a rate limit), for `$retryAfter` seconds when it said. */
class RemoteSiteBusy extends RemoteSiteError
{
    public function __construct(string $message, public readonly ?int $retryAfter = null, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
