<?php

namespace Inly\CoreWeb\Drafts;

use Illuminate\Validation\ValidationException;

/**
 * Changes to single sets of a page builder (a top-level Replicator), made
 * to the entry's current draft: a source changing one block of a long page
 * names it by its id and sends nothing else. What comes out is the field's
 * whole value, written as if the source had sent it. See docs/drafts.md.
 */
final class BlockChanges
{
    public const OPS = ['update', 'replace', 'insert', 'move', 'remove'];

    /**
     * @param  list<mixed>  $sets  the field's current value
     * @param  list<mixed>  $changes
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException keyed `block_changes.{field}.{index}`
     */
    public static function apply(string $field, array $sets, array $changes): array
    {
        $sets = array_values(array_filter($sets, 'is_array'));

        foreach (array_values($changes) as $index => $change) {
            $result = is_array($change) ? self::one($sets, $change) : __('core-web::drafts.block_change_shape');

            if (is_string($result)) {
                throw ValidationException::withMessages(["block_changes.{$field}.{$index}" => $result]);
            }

            $sets = $result;
        }

        return $sets;
    }

    /**
     * @param  list<array<string, mixed>>  $sets
     * @param  array<string, mixed>  $change
     * @return list<array<string, mixed>>|string the sets, or what is wrong with the change
     */
    private static function one(array $sets, array $change): array|string
    {
        $op = $change['op'] ?? null;

        if (! in_array($op, self::OPS, true)) {
            return __('core-web::drafts.block_change_op', ['ops' => implode(', ', self::OPS)]);
        }

        if ($op === 'insert') {
            $set = $change['block'] ?? null;

            if (! is_array($set) || blank($set['type'] ?? null)) {
                return __('core-web::drafts.block_change_insert');
            }

            unset($set['id']);

            return self::place($sets, $set, $change);
        }

        $at = self::find($sets, $change['id'] ?? null);

        if (is_string($at)) {
            return $at;
        }

        return match ($op) {
            'update' => is_array($change['fields'] ?? null) && $change['fields'] !== []
                ? self::put($sets, $at, [...$sets[$at], ...array_diff_key($change['fields'], ['id' => true, 'type' => true])])
                : __('core-web::drafts.block_change_update'),
            'replace' => is_array($change['block'] ?? null) && filled($change['block']['type'] ?? null)
                ? self::put($sets, $at, [...$change['block'], 'id' => $sets[$at]['id']])
                : __('core-web::drafts.block_change_replace'),
            'move' => self::place(self::without($sets, $at), $sets[$at], $change),
            'remove' => self::without($sets, $at),
        };
    }

    /** @param  list<array<string, mixed>>  $sets */
    private static function find(array $sets, mixed $id): int|string
    {
        if (! is_string($id) || $id === '') {
            return __('core-web::drafts.block_change_id');
        }

        foreach ($sets as $index => $set) {
            if (($set['id'] ?? null) === $id) {
                return $index;
            }
        }

        return __('core-web::drafts.block_change_unknown', ['id' => $id]);
    }

    /**
     * Before the set `before` names, after the one `after` names, or last.
     *
     * @param  list<array<string, mixed>>  $sets
     * @param  array<string, mixed>  $set
     * @param  array<string, mixed>  $change
     * @return list<array<string, mixed>>|string
     */
    private static function place(array $sets, array $set, array $change): array|string
    {
        $before = $change['before'] ?? null;
        $after = $change['after'] ?? null;

        if ($before === null && $after === null) {
            return [...$sets, $set];
        }

        if ($before !== null && $after !== null) {
            return __('core-web::drafts.block_change_two_places');
        }

        if (isset($set['id']) && ($before ?? $after) === $set['id']) {
            return __('core-web::drafts.block_change_itself');
        }

        $at = self::find($sets, $before ?? $after);

        if (is_string($at)) {
            return $at;
        }

        $at += $before !== null ? 0 : 1;

        return [...array_slice($sets, 0, $at), $set, ...array_slice($sets, $at)];
    }

    /**
     * @param  list<array<string, mixed>>  $sets
     * @param  array<string, mixed>  $set
     * @return list<array<string, mixed>>
     */
    private static function put(array $sets, int $at, array $set): array
    {
        $sets[$at] = $set;

        return $sets;
    }

    /**
     * @param  list<array<string, mixed>>  $sets
     * @return list<array<string, mixed>>
     */
    private static function without(array $sets, int $at): array
    {
        unset($sets[$at]);

        return array_values($sets);
    }
}
