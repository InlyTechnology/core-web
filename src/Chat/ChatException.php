<?php

namespace Inly\CoreWeb\Chat;

use RuntimeException;
use Throwable;

/** A chat call Core refused or never answered; `status` is Core's HTTP status, 0 when nothing usable came back. */
class ChatException extends RuntimeException
{
    /**
     * @param  ?int  $retryAfter  seconds, when Core's rate limiter said how long to wait
     * @param  bool  $exhausted  a 429 because the conversation spent its budget, which no wait mends
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        ?Throwable $previous = null,
        public readonly ?int $retryAfter = null,
        public readonly bool $exhausted = false,
    ) {
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

    /** A rate limit or a budget that time restores ran out: wait (retryAfter, when Core said). */
    public function limited(): bool
    {
        return $this->status === 429 && ! $this->exhausted;
    }

    public function invalid(): bool
    {
        return $this->status === 422;
    }
}
