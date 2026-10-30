<?php

namespace Inly\CoreWeb\Translation;

use Statamic\Contracts\Entries\Entry;

/**
 * A translation that follows its original: its replicators and grids keep
 * the original's blocks, order and shared values, and own only their texts.
 * Each text remembers which version of the original's text it translates,
 * so a change in the original shows as an outdated translation.
 */
final class SyncedTranslations
{
    /** The toggle an editor switches a translation's own structure on with. */
    public const FIELD = 'translation_follows_original';

    /** Where a translation keeps, per text, the hashes of the original's text and its own when it was translated. */
    public const SOURCES = 'core_web_translation';

    public static function siteDefault(string $site): bool
    {
        return (config("core-web.translation.sites.{$site}") ?? 'own') === 'synced';
    }

    public static function follows(Entry $entry): bool
    {
        if (! $entry->hasOrigin() || $entry->origin() === null) {
            return false;
        }

        $own = $entry->get(self::FIELD);

        return is_bool($own) ? $own : self::siteDefault($entry->locale());
    }

    /** @return array<string, array<string, mixed>> */
    public static function fields(Entry $entry): array
    {
        return FieldShape::synced($entry->blueprint());
    }

    /**
     * Aligns the fields the translation holds of its own with the original and
     * brings the text sources up to date. Fields it inherits are left alone.
     *
     * @return bool whether anything changed
     */
    public static function align(Entry $translation): bool
    {
        $origin = $translation->origin();
        $before = $translation->data()->all();
        $sources = (array) $translation->get(self::SOURCES, []);
        $next = [];

        foreach (self::fields($translation) as $handle => $shape) {
            if (! $translation->has($handle)) {
                continue;
            }

            $aligned = Alignment::align($origin->value($handle), $translation->get($handle), $shape);
            $translation->set($handle, $aligned);

            $originTexts = Alignment::texts($origin->value($handle), $shape, $handle);

            foreach (Alignment::texts($aligned, $shape, $handle) as $path => $text) {
                if (! array_key_exists($path, $originTexts) || $text === $originTexts[$path]) {
                    continue;
                }

                $known = $sources[$path] ?? null;
                $next[$path] = is_array($known) && ($known['t'] ?? null) === self::hash($text)
                    ? $known
                    : ['o' => self::hash($originTexts[$path]), 't' => self::hash($text)];
            }
        }

        $next === [] ? $translation->remove(self::SOURCES) : $translation->set(self::SOURCES, $next);

        return $translation->data()->all() !== $before;
    }

    /**
     * Where the translation stands against its original, per synced field.
     *
     * @return array{drift: list<string>, outdated: list<string>, untranslated: list<string>}
     */
    public static function status(Entry $translation): array
    {
        $origin = $translation->origin();
        $sources = (array) $translation->get(self::SOURCES, []);
        $status = ['drift' => [], 'outdated' => [], 'untranslated' => []];

        foreach (self::fields($translation) as $handle => $shape) {
            $original = $origin->value($handle);
            $own = $translation->has($handle) ? $translation->get($handle) : $original;

            if (Alignment::structure($own, $shape) !== Alignment::structure($original, $shape)) {
                $status['drift'][] = $handle;
            }

            $texts = Alignment::texts($own, $shape, $handle);

            foreach (Alignment::texts($original, $shape, $handle) as $path => $text) {
                if (! array_key_exists($path, $texts) || $texts[$path] === $text) {
                    $status['untranslated'][] = $path;
                } elseif (is_array($sources[$path] ?? null) && ($sources[$path]['o'] ?? null) !== self::hash($text)) {
                    $status['outdated'][] = $path;
                }
            }
        }

        return $status;
    }

    private static function hash(mixed $value): string
    {
        return substr(hash('sha256', (string) json_encode($value)), 0, 16);
    }
}
