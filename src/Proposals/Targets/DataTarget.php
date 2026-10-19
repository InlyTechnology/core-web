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

    /** @return array<string, string> stored keys a proposal may set beyond the blueprint (an asset's focal point), each with the pattern it must match */
    protected function extras(): array
    {
        return [];
    }

    /**
     * The values to save: the proposal's, over what is stored outside the blueprint, which the proposal never sees.
     *
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    protected function merged(array $value): array
    {
        $blueprint = $this->blueprint();
        $known = $blueprint === null ? [] : $blueprint->fields()->all()->keys()->map(fn ($handle) => (string) $handle)->all();
        $owned = [...$known, ...array_keys($this->extras())];

        return [...array_diff_key($this->stored(), array_flip($owned)), ...$value];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function extraValues(array $value): array
    {
        $out = [];

        foreach ($this->extras() as $key => $pattern) {
            if (! array_key_exists($key, $value) || $value[$key] === null) {
                continue;
            }

            if (! is_string($value[$key]) || preg_match($pattern, $value[$key]) !== 1) {
                throw ValidationException::withMessages(["value.{$key}" => "The value of `{$key}` is not one this field takes."]);
            }

            $out[$key] = $value[$key];
        }

        return $out;
    }

    public function current(): array
    {
        $stored = array_diff_key($this->stored(), array_flip($this->internal()));

        return $this->blueprint() === null ? $stored : array_diff_key($stored, $this->merged([]));
    }

    public function validate(array $value): array
    {
        $blueprint = $this->blueprint();

        if ($blueprint === null) {
            return array_diff_key($value, array_flip($this->internal()));
        }

        $extras = $this->extraValues($value);

        $fields = $this->outside() === [] ? $blueprint->fields() : $blueprint->fields()->except(...$this->outside());
        $known = $fields->all()->keys()->map(fn ($handle) => (string) $handle)->all();
        $value = array_intersect_key($value, array_flip($known));
        $fields = $fields->addValues($value)->preProcess();
        $hidden = FieldConditions::omitted($fields, $fields->values()->all());
        $validator = $fields->validator()->validator();

        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())->mapWithKeys(fn (array $messages, string $key) => ["value.{$key}" => $messages])->all());
        }

        return [...array_filter(
            array_diff_key($fields->process()->values()->only(array_keys($value))->all(), array_flip($hidden)),
            fn (mixed $v) => $v !== null,
        ), ...$extras];
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
