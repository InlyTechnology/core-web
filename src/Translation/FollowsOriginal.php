<?php

namespace Inly\CoreWeb\Translation;

use Illuminate\Validation\Validator;
use Statamic\Facades\Entry;

/**
 * The validation a synced field of a following translation gets: its blocks
 * stay the original's, or the ones the form opened with, unless the same save
 * switches the translation to its own blocks. The form's own blocks pass even
 * when the original changed after it opened, since the save realigns them.
 */
final class FollowsOriginal
{
    /** @param  list<string>  $parameters  the translation's id and the field's handle */
    public function validate(string $attribute, mixed $value, array $parameters, Validator $validator): bool
    {
        [$id, $handle] = $parameters + [null, null];
        $entry = $id === null ? null : Entry::find($id);
        $origin = $entry?->origin();
        $data = $validator->getData();

        if ($entry === null || $origin === null || ($data[SyncedTranslations::FIELD] ?? true) === false) {
            return true;
        }

        $shape = SyncedTranslations::fields($entry)[$handle] ?? null;

        if ($shape === null) {
            return true;
        }

        $submitted = Alignment::structure($value, $shape);
        $seen = json_decode((string) ($data[SyncedTranslations::SEEN] ?? ''), true)[$handle] ?? null;

        if (is_array($seen)) {
            return Alignment::matches($submitted, $seen)
                || Alignment::matches($submitted, Alignment::structure($origin->value($handle), $shape));
        }

        $current = $entry->hasWorkingCopy() ? $entry->fromWorkingCopy() : $entry;

        return Alignment::matches($submitted, Alignment::structure($origin->value($handle), $shape))
            || Alignment::matches($submitted, Alignment::structure($current->has($handle) ? $current->get($handle) : $origin->value($handle), $shape));
    }

    public static function message(): string
    {
        return __('This page follows the original\'s blocks. Switch off "Follow the original\'s blocks" to add, remove or move blocks here.');
    }
}
