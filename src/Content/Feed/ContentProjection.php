<?php

namespace Inly\CoreWeb\Content\Feed;

use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Inly\CoreWeb\Buttons\SnapshotPagesDictionary;
use Inly\CoreWeb\Drafts\DraftProvenance;
use Inly\CoreWeb\Drafts\EntryFields;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Markdown;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Group;
use Statamic\Fieldtypes\Replicator;

/**
 * One page as the Statamic connector API projects it (docs/content-feed.md): the protocol's keys and the CMS
 * layer's (collection, slug, working copy, blocks, stored fields). Read from the stored values against the
 * blueprint, never augmented, so it costs no snapshot or asset read and says what the editor picked, not what
 * renders today.
 */
class ContentProjection
{
    public const TEXT_LIMIT = 10000;

    /** Fieldtypes whose stored value is the text a visitor reads (Bard is walked on its own). */
    private const TEXT_FIELDTYPES = ['text', 'textarea', 'list', 'markdown'];

    /** The blueprint tab Peak SEO's fields live in: projected as `seo`, kept out of the text. */
    private const SEO_TAB = 'seo';

    /** @var array<string, int> */
    private array $blocks = [];

    /** @var array<string, array{type: string, id: string, field: string}> */
    private array $shows = [];

    /** @var list<string> */
    private array $texts = [];

    public function __construct(protected PageStatus $status, protected SnapshotRegistry $snapshots) {}

    /** @return array<string, mixed> */
    public function project(Entry $entry): array
    {
        $this->walk($entry);

        $hasWorkingCopy = $entry->revisionsEnabled() && $entry->hasWorkingCopy();

        $projection = [
            'id' => (string) $entry->id(),
            'url' => $entry->absoluteUrl(),
            'title' => (string) $entry->value('title'),
            'status' => $this->status->of($entry),
            'site' => $entry->locale(),
            'text' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', implode(' ', $this->texts))), 0, self::TEXT_LIMIT),
            'updated_at' => self::iso($entry->lastModified()),
            'has_draft' => $hasWorkingCopy || ! $entry->published(),
            'shows' => array_values($this->shows),
            'seo' => [
                'title' => self::text($entry->get('seo_title')),
                'description' => self::text($entry->get('seo_description')),
                'canonical_url' => null,
                'indexable' => ! (bool) $entry->get('seo_noindex', false),
                'social_image' => null,
                'social_title' => self::text($entry->get('og_title')),
                'social_description' => self::text($entry->get('og_description')),
            ],
            'collection' => $entry->collectionHandle(),
            'blueprint' => $entry->blueprint()?->handle(),
            'slug' => $entry->slug(),
            'published' => $entry->published(),
            'date' => $entry->hasDate() && $entry->date() ? self::iso($entry->date()) : null,
            'updated_by' => self::person($entry->lastModifiedBy()),
            'working_copy' => $hasWorkingCopy ? self::workingCopy($entry) : null,
            'blocks' => array_map(fn (string $type, int $count): array => ['type' => $type, 'count' => $count], array_keys($this->blocks), $this->blocks),
            'fields' => EntryFields::of(EntryFields::draftOf($entry)),
        ];

        return [...$projection, 'content_hash' => self::hash($projection)];
    }

    /**
     * SHA-256 over the projection without what changes when nothing a reader sees does.
     *
     * @param  array<string, mixed>  $projection
     */
    public static function hash(array $projection): string
    {
        return hash('sha256', json_encode(
            Arr::except($projection, ['updated_at', 'has_draft', 'content_hash']),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /** @return array{by: ?string, since: ?string, message: ?string, via: ?string} */
    private static function workingCopy(Entry $entry): array
    {
        $copy = $entry->workingCopy();

        return ['by' => self::person($copy->user()), 'since' => $copy->date() ? self::iso($copy->date()) : null, 'message' => self::text($copy->message()), 'via' => DraftProvenance::via($copy)];
    }

    private function walk(Entry $entry): void
    {
        $this->blocks = [];
        $this->shows = [];
        $this->texts = [];

        $blueprint = $entry->blueprint();

        if ($blueprint === null) {
            return;
        }

        $values = $entry->values()->all();
        $untexted = ['title', ...$blueprint->tabs()->get(self::SEO_TAB)?->fields()->all()->keys()->all() ?? []];

        foreach ($blueprint->fields()->all() as $handle => $field) {
            if (array_key_exists($handle, $values)) {
                $this->value($field, $values[$handle], (string) $handle, ! in_array($handle, $untexted, true));
            }
        }
    }

    /** @param  array<string, mixed>  $values */
    private function fields(Fields $fields, array $values, string $path, bool $texted): void
    {
        foreach ($fields->all() as $handle => $field) {
            if (array_key_exists($handle, $values)) {
                $this->value($field, $values[$handle], $path.'.'.$handle, $texted);
            }
        }
    }

    private function value(Field $field, mixed $value, string $path, bool $texted): void
    {
        $fieldtype = $field->fieldtype();

        // Bard first: it is a Replicator too, but stores ProseMirror nodes rather than sets.
        if ($fieldtype instanceof Bard) {
            $this->bard($fieldtype, $value, $path, $texted);

            return;
        }

        if ($fieldtype instanceof Replicator) {
            foreach (is_array($value) ? $value : [] as $index => $set) {
                $this->set($fieldtype, $set, $path.'.'.$index, $texted);
            }

            return;
        }

        if ($fieldtype instanceof Grid) {
            foreach (is_array($value) ? $value : [] as $index => $row) {
                if (is_array($row)) {
                    $this->fields($fieldtype->fields(), $row, $path.'.'.$index, $texted);
                }
            }

            return;
        }

        if ($fieldtype instanceof Group) {
            if (is_array($value)) {
                $this->fields($fieldtype->fields(), $value, $path, $texted);
            }

            return;
        }

        if ($field->type() === 'dictionary') {
            $this->picks($field, $value, $path);

            return;
        }

        if ($texted && self::isReadText($field)) {
            $this->texts($field->type(), $value);
        }
    }

    /** One set of a Replicator or a Bard: counted as a block, its fields walked. A disabled set renders nowhere. */
    private function set(Replicator $fieldtype, mixed $set, string $path, bool $texted): void
    {
        if (! is_array($set) || ($set['enabled'] ?? true) === false || ! is_string($type = $set['type'] ?? null)) {
            return;
        }

        $this->blocks[$type] = ($this->blocks[$type] ?? 0) + 1;
        $this->fields($fieldtype->fields($type), $set, $path, $texted);
    }

    private function bard(Bard $fieldtype, mixed $value, string $path, bool $texted): void
    {
        if (is_string($value)) {
            if ($texted) {
                $this->texts[] = self::plain($value);
            }

            return;
        }

        foreach (is_array($value) ? $value : [] as $index => $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? null) === 'set') {
                $this->set($fieldtype, [
                    ...(array) ($node['attrs']['values'] ?? []),
                    'enabled' => $node['attrs']['enabled'] ?? true,
                ], $path.'.'.$index, $texted);

                continue;
            }

            if ($texted) {
                $this->texts[] = self::nodeText($node);
            }
        }
    }

    /**
     * Every pick of a Dictionary backed by Core: a `core_snapshot_pages` Dictionary names its object type through its
     * snapshot's listing, and a site's own through `content.feed.dictionaries`. A stored `{key}|{id}` pick names its id last.
     */
    private function picks(Field $field, mixed $value, string $path): void
    {
        $type = $this->pickType($field);

        if ($type === null) {
            return;
        }

        foreach (Arr::wrap($value) as $pick) {
            $id = is_scalar($pick) ? trim(Str::afterLast((string) $pick, '|')) : '';

            if ($id !== '') {
                $this->shows["{$type}|{$id}|{$path}"] = ['type' => $type, 'id' => $id, 'field' => $path];
            }
        }
    }

    private function pickType(Field $field): ?string
    {
        $dictionary = $field->get('dictionary');
        $handle = is_array($dictionary) ? ($dictionary['type'] ?? null) : $dictionary;

        if (! is_string($handle) || $handle === '') {
            return null;
        }

        if ($handle === SnapshotPagesDictionary::HANDLE) {
            $snapshot = is_array($dictionary) ? (string) ($dictionary['snapshot'] ?? '') : '';

            return $snapshot !== '' && $this->snapshots->has($snapshot) ? $this->snapshots->get($snapshot)->coreType() : null;
        }

        $type = config("core-web.content.feed.dictionaries.{$handle}");

        return is_string($type) && $type !== '' ? $type : null;
    }

    private function texts(string $fieldtype, mixed $value): void
    {
        foreach (Arr::flatten(Arr::wrap($value)) as $text) {
            if (is_string($text) && trim($text) !== '' && ! self::isAddress($text)) {
                $this->texts[] = $fieldtype === 'markdown' ? self::plain(Markdown::parse($text)) : $text;
            }
        }
    }

    /** Text a visitor reads: not a text field holding an address (input_type url, email, tel), nor an icon picked by name. */
    private static function isReadText(Field $field): bool
    {
        if (! in_array($field->type(), self::TEXT_FIELDTYPES, true)) {
            return false;
        }

        if ($field->type() === 'text' && ! in_array($field->get('input_type', 'text'), ['text', null], true)) {
            return false;
        }

        return preg_match('/(?:^|_)icon(?:_|$)/', (string) $field->handle()) !== 1;
    }

    /** A link typed into a plain text field (`#team`, `/contact`, `?type=guides`, `https://…`). */
    private static function isAddress(string $text): bool
    {
        return preg_match('~^\s*(?:#|/|\?|https?://|mailto:|tel:|entry::|asset::)\S*\s*$~i', $text) === 1;
    }

    /** @param  array<string, mixed>  $node  a ProseMirror node: its block children apart, its inline runs together */
    private static function nodeText(array $node): string
    {
        return match ($node['type'] ?? null) {
            'text' => (string) ($node['text'] ?? ''),
            'hardBreak' => ' ',
            default => ' '.implode('', array_map(fn ($child) => is_array($child) ? self::nodeText($child) : '', (array) ($node['content'] ?? []))).' ',
        };
    }

    private static function plain(string $html): string
    {
        // A space before every tag keeps "<p>a</p><p>b</p>" two words.
        return html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function person(mixed $user): ?string
    {
        return $user instanceof User ? (self::text($user->name()) ?? self::text($user->email())) : null;
    }

    private static function iso(CarbonInterface $date): string
    {
        return $date->copy()->utc()->toIso8601String();
    }
}
