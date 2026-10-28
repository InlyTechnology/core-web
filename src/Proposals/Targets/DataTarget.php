<?php

namespace Inly\CoreWeb\Proposals\Targets;

use Illuminate\Validation\ValidationException;
use Inly\CoreWeb\Drafts\FieldConditions;
use Inly\CoreWeb\Proposals\ProposalTarget;
use Statamic\Facades\Entry;
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
        $fields = $this->blueprint()?->fields()->all();
        $lines = [];

        foreach ($value as $handle => $v) {
            $field = $fields?->get((string) $handle);
            $label = $field ? (string) __($field->display()) : (string) $handle;

            // A grid or replicator reads row by row, so changing one redirect shows one row, not the whole list.
            if (is_array($v) && $v !== [] && array_is_list($v) && $field?->type() !== 'bard' && collect($v)->every(fn (mixed $row) => is_array($row) && ! array_is_list($row))) {
                foreach ($v as $index => $row) {
                    $lines[$label.' › '.(filled($row['id'] ?? null) ? $row['id'] : '#'.($index + 1))] = self::row($row);
                }

                continue;
            }

            $lines[$label] = $field?->type() === 'bard' && is_array($v) ? self::text($v) : self::display($v);
        }

        ksort($lines);

        return $lines;
    }

    /** @param  array<string, mixed>  $row */
    private static function row(array $row): string
    {
        return collect($row)
            ->except(['id', '_id'])
            ->map(fn (mixed $v, string $key) => $key.': '.self::display($v))
            ->implode("\n");
    }

    /** A value as people read it: entry references by their page title, lists comma-separated. */
    private static function display(mixed $v): string
    {
        return match (true) {
            $v === null => '',
            is_bool($v) => $v ? __('Yes') : __('No'),
            is_string($v) => self::reference($v),
            is_scalar($v) => (string) $v,
            array_is_list($v) && collect($v)->every(fn (mixed $item) => is_scalar($item)) => implode(', ', array_map(fn (mixed $item) => is_string($item) ? self::reference($item) : (string) $item, $v)),
            default => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    private static function reference(string $v): string
    {
        $id = preg_match('~^(?:statamic://)?entry::(.+)$~', $v, $m) === 1 ? $m[1] : $v;

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) !== 1 || ($entry = Entry::find($id)) === null) {
            return $v;
        }

        $url = $entry->url();

        return (string) $entry->value('title').($url ? " ({$url})" : '');
    }

    /** @param  array<mixed>  $nodes  Bard's stored ProseMirror nodes */
    private static function text(array $nodes): string
    {
        $text = [];

        array_walk_recursive($nodes, function (mixed $v, string|int $key) use (&$text): void {
            if ($key === 'text' && is_string($v)) {
                $text[] = $v;
            }
        });

        return implode(' ', $text);
    }
}
