<?php

namespace Inly\CoreWeb\Proposals\Targets;

use Illuminate\Validation\ValidationException;
use Inly\CoreWeb\Drafts\FieldConditions;
use Inly\CoreWeb\Proposals\ProposalTarget;
use Inly\CoreWeb\Support\Fingerprint;
use Statamic\Facades\Blink;
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
            $handle = (string) $handle;
            $field = $fields?->get($handle);
            $type = $field?->type();

            if ($type === 'bard' && is_array($v)) {
                $lines[$handle] = self::text($v);

                continue;
            }

            // A grid or replicator reads row by row, so changing one redirect shows one row, not the whole list.
            if (is_array($v) && $v !== [] && array_is_list($v) && collect($v)->every(fn (mixed $row) => is_array($row) && ! array_is_list($row))) {
                $entryFields = collect((array) ($field?->config()['fields'] ?? []))->filter(fn (mixed $sub) => ($sub['field']['type'] ?? null) === 'entries')->pluck('handle')->all();
                $ids = array_map(fn (array $row) => is_scalar($row['id'] ?? null) && filled($row['id']) ? (string) $row['id'] : null, $v);
                // Rows key by their id only when every row has one of its own; otherwise by position, so no row hides another.
                $byId = ! in_array(null, $ids, true) && count(array_unique($ids)) === count($ids);

                foreach ($v as $index => $row) {
                    $lines[$handle.' › '.($byId ? $ids[$index] : '#'.($index + 1))] = self::row($row, $entryFields);
                }

                if ($byId) {
                    $lines[$handle.' › '.self::ORDER] = implode(', ', $ids);
                }

                continue;
            }

            $lines[$handle] = self::display($v, $type === 'entries');
        }

        ksort($lines);

        return $lines;
    }

    public function changes(array $from, array $to): array
    {
        $a = $this->outline($from);
        $b = $this->outline($to);
        $changes = [];

        foreach (array_keys($a + $b) as $key) {
            if (($a[$key] ?? null) !== ($b[$key] ?? null)) {
                $changes[$key] = ['from' => $a[$key] ?? null, 'to' => $b[$key] ?? null];
            }
        }

        $order = fn (string $key) => str_ends_with($key, ' › '.self::ORDER);
        $shown = array_keys(array_filter($changes, fn (array $change, string $key) => ! $order($key), ARRAY_FILTER_USE_BOTH));

        foreach (array_keys($from + $to) as $handle) {
            $handle = (string) $handle;
            $orderKey = $handle.' › '.self::ORDER;

            // Rows added or removed move the rest anyway: the order line only says something when the same rows changed places.
            if (isset($changes[$orderKey]) && collect($shown)->contains(fn (string $key) => str_starts_with($key, $handle.' › '))) {
                unset($changes[$orderKey]);
            }

            $visible = collect(array_keys($changes))->contains(fn (string $key) => $key === $handle || str_starts_with($key, $handle.' › '));

            // A change the readable lines cannot show (a Bard link, a set's values) is shown raw, so nothing is applied unseen.
            if (! $visible && Fingerprint::of([$from[$handle] ?? null]) !== Fingerprint::of([$to[$handle] ?? null])) {
                $changes[$handle] = ['from' => self::raw($from[$handle] ?? null), 'to' => self::raw($to[$handle] ?? null)];
            }
        }

        ksort($changes);

        return $changes;
    }

    private const ORDER = 'order';

    private static function raw(mixed $v): ?string
    {
        return $v === null ? null : (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $entryFields  the row's fields that hold entries
     */
    private static function row(array $row, array $entryFields): string
    {
        return collect($row)
            ->except(['id', '_id'])
            ->map(fn (mixed $v, string|int $key) => $key.': '.self::display($v, in_array((string) $key, $entryFields, true)))
            ->implode("\n");
    }

    /** A value as people read it: entry references by their page title, lists comma-separated. */
    private static function display(mixed $v, bool $entries = false): string
    {
        return match (true) {
            $v === null => '',
            is_bool($v) => $v ? __('Yes') : __('No'),
            is_string($v) || ($entries && is_int($v)) => self::reference((string) $v, $entries),
            is_scalar($v) => (string) $v,
            array_is_list($v) && collect($v)->every(fn (mixed $item) => is_scalar($item)) => implode(', ', array_map(fn (mixed $item) => is_string($item) || ($entries && is_int($item)) ? self::reference((string) $item, $entries) : (string) $item, $v)),
            default => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    /** @param  bool  $entries  whether the field holds entries, so any id is looked up; elsewhere only an entry reference or a UUID is */
    private static function reference(string $v, bool $entries): string
    {
        $id = preg_match('~^(?:statamic://)?entry::(.+)$~', $v, $m) === 1 ? $m[1] : $v;

        if (! $entries && $id === $v && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) !== 1) {
            return $v;
        }

        // A global holds hundreds of redirects to a few pages; each page is looked up once per request.
        return Blink::once('core-web-proposal-entry-'.$id, function () use ($id, $v): string {
            $entry = Entry::find($id);

            if ($entry === null) {
                return $v;
            }

            $title = (string) ($entry->value('title') ?: $id);
            $url = $entry->url();

            return $title.($url ? " ({$url})" : '');
        });
    }

    /** @param  array<mixed>  $nodes  Bard's stored ProseMirror nodes, as text with one line per block */
    private static function text(array $nodes): string
    {
        $lines = [];

        foreach ($nodes as $node) {
            if (is_array($node)) {
                $lines[] = self::inline($node);
            }
        }

        return implode("\n", array_filter($lines, fn (string $line) => $line !== ''));
    }

    /** @param  array<mixed>  $node */
    private static function inline(array $node): string
    {
        if (($node['type'] ?? null) === 'text') {
            return (string) ($node['text'] ?? '');
        }

        return implode('', array_map(fn (mixed $child) => is_array($child) ? self::inline($child) : '', (array) ($node['content'] ?? [])));
    }
}
