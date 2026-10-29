<?php

namespace Inly\CoreWeb\Proposals\Targets;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inly\CoreWeb\Drafts\FieldConditions;
use Inly\CoreWeb\Proposals\ProposalTarget;
use Inly\CoreWeb\Support\Fingerprint;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

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
            if (($rows = self::rows($field, $v)) !== null) {
                foreach ($rows['rows'] as $key => $cells) {
                    $lines[$handle.' › '.$key] = collect($cells)->map(fn (string $cell, string $column) => $column.': '.$cell)->implode("\n");
                }

                if ($rows['order'] !== null) {
                    $lines[$handle.' › '.self::ORDER] = $rows['order'];
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

    /**
     * The changes as the CP shows them: one group per field, a grid's rows as a table of their fields, each cell before and after.
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return list<array{label: string, kind: 'rows', columns: array<string, string>, rows: list<array{status: 'added'|'changed'|'removed', cells: array<string, array{from: ?string, to: ?string}>}>, counts: array<string, int>, reordered: bool}|array{label: string, kind: 'value', from: ?string, to: ?string, raw: bool}>
     */
    public function review(array $from, array $to): array
    {
        $fields = $this->blueprint()?->fields()->all();
        $before = $this->outline($from);
        $groups = [];
        $read = [];

        foreach ($this->changes($from, $to) as $key => $change) {
            [$handle, $row] = array_pad(explode(' › ', $key, 2), 2, null);
            $field = $fields?->get($handle);
            $label = $field?->display() ?: Str::headline($handle);

            if ($row === null) {
                $groups[$handle] = ['label' => $label, 'kind' => 'value', 'from' => $change['from'], 'to' => $change['to'], 'raw' => ($before[$handle] ?? null) !== $change['from']];

                continue;
            }

            $groups[$handle] ??= ['label' => $label, 'kind' => 'rows', 'columns' => [], 'rows' => [], 'counts' => ['added' => 0, 'changed' => 0, 'removed' => 0], 'reordered' => false];

            if ($row === self::ORDER) {
                $groups[$handle]['reordered'] = true;

                continue;
            }

            $read[$handle] ??= [self::rows($field, $from[$handle] ?? null)['rows'] ?? [], self::rows($field, $to[$handle] ?? null)['rows'] ?? []];
            $old = $read[$handle][0][$row] ?? null;
            $new = $read[$handle][1][$row] ?? null;
            $status = match (true) {
                $old === null => 'added',
                $new === null => 'removed',
                default => 'changed',
            };
            $cells = [];

            foreach (array_keys(($old ?? []) + ($new ?? [])) as $column) {
                $groups[$handle]['columns'][$column] ??= self::subfieldLabel($field, $column);
                $cells[$column] = ['from' => $old[$column] ?? null, 'to' => $new[$column] ?? null];
            }

            $groups[$handle]['counts'][$status]++;
            $groups[$handle]['rows'][] = ['status' => $status, 'cells' => $cells];
        }

        return array_values($groups);
    }

    /**
     * A grid's or replicator's rows, each as its fields read for people, keyed by row id when every row has its own, otherwise by position.
     *
     * @return array{rows: array<string, array<string, string>>, order: ?string}|null null when the value is not a list of rows
     */
    private static function rows(?Field $field, mixed $v): ?array
    {
        if (! is_array($v) || $v === [] || ! array_is_list($v) || ! collect($v)->every(fn (mixed $row) => is_array($row) && ! array_is_list($row))) {
            return null;
        }

        $entryFields = collect((array) ($field?->config()['fields'] ?? []))->filter(fn (mixed $sub) => ($sub['field']['type'] ?? null) === 'entries')->pluck('handle')->all();
        $ids = array_map(fn (array $row) => is_scalar($row['id'] ?? null) && filled($row['id']) ? (string) $row['id'] : null, $v);
        // Rows key by their id only when every row has one of its own; otherwise by position, so no row hides another.
        $byId = ! in_array(null, $ids, true) && count(array_unique($ids)) === count($ids);
        $rows = [];

        foreach ($v as $index => $row) {
            $rows[$byId ? $ids[$index] : '#'.($index + 1)] = collect($row)
                ->except(['id', '_id'])
                ->mapWithKeys(fn (mixed $value, string|int $column) => [(string) $column => self::display($value, in_array((string) $column, $entryFields, true))])
                ->all();
        }

        return ['rows' => $rows, 'order' => $byId ? implode(', ', $ids) : null];
    }

    private static function subfieldLabel(?Field $field, string $handle): string
    {
        $sub = collect((array) ($field?->config()['fields'] ?? []))->firstWhere('handle', $handle);

        return (string) ($sub['field']['display'] ?? '') ?: Str::headline($handle);
    }

    private const ORDER = 'order';

    private static function raw(mixed $v): ?string
    {
        return $v === null ? null : (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
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
