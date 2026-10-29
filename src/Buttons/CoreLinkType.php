<?php

namespace Inly\CoreWeb\Buttons;

use Statamic\Fields\Field;
use Statamic\Fieldtypes\Link;
use Statamic\Fieldtypes\Link\LinkType;

/**
 * A `buttons.core_links` entry as an option of Statamic's link fieldtype, so
 * every link field can pick a page built from Core beside Entry and URL. It
 * stores `{handle}::{pick}` and resolves to the page's address, as a button
 * of the same link type does.
 */
final class CoreLinkType extends LinkType
{
    protected ?string $icon = 'link';

    /** Adds each configured Core link to the link fieldtype; Statamic's own entry and asset types keep their handles. */
    public static function register(): void
    {
        foreach (array_keys(Button::coreLinks()) as $handle) {
            if (! in_array($handle, ['entry', 'asset', 'url', 'first-child'], true)) {
                Link::extend($handle, self::class);
            }
        }
    }

    public function title(): string
    {
        return __(Button::coreLinks()[$this->handle]['display'] ?? ucfirst($this->handle));
    }

    public function visible(Field $field): bool
    {
        return isset(Button::coreLinks()[$this->handle]);
    }

    public function fieldtype(Field $field): ?array
    {
        $link = Button::coreLinks()[$this->handle] ?? null;

        if ($link === null) {
            return null;
        }

        return [
            'type' => 'dictionary',
            'dictionary' => $link['snapshot'] === null ? $link['dictionary'] : ['type' => SnapshotPagesDictionary::HANDLE, 'snapshot' => $link['snapshot']],
            // A string on purpose: the link field reads its nested field's value as a list, which the dictionary input
            // sends only when it is not exactly 1, and the input still stops at one pick.
            'max_items' => '1',
        ];
    }

    public function resolve(string $id, $parent = null, bool $localize = false): mixed
    {
        $url = Button::coreLinkUrl($this->handle, $id);

        return $url === '' ? null : $url;
    }
}
