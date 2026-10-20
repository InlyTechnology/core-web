<?php

namespace Inly\CoreWeb\ContentSync;

/**
 * Where SyncTokens keeps its records, by hash; expiry and pruning stay in SyncTokens so every driver behaves alike.
 *
 * @internal
 */
interface SyncTokenStore
{
    public function get(string $hash): ?SyncToken;

    public function put(SyncToken $token): void;

    public function delete(string $hash): void;

    /** @return list<SyncToken> expired ones included */
    public function all(): array;
}
