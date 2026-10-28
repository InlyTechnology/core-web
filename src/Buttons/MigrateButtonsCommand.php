<?php

namespace Inly\CoreWeb\Buttons;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Globals\Variables;

/**
 * Renames the agency base's `style` handle to `variant` in every button row
 * of the site's entries and globals. A row is a button when it has a
 * `link_type`, so another field named `style` is left as it is.
 */
class MigrateButtonsCommand extends Command
{
    protected $signature = 'buttons:migrate {--dry-run : List what would change without saving}';

    protected $description = 'Rename the button fieldset\'s style handle to variant in saved content';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $changed = 0;

        foreach (Entry::all() as $entry) {
            $changed += $this->migrate($entry, "entry {$entry->id()} ({$entry->locale()})", $dry);
        }

        foreach (GlobalSet::all() as $set) {
            foreach ($set->localizations() as $variables) {
                $changed += $this->migrate($variables, "global {$set->handle()} ({$variables->locale()})", $dry);
            }
        }

        $this->info(($dry ? 'Would change ' : 'Changed ').$changed.' '.($changed === 1 ? 'item' : 'items').'.');

        return self::SUCCESS;
    }

    /** @param \Statamic\Entries\Entry|Variables $item */
    private function migrate(object $item, string $name, bool $dry): int
    {
        $rows = 0;
        $data = self::rename($item->data()->all(), $rows);

        if ($rows === 0) {
            return 0;
        }

        $this->line("{$name}: {$rows} ".($rows === 1 ? 'button' : 'buttons'));

        if (! $dry) {
            $item->data($data)->save();
        }

        return 1;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function rename(array $data, int &$rows = 0): array
    {
        if (array_key_exists('link_type', $data) && array_key_exists('style', $data) && ! array_key_exists('variant', $data)) {
            $data['variant'] = $data['style'];
            unset($data['style']);
            $rows++;
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::rename($value, $rows);
            }
        }

        return $data;
    }
}
