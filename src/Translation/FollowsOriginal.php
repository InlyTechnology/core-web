<?php

namespace Inly\CoreWeb\Translation;

use Illuminate\Validation\Validator;
use Statamic\Facades\Entry;

/**
 * The validation a synced field of a following translation gets: its blocks
 * stay the original's, or the translation's as they stand (a working copy
 * started before the original changed), unless the same save switches the
 * translation to its own blocks.
 */
final class FollowsOriginal
{
    /** @param  list<string>  $parameters  the translation's id and the field's handle */
    public function validate(string $attribute, mixed $value, array $parameters, Validator $validator): bool
    {
        [$id, $handle] = $parameters + [null, null];
        $entry = $id === null ? null : Entry::find($id);
        $origin = $entry?->origin();

        if ($entry === null || $origin === null || ($validator->getData()[SyncedTranslations::FIELD] ?? true) === false) {
            return true;
        }

        $shape = SyncedTranslations::fields($entry)[$handle] ?? null;

        if ($shape === null) {
            return true;
        }

        $current = $entry->hasWorkingCopy() ? $entry->fromWorkingCopy() : $entry;
        $submitted = Alignment::structure($value, $shape);

        return $submitted === Alignment::structure($origin->value($handle), $shape)
            || $submitted === Alignment::structure($current->has($handle) ? $current->get($handle) : $origin->value($handle), $shape);
    }

    public static function message(): string
    {
        return __('This page follows the original\'s blocks. Switch off "Follow the original\'s blocks" to add, remove or move blocks here.');
    }
}
