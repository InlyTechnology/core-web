<?php

namespace Inly\CoreWeb\Checks\Checks;

use Inly\CoreWeb\Checks\Check;
use Inly\CoreWeb\Checks\ContentWalk;
use Inly\CoreWeb\Checks\Finding;
use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * Published pages search engines and link previews read badly: no meta
 * description, or a title or description longer than a result shows. The
 * fields are the site's (core-web.checks.seo); a page with a URL and no
 * description of its own is the one that matters, since search engines then
 * write one from whatever text they find.
 */
class SeoGaps extends Check
{
    public function key(): string
    {
        return 'seo-gaps';
    }

    public function description(): string
    {
        return 'Published pages without a meta description, or with a title or description too long to show in full.';
    }

    public function detect(): iterable
    {
        $config = (array) config('core-web.checks.seo', []);
        $collections = $config['collections'] ?? null;

        foreach (ContentWalk::entries() as $entry) {
            if (! $entry->published() || blank($entry->url()) || (is_array($collections) && ! in_array($entry->collectionHandle(), $collections, true))) {
                continue;
            }

            yield from $this->inEntry($entry, $config);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return iterable<Finding>
     */
    private function inEntry(EntryContract $entry, array $config): iterable
    {
        $descriptionField = (string) ($config['description'] ?? 'seo_description');
        $titleField = (string) ($config['title'] ?? 'seo_title');
        $description = trim((string) $entry->get($descriptionField));
        $title = trim((string) ($entry->get($titleField) ?: $entry->get('title')));
        $titleMax = (int) ($config['title_max'] ?? 60);
        $descriptionMax = (int) ($config['description_max'] ?? 160);

        $gaps = [];

        if ($description === '') {
            $gaps[$descriptionField] = ['message' => 'No meta description, so search engines write their own.', 'details' => []];
        } elseif (mb_strlen($description) > $descriptionMax) {
            $gaps[$descriptionField] = ['message' => 'The meta description is '.mb_strlen($description)." characters; results show about {$descriptionMax}.", 'details' => ['length' => mb_strlen($description), 'max' => $descriptionMax]];
        }

        if (mb_strlen($title) > $titleMax) {
            $gaps[$entry->get($titleField) ? $titleField : 'title'] = ['message' => 'The title is '.mb_strlen($title)." characters; results show about {$titleMax}.", 'details' => ['length' => mb_strlen($title), 'max' => $titleMax]];
        }

        foreach ($gaps as $path => $gap) {
            yield new Finding(
                check: $this->key(),
                subject: 'entry:'.$entry->id(),
                path: $path,
                message: $gap['message'],
                site: $entry->locale(),
                label: (string) $entry->value('title'),
                cpUrl: $entry->editUrl(),
                details: $gap['details'],
            );
        }
    }
}
