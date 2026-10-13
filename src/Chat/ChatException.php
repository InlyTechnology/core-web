<?php

namespace Inly\CoreWeb\Chat;

use RuntimeException;
use Throwable;

/** A chat call Core refused or never answered; `status` is Core's HTTP status, 0 when nothing usable came back. */
class ChatException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }

    /** The conversation is unknown to Core: expired, cleared, or another agent's. */
    public function gone(): bool
    {
        return $this->status === 404;
    }

    /** A turn is already running in the conversation. */
    public function busy(): bool
    {
        return $this->status === 409;
    }

    /** A rate limit or budget ran out. */
    public function limited(): bool
    {
        return $this->status === 429;
    }

    public function invalid(): bool
    {
        return $this->status === 422;
    }
}
