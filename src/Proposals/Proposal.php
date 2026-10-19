<?php

namespace Inly\CoreWeb\Proposals;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * A staged change to content without revisions: the target's full new
 * value, the fingerprint of the value it was made from, who proposed it
 * (a CP user, with the source's provenance) and what became of it.
 */
final class Proposal
{
    public const OPEN = 'open';

    public const APPLIED = 'applied';

    public const DISCARDED = 'discarded';

    public const REPLACED = 'replaced';

    /**
     * @param  array<mixed>  $value
     * @param  array<string, string>  $provenance
     */
    public function __construct(
        public readonly string $id,
        public readonly string $target,
        public readonly array $value,
        public readonly string $base,
        public readonly string $userId,
        public readonly array $provenance,
        public readonly string $message,
        public readonly CarbonImmutable $createdAt,
        public string $status = self::OPEN,
        public ?string $decidedBy = null,
        public ?CarbonImmutable $decidedAt = null,
    ) {}

    public function open(): bool
    {
        return $this->status === self::OPEN;
    }

    public function decide(string $status, ?string $userId): self
    {
        $this->status = $status;
        $this->decidedBy = $userId;
        $this->decidedAt = CarbonImmutable::instance(Carbon::now());

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'target' => $this->target,
            'value' => $this->value,
            'base' => $this->base,
            'user_id' => $this->userId,
            'provenance' => $this->provenance,
            'message' => $this->message,
            'created_at' => $this->createdAt->toIso8601String(),
            'status' => $this->status,
            'decided_by' => $this->decidedBy,
            'decided_at' => $this->decidedAt?->toIso8601String(),
        ];
    }

    /** @param  array<string, mixed>  $row */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) $row['id'],
            (string) $row['target'],
            (array) $row['value'],
            (string) $row['base'],
            (string) $row['user_id'],
            (array) ($row['provenance'] ?? []),
            (string) ($row['message'] ?? ''),
            CarbonImmutable::parse((string) $row['created_at']),
            (string) ($row['status'] ?? self::OPEN),
            isset($row['decided_by']) ? (string) $row['decided_by'] : null,
            isset($row['decided_at']) ? CarbonImmutable::parse((string) $row['decided_at']) : null,
        );
    }
}
