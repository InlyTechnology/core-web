<?php

namespace Inly\CoreWeb\Drafts;

/**
 * A Statamic Dictionary whose stored keys a source cannot guess from its
 * options (a pick of a Core object, a composite key): the content schema
 * lists what it says under the field's `dictionary`.
 */
interface DescribesStoredValue
{
    /**
     * `format`, how a key is written (`{brand slug}|{public_id}`), with an `example` and anything else a source needs, such as
     * the Core object `core_type` a key names.
     *
     * @return array{format: string, example?: string}&array<string, mixed>
     */
    public function storedValue(): array;
}
