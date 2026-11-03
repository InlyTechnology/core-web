<?php

namespace Inly\CoreWeb\Content\Feed;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Inly\CoreWeb\Content\ContentPings;

/**
 * Tells Core a served page was saved or deleted, so it reads the page back. One waiting ping per page and action
 * absorbs a burst of saves; a ping that never lands is caught by Core's periodic full read.
 */
class PingPageJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $uniqueFor = 300;

    public function __construct(public string $id, public string $action) {}

    public function uniqueId(): string
    {
        return "{$this->id}:{$this->action}";
    }

    public function handle(ContentPings $pings): void
    {
        if ($pings->enabled()) {
            $pings->send($this->id, $this->action);
        }
    }
}
