<?php

namespace Inly\CoreWeb\ContentSync;

use Carbon\CarbonImmutable;

/** One machine's login to this site as a CMS user: what is stored of a token, never the token itself. */
final class SyncToken
{
    public function __construct(
        public readonly string $id,
        public readonly string $hash,
        public readonly string $userId,
        public readonly string $machine,
        public readonly CarbonImmutable $createdAt,
        public readonly ?CarbonImmutable $lastUsedAt,
        public readonly CarbonImmutable $expiresAt,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['hash'],
            (string) $data['user_id'],
            (string) $data['machine'],
            CarbonImmutable::parse($data['created_at']),
            blank($data['last_used_at'] ?? null) ? null : CarbonImmutable::parse($data['last_used_at']),
            CarbonImmutable::parse($data['expires_at']),
        );
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'hash' => $this->hash,
            'user_id' => $this->userId,
            'machine' => $this->machine,
            'created_at' => $this->createdAt->toIso8601String(),
            'last_used_at' => $this->lastUsedAt?->toIso8601String(),
            'expires_at' => $this->expiresAt->toIso8601String(),
        ];
    }

    public function expired(): bool
    {
        return $this->expiresAt->isPast();
    }

    public function used(CarbonImmutable $at, CarbonImmutable $expiresAt): self
    {
        return new self($this->id, $this->hash, $this->userId, $this->machine, $this->createdAt, $at, $expiresAt);
    }
}
