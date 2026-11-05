<?php

namespace Inly\CoreWeb\ContentSync;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\DraftBase;
use Inly\CoreWeb\Drafts\DraftRefused;
use Inly\CoreWeb\Drafts\DraftWriter;
use Inly\CoreWeb\Drafts\EntryFields;
use Inly\CoreWeb\Drafts\FieldConditions;
use Inly\CoreWeb\Drafts\StoredValues;
use Inly\CoreWeb\Proposals\Proposals;
use Inly\CoreWeb\Proposals\ProposalTarget;
use Inly\CoreWeb\Proposals\Targets\CollectionTreeTarget;
use Inly\CoreWeb\Proposals\Targets\GlobalTarget;
use Inly\CoreWeb\Proposals\Targets\NavTarget;
use Inly\CoreWeb\Proposals\Targets\TermTarget;
use InvalidArgumentException;
use Statamic\Contracts\Taxonomies\Term as TermContract;
use Statamic\Entries\GetDateFromPath;
use Statamic\Entries\GetSlugFromPath;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\YAML;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The site's half of `content:push`: each file a checkout sends becomes what
 * an editor publishes or applies in the CP. An entry is a draft
 * (DraftWriter); a term's values, a global's values, a collection's page
 * tree or a navigation tree is a proposal (Proposals). Every file names the
 * site's hash of the version it was pulled from (`base`), so a push never
 * undoes a change made on the site since. The whole push is checked first
 * and written only when every file passes, unless it asks to write the ones
 * that do (`partial`). A push that asks to publish (`publish`, on a site
 * with `content_sync.push_publish`) then publishes each draft and applies
 * each proposal as the person, once every file was written: what their
 * permissions do not allow stays a draft or proposal. Statamic keeps no
 * draft of a term, so a new term is created only by such a push.
 */
class ContentPush
{
    public const STATUS_PROPOSAL = 'proposal';

    public const STATUS_UNCHANGED = 'unchanged';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_APPLIED = 'applied';

    /** A term the push creates when it publishes, which until then exists only in the checkout. */
    public const STATUS_NEW_TERM = 'new_term';

    /** In the order a push writes them: entries before the trees that may place them. */
    public const TYPES = ['entries', 'terms', 'globals', 'collection-trees', 'nav-trees'];

    /** @var array<string, true> ids of the entries this push creates, which its trees may already hold */
    private array $newEntries = [];

    /** @var array<string, array{hash: string, tree: array<mixed>}> each pushed page tree on the site as the write began */
    private array $treeBases = [];

    /** @var array<string, array{taxonomy: string, slug: string, blueprint: ?string, values: array<string, mixed>}> */
    private array $newTerms = [];

    private bool $publishing = false;

    /** Fields a push never sets or clears: who wrote an entry is the site's record, not the checkout's. */
    private const KEPT = ['author'];

    public function __construct(private DraftWriter $writer, private Proposals $proposals, private DraftBase $draftBase) {}

    /**
     * @param  list<array{path: string, contents: string, base?: ?string, draft?: ?string, parent_id?: ?string, arriving_assets?: list<string>}>  $items  `arriving_assets`: the files a dry run's push uploads before it writes
     * @param  array<int, string>  $refusedAssets  item index => why an asset it names cannot be pushed
     * @return array{written: bool, results: list<array<string, mixed>>}
     */
    public function push(DraftAuthor $author, array $items, bool $partial, bool $force, bool $dryRun, array $refusedAssets = [], bool $publish = false): array
    {
        $checked = [];
        $duplicates = self::duplicates($items);
        $this->publishing = $publish;
        $this->newTerms = [];
        $this->newEntries = self::newEntries($items);
        uasort($items, fn (array $a, array $b) => self::rank($a['path']) <=> self::rank($b['path']));

        foreach ($items as $i => $item) {
            $checked[$i] = match (true) {
                isset($refusedAssets[$i]) => self::refused($item['path'], 'asset_refused', $refusedAssets[$i]),
                isset($duplicates[$i]) => self::refused($item['path'], 'duplicate', __('core-web::sync.push_duplicate')),
                default => $this->one($author, $item, $force, true),
            };
        }

        $refused = collect($checked)->contains(fn (array $result) => $result['status'] === self::STATUS_REFUSED);

        if ($dryRun || ($refused && ! $partial)) {
            return ['written' => false, 'results' => array_values($checked)];
        }

        $results = [];
        $this->treeBases = $this->treeHashes($items);

        // A refusal the check could not foresee (the site changing in between) still leaves the files before it written.
        foreach ($items as $i => $item) {
            $results[] = in_array($checked[$i]['status'], [self::STATUS_REFUSED, self::STATUS_UNCHANGED], true)
                ? $checked[$i]
                : $this->one($author, $item, $force, false);
        }

        if ($publish) {
            $results = $this->publishAll($author, $results, $partial);
        }

        return ['written' => true, 'results' => $results];
    }

    /**
     * Publishes the drafts and applies the proposals a push wrote. Only a push written whole goes live
     * (or, with `partial`, the files that were written), so a late refusal leaves everything for an editor.
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    private function publishAll(DraftAuthor $author, array $results, bool $partial): array
    {
        $refused = collect($results)->contains(fn (array $result) => $result['status'] === self::STATUS_REFUSED);

        if ($refused && ! $partial) {
            return array_map(fn (array $result) => match (true) {
                in_array($result['status'], [self::STATUS_REFUSED, self::STATUS_UNCHANGED], true) => $result,
                $result['status'] === self::STATUS_NEW_TERM => self::refused($result['path'], 'not_created', __('core-web::sync.push_term_skipped')),
                default => [...$result, 'not_published' => __('core-web::sync.push_publish_skipped')],
            }, $results);
        }

        // New terms first, so an entry going live after them never names a term the site lacks.
        $results = array_map(fn (array $result) => $result['status'] === self::STATUS_NEW_TERM ? $this->createTerm($author, $result) : $result, $results);

        return array_map(fn (array $result) => match (true) {
            ! empty($result['unpublished_here']) => [...$result, 'not_published' => __('core-web::sync.push_publish_unpublished')],
            in_array($result['status'], [DraftWriter::STATUS_WORKING_COPY, DraftWriter::STATUS_DRAFT_ENTRY], true) && is_string($result['entry_id'] ?? null) => $this->publishEntry($author, $result),
            $result['status'] === self::STATUS_PROPOSAL && is_string($result['proposal_id'] ?? null) => $this->applyProposal($author, $result),
            default => $result,
        }, $results);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function publishEntry(DraftAuthor $author, array $result): array
    {
        try {
            $published = $this->writer->publish($author, $result['entry_id']);
        } catch (DraftRefused|ValidationException $e) {
            return [...$result, 'not_published' => $e->getMessage()];
        } catch (Throwable $e) {
            return self::failedToPublish($result, $e);
        }

        $entry = Entry::find($result['entry_id']);

        return [
            ...Arr::except($result, ['draft', 'preview_url']),
            'status' => self::STATUS_PUBLISHED,
            'url' => $published['url'] ?? null,
            'images_needed' => $published['images_needed'] ?? [],
            'base' => $entry ? self::hash('entries', $entry)['hash'] ?? null : null,
        ];
    }

    /**
     * A publish that broke on something unforeseen leaves this file for an editor, never the response or the rest of the push.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private static function failedToPublish(array $result, Throwable $e): array
    {
        Log::warning('core-web: content:push could not publish '.$result['path'], ['exception' => $e]);

        return [...$result, 'not_published' => __('core-web::sync.push_publish_failed')];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function applyProposal(DraftAuthor $author, array $result): array
    {
        $type = (string) self::typeOf($result['path']);

        try {
            $this->proposals->apply($result['proposal_id'], $author->user);
            [, $stored] = $this->target($type, $result['path']);
        } catch (DraftRefused|ValidationException $e) {
            return [...$result, 'not_published' => $e->getMessage()];
        } catch (Throwable $e) {
            return self::failedToPublish($result, $e);
        }

        return [...$result, 'status' => self::STATUS_APPLIED, 'base' => self::hash($type, $stored)['hash'] ?? null];
    }

    /**
     * @param  array{path: string, contents: string, base?: ?string, draft?: ?string, parent_id?: ?string}  $item
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
                'terms' => $this->term($author, $path, $data, $item, $force, $dryRun),
                'globals' => $this->proposal($author, $path, $type, $this->globalTarget($path), ['data' => $data], $item, $force, $dryRun),
                'collection-trees' => $this->pageTree($author, $path, $data, $item, $force, $dryRun),
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
     * @param  array{path: string, contents: string, base?: ?string, draft?: ?string, parent_id?: ?string}  $item
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

        $fields = Arr::except($data, [...EntryFields::INTERNAL, ...self::KEPT]);
        $fields = [...$fields, ...self::slugAndDate($path, $id)];
        $existing = Entry::find($id);
        // An entry the checkout keeps unpublished is never made live by --publish.
        $unpublished = ($data['published'] ?? true) === false ? ['unpublished_here' => true] : [];

        if ($existing === null) {
            $result = $this->writer->write($author, [
                'collection' => $collection,
                'id' => $id,
                'site' => $site,
                'parent_id' => $item['parent_id'] ?? null,
                'blueprint' => is_string($data['blueprint'] ?? null) ? $data['blueprint'] : null,
                'fields' => $fields,
                'dry_run' => $dryRun,
                'arriving_assets' => $item['arriving_assets'] ?? [],
            ], new StoredValues);

            return [...$this->drafted($path, $id, $result, $dryRun), ...$unpublished];
        }

        if ($existing->collectionHandle() !== $collection || $existing->locale() !== $site) {
            return self::refused($path, 'moved', __('core-web::sync.push_moved'));
        }

        if (is_string($data['blueprint'] ?? null) && $data['blueprint'] !== $existing->blueprint()?->handle()) {
            return self::refused($path, 'validation', __('core-web::sync.push_blueprint'));
        }

        $draftHash = $this->draftBase->hash($existing);
        $draft = $item['draft'] ?? null;
        // The site as this checkout's last push left it: its own draft, which the pulled base no longer describes.
        $ours = is_string($draft) && $draft !== '' && hash_equals($draftHash, $draft);

        if (! $force && ! $ours) {
            $this->assertBase($path, 'entries', $existing, $item['base'] ?? null);

            // The live file is unchanged, but a working copy made since (even the pusher's own in the CP) is not the pulled version.
            if ($existing->revisionsEnabled() && $existing->hasWorkingCopy()) {
                throw new DraftRefused('working_copy', __('core-web::sync.push_working_copy'));
            }
        }

        // A field the checkout's file no longer has was cleared there, as a CP save clears it.
        foreach (array_keys(Arr::except(EntryFields::stored($existing), self::KEPT)) as $handle) {
            if (! array_key_exists($handle, $fields)) {
                $fields[$handle] = null;
            }
        }

        $result = $this->writer->write($author, [
            'collection' => $collection,
            'entry_id' => $id,
            'site' => $site,
            'fields' => $fields,
            'base_hash' => $draftHash,
            'dry_run' => $dryRun,
            'arriving_assets' => $item['arriving_assets'] ?? [],
        ], new StoredValues);

        return [...$this->drafted($path, $id, $result, $dryRun), ...$unpublished];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function drafted(string $path, string $id, array $result, bool $dryRun): array
    {
        $written = self::written($path, $result);
        $entry = $dryRun ? null : Entry::find($id);

        return $entry === null ? $written : [...$written, 'draft' => $this->draftBase->hash($entry)];
    }

    /**
     * Items that are the same entry (by id) as an earlier one: the check runs before either is written, so it cannot see one undo the other.
     *
     * @param  list<array{path: string, contents: string}>  $items
     * @return array<int, true>
     */
    private static function duplicates(array $items): array
    {
        $seen = [];
        $duplicates = [];

        foreach ($items as $i => $item) {
            if (self::typeOf($item['path']) !== 'entries') {
                continue;
            }

            try {
                $id = self::parse($item['path'], $item['contents'])['id'] ?? null;
            } catch (ValidationException) {
                continue;
            }

            if (! is_string($id) || $id === '') {
                continue;
            }

            if (isset($seen[$id])) {
                $duplicates[$i] = true;
                $duplicates[$seen[$id]] = true;
            }

            $seen[$id] ??= $i;
        }

        return $duplicates;
    }

    /**
     * @param  array{data: array<mixed>}  $value
     * @param  array{path: string, contents: string, base?: ?string, draft?: ?string, parent_id?: ?string}  $item
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
            // The site already holds the file, so its version is the one the lock records.
            if ($e->error === 'unchanged') {
                return ['path' => $path, 'status' => self::STATUS_UNCHANGED, 'base' => self::hash($type, $stored)['hash'] ?? null];
            }

            throw $e;
        }

        return [
            'path' => $path,
            'status' => self::STATUS_PROPOSAL,
            'target' => $target->key(),
            'proposal_id' => $result['id'] ?? null,
            'changed' => array_keys((array) ($result['changed'] ?? [])),
            'cp_url' => $result['cp_url'] ?? null,
        ];
    }

    /**
     * A collection's page tree, which may hold entries this same push creates. While the push is checked
     * those do not exist yet, so the tree is checked whole with their ids allowed; as it is written, the
     * push's own placing of them is not taken for a change made on the site.
     *
     * @param  array<string, mixed>  $data
     * @param  array{path: string, contents: string, base?: ?string, draft?: ?string, parent_id?: ?string}  $item
     * @return array<string, mixed>
     */
    private function pageTree(DraftAuthor $author, string $path, array $data, array $item, bool $force, bool $dryRun): array
    {
        [$target, $stored] = $this->treeTarget($path);
        $tree = (array) ($data['tree'] ?? []);
        $holdsNew = array_intersect(self::entryIds($tree), array_keys($this->newEntries)) !== [];

        // The check runs before the new entries exist, so the tree is checked whole with their ids allowed.
        if ($dryRun && $holdsNew) {
            return $this->proposal($author, $path, 'collection-trees', [$target->expecting(array_keys($this->newEntries)), $stored], ['data' => $tree], $item, $force, true);
        }

        if ($dryRun || ! $holdsNew || $force || ! $this->placedOnly($path, $stored->tree(), $item['base'] ?? null)) {
            return $this->proposal($author, $path, 'collection-trees', [$target, $stored], ['data' => $tree], $item, $force, $dryRun);
        }

        $item['base'] = self::hash('collection-trees', $stored)['hash'] ?? $item['base'];

        try {
            return $this->proposal($author, $path, 'collection-trees', [$target, $stored], ['data' => $tree], $item, $force, $dryRun);
        } catch (DraftRefused|ValidationException $e) {
            // The entries are placed on the site by now, so its tree is the version the next push starts from.
            $refused = $e instanceof DraftRefused
                ? self::refused($path, $e->error, $e->getMessage(), $e->details)
                : self::refused($path, 'validation', $e->getMessage(), ['errors' => $e->errors()]);

            return [...$refused, 'base' => $item['base']];
        }
    }

    /**
     * Whether the tree differs from the one the write began with only by the entries this push placed,
     * and that one was the version the checkout pulled: anything else is a change made on the site.
     *
     * @param  array<mixed>  $current
     */
    private function placedOnly(string $path, array $current, mixed $base): bool
    {
        $start = $this->treeBases[$path] ?? null;

        return $start !== null
            && is_string($base)
            && hash_equals($start['hash'], $base)
            && self::normalised($this->withoutNewEntries($current)) === self::normalised($this->withoutNewEntries($start['tree']));
    }

    /**
     * The tree with each branch of an entry this push creates replaced by its children.
     *
     * @param  array<mixed>  $branches
     * @return list<mixed>
     */
    private function withoutNewEntries(array $branches): array
    {
        $out = [];

        foreach ($branches as $branch) {
            if (! is_array($branch)) {
                $out[] = $branch;

                continue;
            }

            $children = $this->withoutNewEntries((array) ($branch['children'] ?? []));

            if (is_string($branch['entry'] ?? null) && isset($this->newEntries[$branch['entry']])) {
                array_push($out, ...$children);

                continue;
            }

            $out[] = $children === [] ? Arr::except($branch, 'children') : [...$branch, 'children' => $children];
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $branches
     * @return list<mixed>
     */
    private static function normalised(array $branches): array
    {
        return array_values(array_map(function (mixed $branch): mixed {
            if (! is_array($branch)) {
                return $branch;
            }

            $children = self::normalised((array) ($branch['children'] ?? []));
            $branch = Arr::except($branch, 'children');
            ksort($branch);

            return $children === [] ? $branch : [...$branch, 'children' => $children];
        }, $branches));
    }

    /**
     * A term's values on its taxonomy's first site, which its file's top level holds. The file also holds
     * the other sites' values, which a push leaves as they are: a file that changes them is refused.
     * Statamic has no draft of a term, so a new one waits for a push that publishes.
     *
     * @param  array<string, mixed>  $data
     * @param  array{path: string, contents: string, base?: ?string, draft?: ?string, parent_id?: ?string}  $item
     * @return array<string, mixed>
     */
    private function term(DraftAuthor $author, string $path, array $data, array $item, bool $force, bool $dryRun): array
    {
        ['handle' => $taxonomy] = ContentPaths::parse('terms', $path);
        $slug = basename($path, '.yaml');
        $values = Arr::except($data, ['id', 'blueprint', 'localizations', 'updated_by', 'updated_at']);
        $file = self::termFile($taxonomy, $slug);

        if ($file === null) {
            return $this->newTerm($author, $path, $taxonomy, $slug, is_string($data['blueprint'] ?? null) ? $data['blueprint'] : null, $values, array_key_exists('localizations', $data) ? ['localizations'] : [], $dryRun);
        }

        $stored = $file->fileData();

        if ((array) ($data['localizations'] ?? []) != (array) ($stored['localizations'] ?? [])) {
            return self::refused($path, 'localized_term', __('core-web::sync.push_term_localizations'));
        }

        if (array_key_exists('blueprint', $data) && $data['blueprint'] !== ($stored['blueprint'] ?? null)) {
            return self::refused($path, 'validation', __('core-web::sync.push_term_blueprint'));
        }

        return $this->proposal($author, $path, 'terms', [TermTarget::make($taxonomy, $slug, self::termSite($taxonomy)), $file], ['data' => $values], $item, $force, $dryRun);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $ignored  what the file holds that a new term does not take
     * @return array<string, mixed>
     */
    private function newTerm(DraftAuthor $author, string $path, string $taxonomy, string $slug, ?string $blueprint, array $values, array $ignored, bool $dryRun): array
    {
        $found = Taxonomy::find($taxonomy);

        if ($found === null) {
            throw new InvalidArgumentException(__('core-web::sync.push_not_found'));
        }

        if (Str::slug($slug) !== $slug) {
            return self::refused($path, 'validation', __('core-web::sync.push_term_slug', ['slug' => Str::slug($slug)]));
        }

        if (! Gate::forUser($author->user)->allows('create', [TermContract::class, $found, Site::get(self::termSite($taxonomy))])) {
            throw DraftRefused::notPermitted($author->name);
        }

        $termBlueprint = $found->termBlueprint($blueprint);

        if ($termBlueprint === null) {
            throw ValidationException::withMessages(['blueprint' => __('core-web::sync.push_term_new_blueprint')]);
        }

        $known = $termBlueprint->fields()->except('slug')->all()->keys()->map(fn ($handle) => (string) $handle)->all();
        $ignored = [...$ignored, ...array_keys(array_diff_key($values, array_flip($known)))];
        $values = array_intersect_key($values, array_flip($known));
        $fields = $termBlueprint->fields()->except('slug')->addValues($values)->preProcess();
        $all = $fields->values()->all();
        $hidden = FieldConditions::omitted($fields, $all);

        // A value its field's conditions hide is left out, as the CP's form leaves it out of a save.
        if ($hidden !== []) {
            $ignored = [...$ignored, ...array_filter($hidden, fn (string $at) => ! blank(Arr::get($values, $at)))];
            Arr::forget($all, $hidden);
            $fields = $fields->addValues($all)->setFilled(array_values(array_diff(array_keys($values), $hidden)));
        }

        $validator = $fields->validator()->validator();

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->messages());
        }

        if (! $this->publishing) {
            return self::refused($path, 'term_needs_publish', __('core-web::sync.push_term_needs_publish'));
        }

        if (! $dryRun) {
            $this->newTerms[$path] = [
                'taxonomy' => $taxonomy,
                'slug' => $slug,
                'blueprint' => $blueprint,
                'values' => array_filter($fields->process()->values()->only(array_keys($values))->all(), fn (mixed $v) => $v !== null),
            ];
        }

        return ['path' => $path, 'status' => self::STATUS_NEW_TERM, 'ignored_fields' => array_values(array_unique($ignored))];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function createTerm(DraftAuthor $author, array $result): array
    {
        $new = $this->newTerms[$result['path']] ?? null;

        if ($new === null || self::termFile($new['taxonomy'], $new['slug']) !== null) {
            return self::refused($result['path'], 'not_created', __('core-web::sync.push_term_skipped'));
        }

        try {
            $term = Term::make()->taxonomy($new['taxonomy'])->slug($new['slug']);

            if ($new['blueprint'] !== null) {
                $term->blueprint($new['blueprint']);
            }

            $term->dataForLocale(self::termSite($new['taxonomy']), [...$new['values'], 'updated_by' => $author->user->id(), 'updated_at' => now()->timestamp]);
            $term->save();
        } catch (Throwable $e) {
            Log::warning('core-web: content:push could not create '.$result['path'], ['exception' => $e]);

            return self::refused($result['path'], 'not_created', __('core-web::sync.push_publish_failed'));
        }

        return [...$result, 'status' => self::STATUS_PUBLISHED, 'base' => self::hash('terms', $term)['hash'] ?? null];
    }

    /** The site a term file's top level holds: its taxonomy's first. */
    private static function termSite(string $taxonomy): string
    {
        return (string) (Taxonomy::find($taxonomy)?->sites()->first() ?? Site::default()->handle());
    }

    /** A term's file object (every site's values), or null when the site has no such term. */
    private static function termFile(string $taxonomy, string $slug): ?object
    {
        $term = Term::find("{$taxonomy}::{$slug}");

        return $term === null ? null : (method_exists($term, 'term') ? $term->term() : $term);
    }

    /**
     * The proposal target of a pushable file and what the site stores for it.
     *
     * @return array{0: ProposalTarget, 1: object}
     */
    private function target(string $type, string $path): array
    {
        return match ($type) {
            'globals' => $this->globalTarget($path),
            'collection-trees' => $this->treeTarget($path),
            'nav-trees' => $this->navTarget($path),
            'terms' => [TermTarget::make(ContentPaths::parse('terms', $path)['handle'], basename($path, '.yaml'), self::termSite(ContentPaths::parse('terms', $path)['handle'])), self::found(self::termFile(ContentPaths::parse('terms', $path)['handle'], basename($path, '.yaml')))],
        };
    }

    /** @return array{0: ProposalTarget, 1: object} */
    private function treeTarget(string $path): array
    {
        ['handle' => $handle, 'site' => $site] = ContentPaths::parse('collection-trees', $path);
        $target = CollectionTreeTarget::make($handle, (string) $site);

        return [$target, self::found(Collection::find($handle)?->structure()?->in((string) $site))];
    }

    /**
     * @param  array<int, array{path: string, contents: string}>  $items
     * @return array<string, array{hash: string, tree: array<mixed>}>
     */
    private function treeHashes(array $items): array
    {
        $hashes = [];

        foreach ($items as $item) {
            if (self::typeOf($item['path']) !== 'collection-trees') {
                continue;
            }

            try {
                [, $stored] = $this->treeTarget($item['path']);
            } catch (InvalidArgumentException) {
                continue;
            }

            if (($hash = self::hash('collection-trees', $stored)) !== null) {
                $hashes[$item['path']] = ['hash' => $hash['hash'], 'tree' => $stored->tree()];
            }
        }

        return $hashes;
    }

    /**
     * @param  array<int, array{path: string, contents: string}>  $items
     * @return array<string, true>
     */
    private static function newEntries(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if (self::typeOf($item['path']) !== 'entries') {
                continue;
            }

            try {
                $id = self::parse($item['path'], $item['contents'])['id'] ?? null;
            } catch (ValidationException) {
                continue;
            }

            if (is_string($id) && $id !== '' && Entry::find($id) === null) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * @param  array<mixed>  $branches
     * @return list<string>
     */
    private static function entryIds(array $branches): array
    {
        $ids = [];

        foreach ($branches as $branch) {
            if (! is_array($branch)) {
                continue;
            }

            if (is_string($branch['entry'] ?? null)) {
                $ids[] = $branch['entry'];
            }

            array_push($ids, ...self::entryIds((array) ($branch['children'] ?? [])));
        }

        return $ids;
    }

    private static function rank(string $path): int
    {
        $type = self::typeOf($path);

        return $type === null ? count(self::TYPES) : (int) array_search($type, self::TYPES, true);
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
