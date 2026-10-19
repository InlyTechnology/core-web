<?php

namespace Inly\CoreWeb\Checks;

/**
 * One thing a check found: where it is (an entry and the path in its fields,
 * or a proposal target), what is wrong, and what a fix would make of it. Its
 * id is stable across runs, so a fix can name it and a source can tell a new
 * finding from one it has seen.
 */
final readonly class Finding
{
    /**
     * @param  string  $subject  `entry:{id}`, or a proposal target key (`nav:main:default`)
     * @param  string  $path  where in the subject: a field path, a tree position
     * @param  array<string, mixed>  $details  the check's own (the link found, what it would become)
     */
    public function __construct(
        public string $check,
        public string $subject,
        public string $path,
        public string $message,
        public ?string $site = null,
        public ?string $label = null,
        public ?string $cpUrl = null,
        public array $details = [],
        public bool $fixable = false,
    ) {}

    public function id(): string
    {
        return substr(hash('sha256', implode('|', [$this->check, $this->subject, $this->path, json_encode($this->details['found'] ?? null)])), 0, 16);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'check' => $this->check,
            'subject' => $this->subject,
            'path' => $this->path,
            'message' => $this->message,
            'site' => $this->site,
            'label' => $this->label,
            'cp_url' => $this->cpUrl,
            'details' => $this->details,
            'fixable' => $this->fixable,
        ];
    }

    /** @param  array<string, mixed>  $row */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) $row['check'],
            (string) $row['subject'],
            (string) $row['path'],
            (string) $row['message'],
            $row['site'] ?? null,
            $row['label'] ?? null,
            $row['cp_url'] ?? null,
            (array) ($row['details'] ?? []),
            (bool) ($row['fixable'] ?? false),
        );
    }
}
