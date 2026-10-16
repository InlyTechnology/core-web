<?php

namespace Inly\CoreWeb\Live;

/**
 * One read a site's pages may make of Core while a visitor waits, named in
 * core-web.live.reads and resolved from the container. The visitor sends
 * only the input rules() allows; the Core call, and what of Core's answer
 * reaches the page, are the read's. The answer is the same for every
 * visitor: a live read never carries anything personal.
 */
abstract class LiveRead
{
    /**
     * The call to Core for the validated input, or null when the site
     * already knows there is nothing to read (answered `not_found`).
     *
     * @param  array<string, mixed>  $input
     */
    abstract public function request(array $input): ?LiveRequest;

    /**
     * Validation rules for the visitor's input; a key without a rule never
     * reaches request(), and nested keys need rules of their own (`x.*`).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * What the page gets from Core's answer, or null when the answer is not
     * one the site can show (answered `unavailable`).
     *
     * @param  array<string, mixed>  $json
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    public function present(array $json, array $input): ?array
    {
        $data = $json['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    /** Seconds an answer is kept, by the site per input and by the visitor's browser; 0 keeps none. */
    public function ttl(): int
    {
        return 0;
    }

    /**
     * Whether an answer may be kept for ttl(): false for one that stands in
     * for the real answer (a fallback Core gave while a source was down).
     *
     * @param  array<string, mixed>  $data  what present() returned
     */
    public function keep(array $data): bool
    {
        return true;
    }
}
