<?php

namespace Inly\CoreWeb\Media\Push;

use Statamic\Contracts\Entries\Entry;
use Statamic\Events\EntrySaved;
use Throwable;

/** When a published entry points at held files, they are copied to the folders they were pushed for and the entry points there. */
final class ReleaseHeldAssets
{
    public function __construct(private readonly HeldAssets $assets) {}

    public function handle(EntrySaved $event): void
    {
        $entry = $event->entry;

        if (! $entry instanceof Entry || ! $entry->published() || ! ($blueprint = $entry->blueprint())) {
            return;
        }

        $fields = $blueprint->fields();
        $map = [];

        foreach (AssetReferences::collect($fields, $entry->data()->all()) as $id) {
            [$container, $path] = explode('::', $id, 2);

            if (! HeldAssets::isHeld($path) || ! ($asset = $this->assets->find($container, $path))) {
                continue;
            }

            // A file that cannot be copied must not fail the editor's publish: the entry keeps pointing at the held one, which works.
            try {
                if (($released = $this->assets->release($asset)) !== null) {
                    $map[$id] = $released;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($map === []) {
            return;
        }

        // Saved again with events, so the static cache and pings follow; this listener then finds nothing held.
        $entry->data(AssetReferences::rewrite($fields, $entry->data()->all(), $map))->save();
    }
}
