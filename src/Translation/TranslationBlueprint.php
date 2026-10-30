<?php

namespace Inly\CoreWeb\Translation;

use Statamic\Events\EntryBlueprintFound;
use Statamic\Statamic;

/**
 * In the control panel, a translation's blueprint gets the toggle that keeps
 * it following its original, with where it stands under it, and its synced
 * fields refuse a change to their blocks while it follows.
 */
final class TranslationBlueprint
{
    public const RULE = 'core_web_follows_original';

    public function handle(EntryBlueprintFound $event): void
    {
        $entry = $event->entry;

        if ($entry === null || ! $entry->hasOrigin() || $entry->origin() === null || ! Statamic::isCpRoute()) {
            return;
        }

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

        if (! $follows || $entry->id() === null) {
            return;
        }

        foreach (array_keys($fields) as $handle) {
            $validate = $event->blueprint->field($handle)?->config()['validate'] ?? [];
            $validate = is_string($validate) ? explode('|', $validate) : (array) $validate;
            $validate[] = self::RULE.':'.$entry->id().','.$handle;

            $event->blueprint->ensureFieldHasConfig($handle, ['validate' => $validate]);
        }
    }

    private function instructions(mixed $entry, bool $follows): string
    {
        if (! $follows) {
            return __('Blocks are this translation\'s own. Switch on to make them follow the original again, keeping the texts of the blocks it shares.');
        }

        $status = SyncedTranslations::status($entry);
        $parts = [__('Blocks, their order, images and settings follow the original; only the texts are this translation\'s.')];

        if ($status['outdated'] !== []) {
            $parts[] = __(':count texts changed in the original since they were translated.', ['count' => count($status['outdated'])]);
        }

        if ($status['untranslated'] !== []) {
            $parts[] = __(':count texts are not translated yet.', ['count' => count($status['untranslated'])]);
        }

        return implode(' ', $parts);
    }
}
