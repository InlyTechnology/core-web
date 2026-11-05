<?php

namespace Inly\CoreWeb\Buttons;

use Statamic\CP\Column;
use Statamic\Dictionaries\Dictionary as DictionaryInstance;
use Statamic\Facades\Dictionary;
use Statamic\Fieldtypes\Relationship;
use Statamic\Support\Arr;

/**
 * A dictionary's items as a relationship field, so a Core link in a link field is picked like an entry: one inline
 * item with its status dot and menu, chosen from a stack listing, rather than a multi-select with chips.
 */
final class CoreLinkPicker extends Relationship
{
    public const HANDLE = 'core_link_picker';

    protected static $handle = self::HANDLE;

    protected $selectable = false;

    protected $canSearch = true;

    protected $statusIcons = true;

    protected function toItemArray($id)
    {
        $item = $this->dictionary()?->get((string) $id);

        if ($item === null) {
            return $this->invalidItemArray($id);
        }

        return ['id' => (string) $item->value(), 'title' => (string) $item->label(), 'status' => 'published'];
    }

    public function getIndexItems($request)
    {
        $search = is_string($request->search ?? null) && trim($request->search) !== '' ? trim($request->search) : null;

        return collect($this->dictionary()?->options($search) ?? [])
            ->map(fn ($label, $value): array => ['id' => (string) $value, 'title' => (string) $label, 'status' => 'published'])
            ->values();
    }

    protected function getColumns()
    {
        return [Column::make('title')->label(__('Title'))];
    }

    private function dictionary(): ?DictionaryInstance
    {
        $config = $this->config('dictionary');
        $config = is_array($config) ? $config : ['type' => $config];
        $handle = Arr::pull($config, 'type');

        return is_string($handle) && $handle !== '' ? Dictionary::find($handle, $config) : null;
    }
}
