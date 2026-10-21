<?php

namespace Inly\CoreWeb\ContentSync;

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\DraftBase;
use Inly\CoreWeb\Drafts\DraftRefused;
use Inly\CoreWeb\Drafts\DraftWriter;
use Inly\CoreWeb\Drafts\EntryFields;
use Inly\CoreWeb\Drafts\StoredValues;
use Inly\CoreWeb\Proposals\Proposals;
use Inly\CoreWeb\Proposals\ProposalTarget;
use Inly\CoreWeb\Proposals\Targets\GlobalTarget;
use Inly\CoreWeb\Proposals\Targets\NavTarget;
use InvalidArgumentException;
use Statamic\Entries\GetDateFromPath;
use Statamic\Entries\GetSlugFromPath;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\YAML;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The site's half of `content:push`: each file a checkout sends becomes what
 * an editor publishes or applies in the CP, never a change to what is live.
 * An entry is a draft (DraftWriter), a global's values or a navigation tree
 * a proposal (Proposals). Every file names the site's hash of the version
 * it was pulled from (`base`), so a push never undoes a change made on the
 * site since. The whole push is checked first and written only when every
 * file passes, unless it asks to write the ones that do (`partial`).
 * Collection trees (the page order) and terms stay CP edits.
 */
class ContentPush
{
    public const STATUS_PROPOSAL = 'proposal';

    public const STATUS_UNCHANGED = 'unchanged';

    public const STATUS_REFUSED = 'refused';

    public const TYPES = ['entries', 'globals', 'nav-trees'];

    public function __construct(private DraftWriter $writer, private Proposals $proposals, private DraftBase $draftBase) {}

    /**
     * @param  list<array{path: string, contents: string, base?: ?string, parent_id?: ?string}>  $items
     * @param  array<int, string>  $refusedAssets  item index => why an asset it names cannot be pushed
     * @return array{written: bool, results: list<array<string, mixed>>}
     */
    public function push(DraftAuthor $author, array $items, bool $partial, bool $force, bool $dryRun, array $refusedAssets = []): array
    {
        $checked = [];

        foreach ($items as $i => $item) {
            $checked[$i] = isset($refusedAssets[$i])
                ? self::refused($item['path'], 'asset_refused', $refusedAssets[$i])
                : $this->one($author, $item, $force, true);
        }

        $refused = collect($checked)->contains(fn (array $result) => $result['status'] === self::STATUS_REFUSED);

        if ($dryRun || ($refused && ! $partial)) {
            return ['written' => false, 'results' => array_values($checked)];
        }

        $results = [];

        foreach ($items as $i => $item) {
            $results[] = in_array($checked[$i]['status'], [self::STATUS_REFUSED, self::STATUS_UNCHANGED], true)
                ? $checked[$i]
                : $this->one($author, $item, $force, false);
        }

        return ['written' => true, 'results' => $results];
    }

    /**
     * @param  array{path: string, contents: string, base?: ?string, parent_id?: ?string}  $item
     * @return array<string, mixed>
     */
    private function one(DraftAuthor $author, array $item, bool $force, bool $dryRun): array
    {
        $path = $item['path'];

        try {
            $type = self::typeOf($path);

            if ($type === null) {
                return self::refused($path, 'not_pushed', __('core-web::sync.push_not_pushed'));
            }

            $data = self::parse($path, $item['contents']);

            return match ($type) {
                'entries' => $this->entry($author, $path, $data, $item, $force, $dryRun),
                'globals' => $this->proposal($author, $path, $type, $this->globalTarget($path), ['data' => $data], $item, $force, $dryRun),
                'nav-trees' => $this->proposal($author, $path, $type, $this->navTarget($path), ['data' => (array) ($data['tree'] ?? [])], $item, $force, $dryRun),
            };
        } catch (DraftRefused $e) {
            return self::refused($path, $e->error, $e->getMessage(), $e->details);
        } catch (ValidationException $e) {
            return self::refused($path, 'validation', $e->getMessage(), ['errors' => $e->errors()]);
        } catch (HttpExceptionInterface $e) {
            return self::refused($path, $e->getStatusCode() === 404 ? 'not_found' : 'refused', $e->getMessage() ?: __('core-web::sync.push_not_found'));
        } catch (InvalidArgumentException $e) {
            return self::refused($path, 'not_found', $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{path: string, contents: string, base?: ?string, parent_id?: ?string}  $item
     * @return array<string, mixed>
     */
    private function entry(DraftAuthor $author, string $path, array $data, array $item, bool $force, bool $dryRun): array
    {
        ['handle' => $collection, 'site' => $site] = ContentPaths::parse('entries', $path);
        $id = $data['id'] ?? null;

        // Without an id the Stache would write one into the file; a checkout's own saves always carry one.
        if (! is_string($id) || $id === '') {
            return self::refused($path, 'validation', __('core-web::sync.push_no_id'));
        }

        $fields = Arr::except($data, EntryFields::INTERNAL);
        $fields = [...$fields, ...self::slugAndDate($path, $id)];
        $existing = Entry::find($id);

        if ($existing === null) {
            $result = $this->writer->write($author, [
                'collection' => $collection,
                'id' => $id,
                'site' => $site,
                'parent_id' => $item['parent_id'] ?? null,
                'blueprint' => is_string($data['blueprint'] ?? null) ? $data['blueprint'] : null,
                'fields' => $fields,
                'dry_run' => $dryRun,
            ], new StoredValues);

            return self::written($path, $result);
        }

        if ($existing->collectionHandle() !== $collection || $existing->locale() !== $site) {
            return self::refused($path, 'moved', __('core-web::sync.push_moved'));
        }

        if (is_string($data['blueprint'] ?? null) && $data['blueprint'] !== $existing->blueprint()?->handle()) {
            return self::refused($path, 'validation', __('core-web::sync.push_blueprint'));
        }

        if (! $force) {
            $this->assertBase($path, 'entries', $existing, $item['base'] ?? null);
        }

        // A field the checkout's file no longer has was cleared there, as a CP save clears it.
        foreach (array_keys(EntryFields::stored($existing)) as $handle) {
            if (! array_key_exists($handle, $fields)) {
                $fields[$handle] = null;
            }
        }

        $result = $this->writer->write($author, [
            'collection' => $collection,
            'entry_id' => $id,
            'site' => $site,
            'fields' => $fields,
            'base_hash' => $this->draftBase->hash($existing),
            'dry_run' => $dryRun,
        ], new StoredValues);

        return self::written($path, $result);
    }

    /**
     * @param  array{data: array<mixed>}  $value
     * @param  array{path: string, contents: string, base?: ?string, parent_id?: ?string}  $item
     * @return array<string, mixed>
     */
    private function proposal(DraftAuthor $author, string $path, string $type, array $resolved, array $value, array $item, bool $force, bool $dryRun): array
    {
        [$target, $stored] = $resolved;

        // Only the CP page of the drafts feature decides a proposal, so without it one would wait for nobody.
        if (! CoreWebServiceProvider::enabled('drafts')) {
            return self::refused($path, 'proposals_off', __('core-web::sync.push_proposals_off'));
        }

        if (! $force) {
            $this->assertBase($path, $type, $stored, $item['base'] ?? null);
        }

        try {
            $result = $this->proposals->propose($author, $target, $value['data'], Proposals::base($target), $dryRun);
        } catch (DraftRefused $e) {
            if ($e->error === 'unchanged') {
                return ['path' => $path, 'status' => self::STATUS_UNCHANGED];
            }

            throw $e;
        }

        return [
            'path' => $path,
            'status' => self::STATUS_PROPOSAL,
            'target' => $target->key(),
            'changed' => array_keys((array) ($result['changed'] ?? [])),
            'cp_url' => $result['cp_url'] ?? null,
        ];
    }

    /** @return array{0: ProposalTarget, 1: object} */
    private function globalTarget(string $path): array
    {
        ['handle' => $handle, 'site' => $site] = ContentPaths::parse('globals', $path);
        $target = GlobalTarget::make($handle, (string) $site);

        return [$target, self::found(GlobalSet::find($handle)?->in((string) $site))];
    }

    /** @return array{0: ProposalTarget, 1: object} */
    private function navTarget(string $path): array
    {
        ['handle' => $handle, 'site' => $site] = ContentPaths::parse('nav-trees', $path);
        $target = NavTarget::make($handle, (string) $site);

        return [$target, self::found(Nav::find($handle)?->in((string) $site))];
    }

    /** A proposal changes what the site has, so a global set or nav (or site) it lacks refuses the file. */
    private static function found(?object $stored): object
    {
        if ($stored === null) {
            throw new InvalidArgumentException(__('core-web::sync.push_not_found'));
        }

        return $stored;
    }

    /**
     * That the file was pulled from the version the site holds now, at the same
     * path: the site's own export hash of it, which the checkout's lock keeps.
     *
     * @throws DraftRefused|ValidationException
     */
    private function assertBase(string $path, string $type, object $stored, mixed $base): void
    {
        if (blank($base) || ! is_string($base)) {
            throw new DraftRefused('no_base', __('core-web::sync.push_no_base'));
        }

        $current = self::hash($type, $stored);

        if ($current === null || $current['path'] !== $path || ! hash_equals($current['hash'], $base)) {
            throw new DraftRefused('base_changed', __('core-web::sync.push_base_changed'), ['content_hash' => $current['hash'] ?? null]);
        }
    }

    /**
     * The export's path and hash of an item, as a pull records them (ContentExport).
     *
     * @return array{path: string, hash: string}|null
     */
    public static function hash(string $type, object $stored): ?array
    {
        $path = ContentPaths::relative($type, (string) $stored->path());

        if ($path === null) {
            return null;
        }

        return ['path' => $path, 'hash' => hash('sha256', ContentFile::canonical($type, $path, (string) $stored->fileContents()))];
    }

    /** The pushable type a file is, by where it sits; null for one a push never carries. */
    public static function typeOf(string $path): ?string
    {
        foreach (self::TYPES as $type) {
            if (ContentPaths::parse($type, $path) !== null) {
                return $type;
            }
        }

        return null;
    }

    /**
     * The slug and date the Stache reads from an entry's file name.
     *
     * @return array{slug?: string, date?: string}
     */
    private static function slugAndDate(string $path, string $id): array
    {
        $out = [];
        $slug = (string) (new GetSlugFromPath)($path);

        if ($slug !== '' && $slug !== $id) {
            $out['slug'] = $slug;
        }

        $date = (new GetDateFromPath)($path);

        if (is_string($date) && $date !== '') {
            $out['date'] = strlen($date) === 10 ? $date : substr($date, 0, 10).' '.substr($date, 11, 2).':'.substr($date, 13, 2);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function parse(string $path, string $contents): array
    {
        try {
            $data = YAML::file($path)->parse($contents);
        } catch (Throwable) {
            throw ValidationException::withMessages(['contents' => __('core-web::sync.push_unreadable')]);
        }

        if (! is_array($data)) {
            throw ValidationException::withMessages(['contents' => __('core-web::sync.push_unreadable')]);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private static function written(string $path, array $result): array
    {
        return ['path' => $path, ...Arr::only($result, ['status', 'entry_id', 'cp_url', 'preview_url', 'changed_fields', 'ignored_fields', 'images_needed'])];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private static function refused(string $path, string $error, string $message, array $details = []): array
    {
        return ['path' => $path, 'status' => self::STATUS_REFUSED, 'error' => $error, 'message' => $message, ...$details];
    }
}
