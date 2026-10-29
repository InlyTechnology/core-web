<?php

namespace Inly\CoreWeb\Proposals\Targets;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inly\CoreWeb\Drafts\FieldConditions;
use Inly\CoreWeb\Proposals\Moves;
use Inly\CoreWeb\Proposals\ProposalTarget;
use Inly\CoreWeb\Support\Fingerprint;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Replicator;

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

            // An empty grid has no rows to list; emptying one shows as its rows removed.
            if (in_array($type, ['grid', 'replicator'], true) && blank($v)) {
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
        return $this->diff($from, $to)[0];
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return array{0: array<string, array{from: ?string, to: ?string}>, 1: list<string>} the changes, and the keys shown raw
     */
    private function diff(array $from, array $to): array
    {
        $raw = [];
        $a = $this->outline($from);
        $b = $this->outline($to);
        $changes = [];

        foreach (array_keys($a + $b) as $key) {
            if (($a[$key] ?? null) !== ($b[$key] ?? null)) {
                $changes[$key] = ['from' => $a[$key] ?? null, 'to' => $b[$key] ?? null];
            }
        }

        $fields = $this->blueprint()?->fields()->all();

        foreach (array_keys($from + $to) as $handle) {
            $handle = (string) $handle;
            $orderKey = $handle.' › '.self::ORDER;

            // Rows added or removed shift the rest anyway: the order line only says something when rows that stay changed places.
            if (isset($changes[$orderKey]) && Moves::moved(array_keys(self::rows($fields?->get($handle), $from[$handle] ?? null)['rows'] ?? []), array_keys(self::rows($fields?->get($handle), $to[$handle] ?? null)['rows'] ?? [])) === []) {
                unset($changes[$orderKey]);
            }

            $visible = collect(array_keys($changes))->contains(fn (string $key) => $key === $handle || str_starts_with($key, $handle.' › '));

            // A change the readable lines cannot show (a Bard link, a set's values) is shown raw, so nothing is applied unseen.
            if (! $visible && Fingerprint::of([$from[$handle] ?? null]) !== Fingerprint::of([$to[$handle] ?? null])) {
                $changes[$handle] = ['from' => self::raw($from[$handle] ?? null), 'to' => self::raw($to[$handle] ?? null)];
                $raw[] = $handle;
            }
        }

        ksort($changes);

        return [$changes, $raw];
    }

    /**
     * The changes as the CP shows them: one group per field, a grid's rows as a table of their fields, each cell before and after.
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return list<array{label: string, kind: 'rows', columns: array<string, string>, rows: list<array{status: 'added'|'changed'|'removed'|'moved'|'origin', moved: bool, cells: array<string, array{from: ?string, to: ?string}>}>, counts: array<string, int>}|array{label: string, kind: 'value', from: ?string, to: ?string, raw: bool}>
     */
    public function review(array $from, array $to): array
    {
        $fields = $this->blueprint()?->fields()->all();
        [$changes, $raw] = $this->diff($from, $to);
        $groups = [];

        foreach ($changes as $key => $change) {
            [$handle, $row] = array_pad(explode(' › ', $key, 2), 2, null);
            $field = $fields?->get($handle);
            $label = $field?->display() ?: Str::headline($handle);

            if ($row === null) {
                $groups[$handle] ??= ['label' => $label, 'kind' => 'value', 'from' => $change['from'], 'to' => $change['to'], 'raw' => in_array($handle, $raw, true)];
            } elseif (($groups[$handle]['kind'] ?? null) !== 'rows') {
                $groups[$handle] = self::rowGroup($field, $label, $from[$handle] ?? null, $to[$handle] ?? null);
            }
        }

        return array_values($groups);
    }

    /**
     * A grid's rows in their proposed order, removed rows where they stood, each marked moved only when rows that stay changed places around it.
     *
     * @return array{label: string, kind: 'rows', columns: array<string, string>, rows: list<array{status: 'added'|'changed'|'removed'|'moved'|'origin', moved: bool, cells: array<string, array{from: ?string, to: ?string}>}>, counts: array<string, int>}
     */
    private static function rowGroup(?Field $field, string $label, mixed $from, mixed $to): array
    {
        $old = self::rows($field, $from) ?? ['rows' => [], 'order' => null];
        $new = self::rows($field, $to) ?? ['rows' => [], 'order' => null];
        $moved = $old['order'] !== null && $new['order'] !== null ? Moves::moved(array_keys($old['rows']), array_keys($new['rows'])) : [];

        // A removed or moved row shows where it stood: after the last row before it that stays in place.
        $removedAfter = [];
        $previous = '';

        foreach (array_keys($old['rows']) as $key) {
            if (array_key_exists($key, $new['rows']) && ! in_array((string) $key, $moved, true)) {
                $previous = (string) $key;
            } else {
                $removedAfter[$previous][] = [(string) $key, array_key_exists($key, $new['rows'])];
            }
        }

        $order = $removedAfter[''] ?? [];

        foreach (array_keys($new['rows']) as $key) {
            $order = [...$order, [(string) $key, false], ...($removedAfter[(string) $key] ?? [])];
        }

        $group = ['label' => $label, 'kind' => 'rows', 'columns' => [], 'rows' => [], 'counts' => ['added' => 0, 'changed' => 0, 'removed' => 0, 'moved' => 0]];

        foreach ($order as [$key, $isOrigin]) {
            if ($isOrigin) {
                $cells = [];

                foreach ($old['rows'][$key] as $column => $value) {
                    $group['columns'][$column] ??= self::subfields($field)[$column]['label'] ?? Str::headline((string) $column);
                    $cells[$column] = ['from' => $value, 'to' => null];
                }

                $group['rows'][] = ['status' => 'origin', 'moved' => false, 'cells' => $cells];

                continue;
            }

            $before = $old['rows'][$key] ?? null;
            $after = $new['rows'][$key] ?? null;
            $isMoved = in_array($key, $moved, true);
            $status = match (true) {
                $before === null => 'added',
                $after === null => 'removed',
                $before !== $after => 'changed',
                $isMoved => 'moved',
                default => null,
            };

            if ($status === null) {
                continue;
            }

            $cells = [];

            foreach (array_keys(($before ?? []) + ($after ?? [])) as $column) {
                $group['columns'][$column] ??= self::subfields($field)[$column]['label'] ?? Str::headline((string) $column);
                $cells[$column] = ['from' => $before[$column] ?? null, 'to' => $after[$column] ?? null];
            }

            $group['counts'][$status]++;

            if ($isMoved && $status !== 'moved') {
                $group['counts']['moved']++;
            }

            $group['rows'][] = ['status' => $status, 'moved' => $isMoved, 'cells' => $cells];
        }

        return $group;
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

        $entryFields = array_keys(array_filter(self::subfields($field), fn (array $sub) => $sub['type'] === 'entries'));
        $ids = array_map(fn (array $row) => is_scalar($row['id'] ?? null) && filled($row['id']) ? (string) $row['id'] : null, $v);
        // Rows key by their id only when every row has one of its own; otherwise by position, so no row hides another.
        $byId = ! in_array(null, $ids, true) && count(array_unique($ids)) === count($ids);
        $rows = [];

        foreach ($v as $index => $row) {
            $rows[$byId ? $ids[$index] : '#'.($index + 1)] = collect($row)
                ->except(['id', '_id', 'enabled'])
                ->mapWithKeys(fn (mixed $value, string|int $column) => [(string) $column => self::display($value, in_array((string) $column, $entryFields, true))])
                ->all();
        }

        return ['rows' => $rows, 'order' => $byId ? implode(', ', $ids) : null];
    }

    /**
     * A grid's fields, or every set's fields of a replicator, imports resolved.
     *
     * @return array<string, array{label: string, type: string}>
     */
    private static function subfields(?Field $field): array
    {
        if ($field === null) {
            return [];
        }

        return Blink::once('core-web-proposal-subfields-'.md5($field->handle().json_encode($field->config())), function () use ($field): array {
            $fieldtype = $field->fieldtype();
            $fields = match (true) {
                $fieldtype instanceof Grid => [$fieldtype->fields()],
                $fieldtype instanceof Replicator => array_map(fn (string $set) => $fieldtype->fields($set), collect($fieldtype->flattenedSetsConfig())->keys()->all()),
                default => [],
            };
            $out = $fieldtype instanceof Replicator ? ['type' => ['label' => __('Set'), 'type' => 'text']] : [];

            foreach ($fields as $set) {
                foreach ($set->all() as $handle => $sub) {
                    $out[(string) $handle] ??= ['label' => (string) ($sub->display() ?: Str::headline((string) $handle)), 'type' => (string) $sub->type()];
                }
            }

            return $out;
        });
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
