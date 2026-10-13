<?php

namespace Inly\CoreWeb\Chat;

use Illuminate\Http\Request;

/**
 * What a page tells one of Core's agents, built server side from the
 * `subject` the browser names (the id of the record on the page, say). The
 * browser never supplies context itself: whatever it sent would reach the
 * agent's prompt. Bound in core-web.chat.agents, one class per agent.
 */
interface ChatContext
{
    /**
     * The payload a new conversation opens with; the agent decides which keys it reads.
     *
     * @return array<string, mixed>
     */
    public function context(?string $subject, Request $request): array;

    /** The conversation the visitor is in: a different key starts a fresh one (one per record, say). */
    public function key(?string $subject, Request $request): string;
}
