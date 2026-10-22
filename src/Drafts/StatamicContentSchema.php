<?php

namespace Inly\CoreWeb\Drafts;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Dictionary as Dictionaries;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Taxonomy;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Group;
use Statamic\Fieldtypes\Replicator;
use Throwable;

/**
 * The content schema read from the blueprints and fieldsets as Statamic
 * resolves them, never written by hand: a new block or field is in it the
 * moment its fieldset is. Fields an editor never fills (UI-only controls,
 * hidden and computed fields) are left out. A block is a set of a top-level
 * Replicator (a page builder); a Bard's sets are described with its field.
 */
class StatamicContentSchema implements ContentSchema
{
    public const EXAMPLE_LIMIT = 3;

    /** Fieldtypes that lay out the form and store nothing. */
    private const UI_ONLY_FIELDTYPES = ['section', 'revealer', 'html', 'spacer'];

    /** Visibilities of a field an editor never types into. */
    private const UNFILLED_VISIBILITIES = ['hidden', 'computed', 'read_only'];

    private const CONDITION_KEYS = ['if', 'if_any', 'show_when', 'show_when_any', 'unless', 'unless_any', 'hide_when', 'hide_when_any'];

    /** Field config passed through as it is, when the blueprint sets it. */
    private const PASSED_CONFIG = [
        'input_type', 'character_limit', 'placeholder', 'max_items', 'max_files', 'min_files',
        'min_rows', 'max_rows', 'multiple', 'time_enabled', 'select_across_sites',
    ];

    /** The stored value of each fieldtype, as an entry's YAML holds it; core-web.drafts.schema.value_formats adds to and overrides it. */
    private const VALUE_FORMATS = [
        'text' => 'string',
        'textarea' => 'string, plain text; line breaks are kept',
        'markdown' => 'string of Markdown',
        'list' => 'array of strings, one item each',
        'toggle' => 'boolean',
        'integer' => 'integer',
        'float' => 'number',
        'select' => 'an option key',
        'button_group' => 'an option key',
        'radio' => 'an option key',
        'checkboxes' => 'array of option keys',
        'color' => 'hex colour, one of `swatches`',
        'slug' => 'lowercase a-z, 0-9 and hyphens',
        'date' => '`Y-m-d`, or `Y-m-d H:i` when `time_enabled`',
        'link' => '`entry::{entry id}` for an entry of this site, an absolute URL otherwise',
        'entries' => 'entry id',
        'terms' => 'term slug',
        'assets' => 'the path the entry already stores, in the field\'s `assets.container`, to keep it',
        'code' => 'string',
        'dictionary' => 'a dictionary key; see `dictionary.value_format` where it has one',
        'table' => 'array of rows: `{ cells: [string, …] }`',
        'bard' => 'array of ProseMirror nodes (`paragraph`, `heading` with `attrs.level`, `bulletList`, `orderedList`, `blockquote` …, text marks `bold`, `italic`, `link` with `attrs.href`); an internal link is `statamic://entry::{entry id}`; a set is `{ type: set, attrs: { id, enabled: true, values: { type: {set handle}, …its fields } } }`',
        'replicator' => 'array of sets: `{ id, type: {set handle}, enabled: true, …its fields }`',
        'grid' => 'array of rows: `{ id, …its fields }`',
        'group' => 'object of its fields',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $collections = null;

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $usage = [];

    /** @var array{guide_index: array{path: string, anchor: ?string}|null, article_blocks: list<string>, page_blocks: list<string>}|null */
    private ?array $guide = null;

    public function describe(?string $collection = null): array
    {
        $collections = array_filter(
            $this->collections(),
            fn (array $definition) => $collection === null || $definition['handle'] === $collection,
        );

        return [
            'catalog_hash' => $this->hash(),
            ...$this->guide(),
            'collections' => array_values(array_map(fn (array $definition) => $this->summary($definition), $collections)),
        ];
    }

    public function block(string $collection, string $handle): ?array
    {
        $block = $this->collections()[$collection]['blocks'][$handle] ?? null;

        if ($block === null) {
            return null;
        }

        return [
            'catalog_hash' => $this->hash(),
            'data' => ['collection' => $collection, ...$block, 'usage' => self::present($this->usage($collection)[$handle] ?? self::unused())],
        ];
    }

    /** sha256 of the schema without its usage, which moves with every save: a blueprint, fieldset, taxonomy term or guide link moves it. */
    public function hash(): string
    {
        return hash('sha256', json_encode([$this->guide(), array_values($this->collections())], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** The status an entry's usage example shows: a site that hides published entries by its own rules says so here. */
    protected function status(Entry $entry): string
    {
        return $entry->status();
    }

    /**
     * How the slug and the date are made and what they do, in the words of
     * core-web::drafts (a site words them for its language in
     * lang/vendor/core-web).
     *
     * @return array<string, string>
     */
    protected function entryRules(Field $field, bool $dated): array
    {
        if ($field->handle() === 'slug' && $field->type() === 'slug') {
            return ['rules' => __('core-web::drafts.schema_slug_rules')];
        }

        if ($field->handle() === 'date' && $dated) {
            return ['rules' => __('core-web::drafts.schema_date_rules')];
        }

        return [];
    }

    /**
     * The guide's page recipe and the blocks it recommends for an article
     * and a page, from the page builder's fieldset.
     *
     * @return array{guide_index: array{path: string, anchor: ?string}|null, article_blocks: list<string>, page_blocks: list<string>}
     */
    private function guide(): array
    {
        if ($this->guide !== null) {
            return $this->guide;
        }

        $contents = self::fieldsetContents(self::config('guide_fieldset', 'page_builder'));

        return $this->guide = [
            'guide_index' => self::guideLink($contents['guide_index'] ?? null),
            'article_blocks' => array_values(array_filter((array) ($contents['article_blocks'] ?? []), 'is_string')),
            'page_blocks' => array_values(array_filter((array) ($contents['page_blocks'] ?? []), 'is_string')),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function collections(): array
    {
        if ($this->collections !== null) {
            return $this->collections;
        }

        $settingsFieldset = self::config('settings_fieldset', 'block');
        $settings = $settingsFieldset === null ? [] : (Fieldset::find($settingsFieldset)?->fields()->all()->keys()->all() ?? []);
        $this->collections = [];

        foreach (DraftableCollections::handles() as $handle) {
            $collection = Collections::find($handle);

            if ($collection === null) {
                continue;
            }

            $blueprints = [];
            $blocks = [];

            foreach ($collection->entryBlueprints() as $blueprint) {
                $builders = [];

                foreach (self::builders($blueprint) as $field) {
                    $sets = $this->sets($field, $settings, $settingsFieldset);
                    $builders[] = ['field' => $field->handle(), 'type' => $field->type(), 'blocks' => array_keys($sets)];

                    foreach ($sets as $setHandle => $set) {
                        // One namespace per collection: a handle is one block wherever it is used.
                        $blocks[$setHandle] ??= [...$set, 'builders' => []];
                        $blocks[$setHandle]['builders'][] = ['blueprint' => $blueprint->handle(), 'field' => $field->handle()];
                    }
                }

                $blueprints[] = array_filter([
                    'handle' => $blueprint->handle(),
                    'title' => $blueprint->title(),
                    'hidden' => $blueprint->hidden() ?: null,
                    'builders' => $builders,
                    'entry_fields' => $this->entryFields($blueprint, $collection->dated()),
                ], fn ($value) => $value !== null);
            }

            $this->collections[$handle] = [
                'handle' => $handle,
                'title' => $collection->title(),
                'route' => $collection->route(Arr::first($collection->sites()->all())),
                'sites' => $collection->sites()->values()->all(),
                'dated' => $collection->dated(),
                'date_behavior' => $collection->dated() ? ['past' => $collection->pastDateBehavior(), 'future' => $collection->futureDateBehavior()] : null,
                'blueprints' => $blueprints,
                'blocks' => $blocks,
            ];
        }

        return $this->collections;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function summary(array $definition): array
    {
        $usage = $this->usage($definition['handle']);

        $blocks = array_map(fn (array $block) => [
            'handle' => $block['handle'],
            'display' => $block['display'],
            'instructions' => $block['instructions'],
            'ai_instructions' => $block['ai_instructions'],
            'group' => $block['group'],
            'builders' => $block['builders'],
            'fields' => array_column($block['fields'], 'type', 'handle'),
            'required' => array_values(array_column(array_filter($block['fields'], fn (array $field) => $field['required']), 'handle')),
            'settings' => array_column($block['settings'], 'handle'),
            'guide' => $block['guide'],
            'usage' => self::present($usage[$block['handle']] ?? self::unused()),
        ], array_values($definition['blocks']));

        return [
            ...Arr::except($definition, 'blocks'),
            'blocks' => $blocks,
        ];
    }

    /** @return list<Field> the top-level Replicators of a blueprint: its page builders */
    private static function builders(Blueprint $blueprint): array
    {
        return $blueprint->fields()->all()
            ->filter(fn (Field $field) => $field->fieldtype() instanceof Replicator && ! $field->fieldtype() instanceof Bard && ! self::isUnfilled($field))
            ->values()
            ->all();
    }

    /**
     * Every field of the entry outside its builders, with the tab and section it is in.
     *
     * @return list<array<string, mixed>>
     */
    private function entryFields(Blueprint $blueprint, bool $dated): array
    {
        $builders = array_map(fn (Field $field) => $field->handle(), self::builders($blueprint));
        $fields = [];

        foreach ($blueprint->tabs() as $tab) {
            foreach ($tab->sections() as $section) {
                $sectionFields = $section->fields();
                $revealers = self::revealers($sectionFields);

                foreach ($sectionFields->all() as $handle => $field) {
                    if (in_array($handle, $builders, true) || self::isUnfilled($field)) {
                        continue;
                    }

                    $fields[] = [
                        ...$this->field($field, $revealers),
                        'tab' => $tab->handle(),
                        'section' => $section->display(),
                        ...$this->entryRules($field, $dated),
                    ];
                }
            }
        }

        return $fields;
    }

    /**
     * A Replicator's sets, each with its group, own fields, the block settings
     * it carries, and its guide section. A set hidden from the picker is left out.
     *
     * @param  list<string>  $settings  the settings fieldset's handles
     * @return array<string, array<string, mixed>>
     */
    private function sets(Field $field, array $settings = [], ?string $settingsFieldset = null): array
    {
        /** @var Replicator $fieldtype */
        $fieldtype = $field->fieldtype();
        $sets = [];

        foreach (self::groupedSets($field) as $group) {
            foreach ($group['sets'] as $handle => $config) {
                if (! empty($config['hide'])) {
                    continue;
                }

                $own = self::ownFieldset($config, $settingsFieldset);
                $setFields = $fieldtype->fields($handle);
                $revealers = self::revealers($setFields);
                $described = $setFields->all()
                    ->reject(fn (Field $setField) => self::isUnfilled($setField))
                    ->map(fn (Field $setField) => $this->field($setField, $revealers));

                $sets[$handle] = [
                    'handle' => $handle,
                    'display' => (string) ($config['display'] ?? Str::headline($handle)),
                    'instructions' => self::text($config['instructions'] ?? null),
                    'ai_instructions' => self::text($own['ai_instructions'] ?? null),
                    'group' => $group['handle'] === null ? null : ['handle' => $group['handle'], 'display' => $group['display']],
                    'fields' => $described->reject(fn (array $described, string $setHandle) => in_array($setHandle, $settings, true))->values()->all(),
                    'settings' => $described->filter(fn (array $described, string $setHandle) => in_array($setHandle, $settings, true))->values()->all(),
                    'guide' => self::guideLink($own['guide'] ?? null),
                ];
            }
        }

        return $sets;
    }

    /**
     * A Replicator's sets by group, as the "Add block" picker tabs them; the
     * ungrouped (legacy) format is one group without a name.
     *
     * @return list<array{handle: ?string, display: ?string, sets: array<string, array<string, mixed>>}>
     */
    private static function groupedSets(Field $field): array
    {
        $sets = (array) $field->get('sets', []);

        if ($sets !== [] && ! Arr::has(Arr::first($sets), 'sets')) {
            return [['handle' => null, 'display' => null, 'sets' => $sets]];
        }

        return array_values(array_map(
            fn (array $group, string $handle) => ['handle' => $handle, 'display' => (string) ($group['display'] ?? Str::headline($handle)), 'sets' => (array) ($group['sets'] ?? [])],
            $sets,
            array_keys($sets),
        ));
    }

    /**
     * One field as an AI needs it to write a value: what it is, whether it
     * must be filled and by which rules, its options and default, when it
     * applies, how its value is stored, and its nested fields.
     *
     * @param  list<string>  $revealers  sibling handles of UI-only toggles, dropped from conditions
     * @return array<string, mixed>
     */
    private function field(Field $field, array $revealers): array
    {
        $type = $field->type();
        $config = $field->config();
        $fieldtype = $field->fieldtype();
        // A rule only drafts are held to reads like any other.
        $validate = self::rules([...(array) ($config['validate'] ?? []), ...(array) ($config['draft_validate'] ?? [])]);

        $described = [
            'handle' => $field->handle(),
            'type' => $type,
            'display' => (string) $field->display(),
            'instructions' => self::text($config['instructions'] ?? null),
            'ai_instructions' => self::text($config['ai_instructions'] ?? null),
            'required' => (bool) ($config['required'] ?? false) || in_array('required', $validate, true),
            'validate' => $validate ?: null,
            ...Arr::only($config, self::PASSED_CONFIG),
            'default' => $config['default'] ?? null,
            'options' => self::options($config),
            'swatches' => $type === 'color' ? ($config['swatches'] ?? null) : null,
            'conditions' => self::conditions($config, $revealers),
            'ai_fillable' => ($config['ai_fillable'] ?? true) === false ? false : null,
            'value' => self::valueFormat($field),
        ];

        $described += match (true) {
            $fieldtype instanceof Bard => ['toolbar' => $config['buttons'] ?? null, 'sets' => $this->nestedSets($field)],
            $fieldtype instanceof Replicator => ['sets' => $this->nestedSets($field)],
            $fieldtype instanceof Grid, $fieldtype instanceof Group => ['fields' => $this->nestedFields($fieldtype->fields())],
            $type === 'dictionary' => ['dictionary' => self::dictionary($config)],
            $type === 'assets' => ['assets' => self::assets($config, $validate)],
            $type === 'entries' => ['collections' => $config['collections'] ?? null],
            $type === 'terms' => ['terms' => self::terms($config)],
            default => [],
        };

        return array_filter($described, fn ($value) => $value !== null && $value !== []);
    }

    /** @return list<array<string, mixed>> */
    private function nestedFields(Fields $fields): array
    {
        $revealers = self::revealers($fields);

        return $fields->all()
            ->reject(fn (Field $field) => self::isUnfilled($field))
            ->map(fn (Field $field) => $this->field($field, $revealers))
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>>|null */
    private function nestedSets(Field $field): ?array
    {
        $sets = array_map(
            fn (array $set) => array_filter(Arr::only($set, ['handle', 'display', 'instructions', 'ai_instructions', 'fields']), fn ($value) => $value !== null),
            array_values($this->sets($field)),
        );

        return $sets ?: null;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function dictionary(array $config): array
    {
        $dictionary = $config['dictionary'] ?? null;
        $handle = is_array($dictionary) ? ($dictionary['type'] ?? null) : $dictionary;
        $stored = self::storedValue(is_string($handle) ? $handle : null, is_array($dictionary) ? Arr::except($dictionary, 'type') : []);

        return array_filter([
            'handle' => $handle,
            ...Arr::except($stored, ['format', 'handle', 'config']),
            'value_format' => $stored['format'] ?? null,
            'config' => is_array($dictionary) ? (Arr::except($dictionary, 'type') ?: null) : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function storedValue(?string $handle, array $context): array
    {
        if ($handle === null) {
            return [];
        }

        try {
            $dictionary = Dictionaries::find($handle, $context);
        } catch (Throwable) {
            return [];
        }

        return $dictionary instanceof DescribesStoredValue ? $dictionary->storedValue() : [];
    }

    /**
     * The container a pick comes from and what it may be: images unless the
     * field's rules name only other file types.
     *
     * @param  array<string, mixed>  $config
     * @param  list<string>  $validate
     * @return array<string, mixed>
     */
    private static function assets(array $config, array $validate): array
    {
        $mimes = collect($validate)
            ->filter(fn (string $rule) => Str::startsWith($rule, ['mimes:', 'mimetypes:']))
            ->flatMap(fn (string $rule) => explode(',', Str::after($rule, ':')))
            ->values()
            ->all();

        $images = $mimes === [] || in_array('image', $validate, true)
            || collect($mimes)->contains(fn (string $mime) => Str::startsWith($mime, 'image/') || in_array($mime, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'], true));

        return array_filter([
            'container' => $config['container'] ?? null,
            'folder' => $config['folder'] ?? null,
            'restrict' => $config['restrict'] ?? null,
            'images' => $images,
            'only_images' => in_array('image', $validate, true) ?: null,
            'file_types' => $mimes ?: null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{taxonomy: string, slug: string, title: string}>|null
     */
    private static function terms(array $config): ?array
    {
        $terms = collect((array) ($config['taxonomies'] ?? []))
            ->map(fn (string $handle) => Taxonomy::find($handle))
            ->filter()
            ->flatMap(fn ($taxonomy) => $taxonomy->queryTerms()->get()->map(fn ($term) => ['taxonomy' => $taxonomy->handle(), 'slug' => $term->slug(), 'title' => (string) $term->title()]))
            ->unique(fn (array $term) => $term['taxonomy'].'/'.$term['slug'])
            ->sortBy(fn (array $term) => $term['taxonomy'].'/'.$term['slug'])
            ->values()
            ->all();

        return $terms ?: null;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>|null option key => label
     */
    private static function options(array $config): ?array
    {
        if (! isset($config['options']) || ! is_array($config['options'])) {
            return null;
        }

        $options = [];

        foreach ($config['options'] as $key => $option) {
            match (true) {
                is_array($option) => $options[(string) ($option['key'] ?? $key)] = (string) ($option['value'] ?? $option['key'] ?? $key),
                is_int($key) => $options[(string) $option] = (string) $option,
                default => $options[(string) $key] = (string) ($option ?? $key),
            };
        }

        return $options ?: null;
    }

    /**
     * When a field applies, without the conditions on a revealer: a revealer
     * only folds the form, and the fields behind it are stored either way.
     *
     * @param  array<string, mixed>  $config
     * @param  list<string>  $revealers
     * @return array<string, mixed>|null
     */
    private static function conditions(array $config, array $revealers): ?array
    {
        $conditions = [];

        foreach (self::CONDITION_KEYS as $key) {
            if (! isset($config[$key])) {
                continue;
            }

            $kept = is_array($config[$key]) ? Arr::except($config[$key], $revealers) : $config[$key];

            if ($kept !== []) {
                $conditions[$key] = $kept;
            }
        }

        return $conditions ?: null;
    }

    private static function valueFormat(Field $field): ?string
    {
        $type = $field->type();
        $format = [...self::VALUE_FORMATS, ...array_filter((array) config('core-web.drafts.schema.value_formats', []), 'is_string')][$type] ?? null;

        if ($format === null) {
            return null;
        }

        $single = match ($type) {
            'entries', 'terms', 'dictionary' => (int) $field->get('max_items') === 1,
            'assets' => (int) $field->get('max_files') === 1,
            'select' => ! $field->get('multiple'),
            default => null,
        };

        return match ($single) {
            true => $format.'; one value',
            false => $type === 'select' ? 'array of option keys' : 'array: '.$format.' each',
            null => $format,
        };
    }

    /** @return list<string> */
    private static function revealers(Fields $fields): array
    {
        return $fields->all()->filter(fn (Field $field) => $field->type() === 'revealer')->keys()->all();
    }

    private static function isUnfilled(Field $field): bool
    {
        return in_array($field->type(), self::UI_ONLY_FIELDTYPES, true)
            || in_array($field->visibility(), self::UNFILLED_VISIBILITIES, true);
    }

    /** @return list<string> */
    private static function rules(mixed $rules): array
    {
        $rules = is_string($rules) ? explode('|', $rules) : (array) $rules;

        return array_values(array_filter(array_map(fn ($rule) => is_string($rule) ? $rule : null, $rules)));
    }

    /**
     * Usage of every set handle in a collection's entries: in how many
     * entries, per site, and a few of them, published ones and the latest
     * changed first. A disabled set renders nowhere, so it counts for nothing.
     *
     * @return array<string, array<string, mixed>>
     */
    private function usage(string $collection): array
    {
        if (isset($this->usage[$collection])) {
            return $this->usage[$collection];
        }

        $entries = Entries::query()->where('collection', $collection)->get()
            ->sortBy([
                fn (Entry $a, Entry $b) => (int) $b->published() <=> (int) $a->published(),
                fn (Entry $a, Entry $b) => $b->lastModified()->getTimestamp() <=> $a->lastModified()->getTimestamp(),
                fn (Entry $a, Entry $b) => strcmp((string) $a->id(), (string) $b->id()),
            ]);

        $usage = [];

        foreach ($entries as $entry) {
            foreach (array_keys(self::blocksIn($entry)) as $handle) {
                $usage[$handle] ??= self::unused();
                $usage[$handle]['entries']++;
                $usage[$handle]['by_site'][$entry->locale()] = ($usage[$handle]['by_site'][$entry->locale()] ?? 0) + 1;

                if (count($usage[$handle]['examples']) < self::EXAMPLE_LIMIT) {
                    $usage[$handle]['examples'][] = [
                        'id' => (string) $entry->id(),
                        'site' => $entry->locale(),
                        'title' => (string) $entry->value('title'),
                        'url' => $entry->absoluteUrl(),
                        'status' => $this->status($entry),
                    ];
                }
            }
        }

        foreach ($usage as &$counted) {
            ksort($counted['by_site']);
        }

        return $this->usage[$collection] = $usage;
    }

    /** @return array<string, int> every set handle an entry uses at any depth, with its count */
    private static function blocksIn(Entry $entry): array
    {
        $blueprint = $entry->blueprint();

        if ($blueprint === null) {
            return [];
        }

        $blocks = [];
        self::countIn($blueprint->fields(), $entry->values()->all(), $blocks);

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, int>  $blocks
     */
    private static function countIn(Fields $fields, array $values, array &$blocks): void
    {
        foreach ($fields->all() as $handle => $field) {
            $value = $values[$handle] ?? null;

            if (! is_array($value)) {
                continue;
            }

            $fieldtype = $field->fieldtype();

            match (true) {
                $fieldtype instanceof Bard => self::countSets($fieldtype, array_map(
                    fn ($node) => is_array($node) && ($node['type'] ?? null) === 'set' ? [...(array) ($node['attrs']['values'] ?? []), 'enabled' => $node['attrs']['enabled'] ?? true] : null,
                    $value,
                ), $blocks),
                $fieldtype instanceof Replicator => self::countSets($fieldtype, $value, $blocks),
                $fieldtype instanceof Grid => array_map(fn ($row) => is_array($row) ? self::countIn($fieldtype->fields(), $row, $blocks) : null, $value),
                $fieldtype instanceof Group => self::countIn($fieldtype->fields(), $value, $blocks),
                default => null,
            };
        }
    }

    /**
     * @param  array<mixed>  $sets
     * @param  array<string, int>  $blocks
     */
    private static function countSets(Replicator $fieldtype, array $sets, array &$blocks): void
    {
        foreach ($sets as $set) {
            if (! is_array($set) || ($set['enabled'] ?? true) === false || ! is_string($type = $set['type'] ?? null)) {
                continue;
            }

            $blocks[$type] = ($blocks[$type] ?? 0) + 1;
            self::countIn($fieldtype->fields($type), $set, $blocks);
        }
    }

    /**
     * Usage as it is answered: `by_site` stays an object when no site uses the block.
     *
     * @param  array{entries: int, by_site: array<string, int>, examples: list<array<string, mixed>>}  $usage
     * @return array<string, mixed>
     */
    private static function present(array $usage): array
    {
        return [...$usage, 'by_site' => (object) $usage['by_site']];
    }

    /** @return array{entries: int, by_site: array<string, int>, examples: list<array<string, mixed>>} */
    private static function unused(): array
    {
        return ['entries' => 0, 'by_site' => [], 'examples' => []];
    }

    /**
     * The contents of a set's own fieldset: the one it imports beside the
     * shared settings. A set's own config cannot hold keys of the site's
     * (`guide`, `ai_instructions`): the CP's set editor drops them on a re-save.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function ownFieldset(array $config, ?string $settingsFieldset): array
    {
        $imports = collect((array) ($config['fields'] ?? []))
            ->map(fn ($field) => is_array($field) ? ($field['import'] ?? null) : null)
            ->filter(fn ($import) => is_string($import) && $import !== $settingsFieldset)
            ->values();

        return $imports->count() === 1 ? self::fieldsetContents($imports->first()) : [];
    }

    /** @return array<string, mixed> */
    private static function fieldsetContents(?string $handle): array
    {
        return $handle === null ? [] : (Fieldset::find($handle)?->contents() ?? []);
    }

    private static function config(string $key, string $default): ?string
    {
        $value = config("core-web.drafts.schema.{$key}", $default);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A section of the editor guide, written `{path}#{anchor}`.
     *
     * @return array{path: string, anchor: ?string}|null
     */
    private static function guideLink(mixed $link): ?array
    {
        if (self::text($link) === null) {
            return null;
        }

        $anchor = Str::contains($link, '#') ? Str::after($link, '#') : '';

        return ['path' => trim(Str::before($link, '#')), 'anchor' => $anchor !== '' ? $anchor : null];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
