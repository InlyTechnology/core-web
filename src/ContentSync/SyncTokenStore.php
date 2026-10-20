<?php

namespace Inly\CoreWeb\ContentSync;

use Carbon\CarbonImmutable;

/**
 * Where SyncTokens keeps its records, by hash; expiry and pruning stay in SyncTokens so every driver behaves alike.
 *
 * @internal
 */
interface SyncTokenStore
{
    public function get(string $hash): ?SyncToken;

    public function findById(string $id): ?SyncToken;

    /** A new token; an existing record changes only through touch(). */
    public function put(SyncToken $token): void;

    /** Records a use; false when the token is gone, so a use racing a revoke never writes it back. */
    public function touch(string $hash, CarbonImmutable $lastUsedAt, CarbonImmutable $expiresAt): bool;

    public function delete(string $hash): void;

    /** @return list<SyncToken> expired ones included */
    public function forUser(string $userId): array;

    /** @return list<SyncToken> expired ones included */
    public function all(): array;
}
