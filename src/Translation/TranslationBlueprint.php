<?php

namespace Inly\CoreWeb\Translation;

use Statamic\Events\EntryBlueprintFound;
use Statamic\Statamic;

/**
 * In the control panel, a translation's blueprint gets the toggle that keeps
 * it following its original, with where it stands under it, and its synced
 * fields refuse a change to their blocks while it follows. Statamic hands
 * every entry of a collection the same blueprint object in a request, so
 * the rule names no entry and the per-entry defaults are the first
 * translation's; a control panel page resolves one translation.
 */
final class TranslationBlueprint
{
    public const RULE = 'core_web_follows_original';

    public function handle(EntryBlueprintFound $event): void
    {
        $entry = $event->entry;

        if ($entry === null || ! Statamic::isCpRoute() || ! $entry->hasOrigin() || $entry->origin() === null) {
            return;
        }

        SyncedTranslations::remember($entry);

        $fields = FieldShape::synced($event->blueprint);

        if ($fields === []) {
            return;
        }

        $follows = SyncedTranslations::follows($entry);

        $event->blueprint->ensureField(SyncedTranslations::FIELD, [
            'type' => 'toggle',
            'display' => __('Follow the original\'s blocks'),
            'instructions' => $this->instructions($entry, $follows),
            'default' => SyncedTranslations::siteDefault($entry->locale()),
            'localizable' => true,
        ], 'sidebar');

        $event->blueprint->ensureField(SyncedTranslations::SEEN, [
            'type' => 'hidden',
            'default' => $this->seen($entry, $fields),
            'localizable' => true,
        ], 'sidebar');

        foreach (array_keys($fields) as $handle) {
            $rule = self::RULE.':{id},'.$handle;
            $validate = $event->blueprint->field($handle)?->config()['validate'] ?? [];
            $validate = is_string($validate) ? explode('|', $validate) : (array) $validate;

            if (! in_array($rule, $validate, true)) {
                $event->blueprint->ensureFieldHasConfig($handle, ['validate' => [...$validate, $rule]]);
            }
        }
    }

    /** @param  array<string, array<string, mixed>>  $fields */
    private function seen(mixed $entry, array $fields): string
    {
        // The form shows the working copy when there is one, and Statamic may resolve the blueprint on the published entry first.
        $entry = $entry->hasWorkingCopy() ? $entry->fromWorkingCopy() : $entry;
        $seen = [];

        foreach ($fields as $handle => $shape) {
            $seen[$handle] = Alignment::structure($entry->has($handle) ? $entry->get($handle) : $entry->origin()->value($handle), $shape);
        }

        return (string) json_encode($seen);
    }

    private function instructions(mixed $entry, bool $follows): string
    {
        if (! $follows) {
            return __('Blocks are this translation\'s own. Switch on to make them follow the original again, keeping the texts of the blocks it shares.');
        }

        $status = SyncedTranslations::status($entry);
        $parts = [__('Blocks, their order, images and settings follow the original; only the texts are this translation\'s.')];

        $outdated = count($status['outdated']);
        $untranslated = count($status['untranslated']);

        if ($outdated > 0) {
            $parts[] = $outdated === 1
                ? __('1 text changed in the original since it was translated.')
                : __(':count texts changed in the original since they were translated.', ['count' => $outdated]);
        }

        if ($untranslated > 0) {
            $parts[] = $untranslated === 1
                ? __('1 text is not translated yet.')
                : __(':count texts are not translated yet.', ['count' => $untranslated]);
        }

        return implode(' ', $parts);
    }
}
