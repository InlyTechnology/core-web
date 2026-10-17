<?php

namespace Inly\CoreWeb\Proposals\Targets;

use Illuminate\Validation\ValidationException;
use Inly\CoreWeb\Drafts\FieldConditions;
use Inly\CoreWeb\Proposals\ProposalTarget;
use Statamic\Fields\Blueprint;

/**
 * Values held against a blueprint (a global's variables, a term, an asset's
 * meta): a proposal restates them whole, in the shape Statamic stores them,
 * and is validated as the CP validates a save. Values a field's conditions
 * hide are left out, as the CP leaves them out.
 */
abstract class DataTarget implements ProposalTarget
{
    abstract protected function blueprint(): ?Blueprint;

    /** @return array<string, mixed> */
    abstract protected function stored(): array;

    /** @return list<string> stored keys that are bookkeeping, never a proposal's */
    protected function internal(): array
    {
        return [];
    }

    /** @return list<string> blueprint fields kept outside the stored values (a term's slug) */
    protected function outside(): array
    {
        return [];
    }

    public function current(): array
    {
        return array_diff_key($this->stored(), array_flip($this->internal()));
    }

    public function validate(array $value): array
    {
        $blueprint = $this->blueprint();

        if ($blueprint === null) {
            return array_diff_key($value, array_flip($this->internal()));
        }

        $fields = $this->outside() === [] ? $blueprint->fields() : $blueprint->fields()->except(...$this->outside());
        $known = $fields->all()->keys()->map(fn ($handle) => (string) $handle)->all();
        $value = array_intersect_key($value, array_flip($known));
        $fields = $fields->addValues($value)->preProcess();
        $hidden = FieldConditions::omitted($fields, $fields->values()->all());
        $validator = $fields->validator()->validator();

        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())->mapWithKeys(fn (array $messages, string $key) => ["value.{$key}" => $messages])->all());
        }

        return array_filter(
            array_diff_key($fields->process()->values()->only(array_keys($value))->all(), array_flip($hidden)),
            fn (mixed $v) => $v !== null,
        );
    }

    public function outline(array $value): array
    {
        $lines = [];

        foreach ($value as $handle => $v) {
            $lines[(string) $handle] = is_scalar($v) || $v === null ? (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        ksort($lines);

        return $lines;
    }
}
