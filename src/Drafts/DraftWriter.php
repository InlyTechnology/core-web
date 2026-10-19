<?php

namespace Inly\CoreWeb\Drafts;

use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Fields;
use Statamic\Support\Str;
use Throwable;

/**
 * Drafts of a site's entries, written the way an editor's CP save writes
 * them and published only when someone asks for it (publish()): a new entry
 * is unpublished (a new entry of a structured collection in its parent's
 * place in the tree, on its parent's site; Statamic keeps working copies of
 * published entries only), a published one gets a working copy and its live
 * entry is left as it is. Each is saved as its author's CP user, who must be
 * allowed to make the change, and marked with the author's provenance
 * (DraftProvenance) and revision message. Sources (Core AI, a push) build
 * the author; the collections they may draft are the site's
 * (DraftableCollections).
 *
 * Statamic keeps one working copy per entry, with nothing to merge into, so
 * a draft never touches one someone else holds (another person's working
 * copy, or an unpublished entry someone else saved last). The author's own,
 * their CP edits included, it rebuilds from the live entry and the fields
 * sent, the way the request states the whole draft: a field the copy
 * changed must be sent again, so a stale draft cannot silently drop a change.
 */
class DraftWriter
{
    public const STATUS_WORKING_COPY = 'working_copy';

    public const STATUS_DRAFT_ENTRY = 'draft_entry';

    public const STATUS_PUBLISHED = 'published';

    /** Whether this write only checks: every refusal and validation, nothing saved. */
    private bool $dryRun = false;

    /** The normaliser of this write: the site's, or the one its source passed. */
    private DraftValueNormaliser $normaliser;

    /** Statamic's asset file rules, which look an asset up by id while a draft holds paths. */
    public const ASSET_FILE_RULES = ['dimensions', 'image', 'mimes', 'mimetypes', 'max_filesize', 'min_filesize'];

    public function __construct(
        private DraftValueNormaliser $values,
        private DraftBase $base,
        private DraftPreview $preview,
    ) {}

    /**
     * @param  array{collection: string, entry_id?: ?string, site?: ?string, parent_id?: ?string, blueprint?: ?string, dry_run?: bool, fields: array<string, mixed>, base_hash?: ?string}  $request
     * @return array<string, mixed>
     *
     * @throws DraftRefused|ValidationException
     */
    public function write(DraftAuthor $author, array $request, ?DraftValueNormaliser $values = null): array
    {
        $this->normaliser = $values ?? $this->values;
        $this->dryRun = (bool) ($request['dry_run'] ?? false);

        if (! DraftableCollections::writable((string) $request['collection'])) {
            throw ValidationException::withMessages(['collection' => 'Drafts are written for '.implode(', ', DraftableCollections::handles()).' only; other content is edited in the CP.']);
        }

        $collection = Collection::findByHandle($request['collection']);
        $parent = $this->parent($collection, $request);
        $site = $parent?->locale() ?? $request['site'] ?? Site::default()->handle();

        if ($parent !== null && filled($request['site'] ?? null) && $request['site'] !== $site) {
            throw ValidationException::withMessages(['site' => "The parent entry is on the site `{$site}`, not `{$request['site']}`."]);
        }

        if (! $collection || ! $collection->sites()->contains($site)) {
            throw ValidationException::withMessages(['site' => "The {$request['collection']} collection is not on the site `{$site}`."]);
        }

        // Local development keeps revisions off as a rule; a draft needs them on.
        if (! $collection->revisionsEnabled()) {
            throw DraftRefused::revisionsOff();
        }

        $entry = null;

        if (filled($request['entry_id'] ?? null)) {
            $entry = Entry::find($request['entry_id']);

            abort_unless($entry && $entry->collectionHandle() === $collection->handle(), 404, 'No such entry.');

            if ($entry->locale() !== $site) {
                throw ValidationException::withMessages(['site' => "The entry is on the site `{$entry->locale()}`, not `{$site}`."]);
            }
        }

        $gate = Gate::forUser($author->user);
        $permitted = $entry === null
            ? $gate->allows('store', [EntryContract::class, $collection, Site::get($site)])
            : $gate->allows('edit', $entry);

        if (! $permitted) {
            throw DraftRefused::notPermitted($author->name);
        }

        if ($entry !== null) {
            $this->assertBase($entry, $request['base_hash'] ?? null);
        }

        $by = $author;
        $fields = (array) $request['fields'];

        if ($entry === null) {
            return $this->create($collection, $site, $fields, $by, $this->blueprint($collection, $request['blueprint'] ?? null), $parent);
        }

        if (filled($request['parent_id'] ?? null) || filled($request['blueprint'] ?? null)) {
            throw ValidationException::withMessages([
                filled($request['parent_id'] ?? null) ? 'parent_id' : 'blueprint' => 'A draft keeps an existing entry where it is and as the kind it is; move it or change its type in the CP.',
            ]);
        }

        if ($entry->published() || ($entry->revisionsEnabled() && $entry->hasWorkingCopy())) {
            return $this->workingCopy($entry, $fields, $by);
        }

        return $this->draftEntry($entry, $fields, $by);
    }

    /**
     * That an update was written against the entry as it is now: its
     * `base_hash` is the hash its source read (DraftBase), which the working
     * copy's fields are part of, so a draft never undoes an edit made after
     * the source read the entry. Every update names one.
     *
     * @throws DraftRefused|ValidationException
     */
    private function assertBase(EntryContract $entry, mixed $base): void
    {
        if (blank($base) || ! is_string($base)) {
            throw ValidationException::withMessages(['base_hash' => __('core-web::drafts.base_required')]);
        }

        $current = $this->base->hash($entry);

        if (! hash_equals($current, $base)) {
            throw DraftRefused::baseChanged($current);
        }
    }

    /**
     * The draft of an entry as a source reads it to update it: the working
     * copy's fields (or the draft entry's, or the live entry's when it has
     * neither), who holds it and what it changes.
     *
     * @return array<string, mixed>|null
     */
    public function read(string $id): ?array
    {
        $entry = Entry::find($id);

        if (! $entry || ! DraftableCollections::writable($entry->collectionHandle())) {
            return null;
        }

        $holder = $this->holder($entry);
        $source = $holder['status'] === self::STATUS_WORKING_COPY ? $entry->fromWorkingCopy() : $entry;

        return [
            'entry_id' => (string) $entry->id(),
            'collection' => $entry->collectionHandle(),
            'site' => $entry->locale(),
            'status' => $holder['status'],
            'held_by' => DraftPerson::of($holder['user']),
            'since' => $holder['since'] ? self::iso($holder['since']) : null,
            'message' => $holder['message'],
            'via' => $holder['via'],
            'fields' => EntryFields::of($source),
            'changed_fields' => $holder['status'] === self::STATUS_WORKING_COPY ? self::changes($entry, $source) : [],
            'cp_url' => $entry->editUrl(),
            'preview_url' => $holder['status'] === self::STATUS_PUBLISHED ? null : $this->preview->link($entry),
        ];
    }

    /**
     * Publishes the author's draft as an editor's Publish in the CP does: the
     * working copy becomes the live entry, or the unpublished entry goes live,
     * with a publish revision carrying the author's provenance and message. A
     * draft someone else holds is theirs to publish. A required image the
     * draft lacks inside a block does not stop it (the block renders nothing
     * until an editor adds one) and is named in the answer; a field the
     * collection is published with (`published_with`) does.
     *
     * @return array<string, mixed>
     *
     * @throws DraftRefused|ValidationException
     */
    public function publish(DraftAuthor $author, string $id): array
    {
        $entry = Entry::find($id);

        abort_unless($entry && DraftableCollections::writable($entry->collectionHandle()), 404, 'No such entry.');

        if (! $entry->revisionsEnabled()) {
            throw DraftRefused::revisionsOff();
        }

        $user = $author->user;

        if (! Gate::forUser($user)->allows('publish', $entry)) {
            throw DraftRefused::notPermittedToPublish($author->name);
        }

        $holder = $this->holder($entry);

        if ($holder['status'] === self::STATUS_PUBLISHED) {
            throw DraftRefused::nothingToPublish();
        }

        $this->guard($entry, $user);

        $draft = $holder['status'] === self::STATUS_WORKING_COPY ? $entry->fromWorkingCopy() : clone $entry;
        $data = EntryFields::stored($draft);

        foreach (DraftableCollections::publishedWith($entry->collectionHandle()) as $handle) {
            if (blank($data[$handle] ?? null)) {
                throw DraftRefused::imageRequired($handle);
            }
        }

        [$fields, $hidden] = self::visible($draft->blueprint(), $data);
        $imagesNeeded = self::outside(RequiredAssets::missing($draft->blueprint()->fields(), $data), $hidden);
        $this->validate($fields, $imagesNeeded, $entry->collection(), $entry->locale(), $entry);

        // Statamic's publishWorkingCopy(), with the provenance on the publish revision.
        $draft->published(true)->updateLastModified($user);

        if ($draft->save() === false) {
            throw DraftRefused::notSaved();
        }

        $revision = $draft->makeRevision()->user($user)->message($author->message)->action('publish');
        DraftProvenance::stamp($revision, $author->provenance)->save();
        $draft->deleteWorkingCopy();
        $draft->blueprint()->setParent($draft);

        return [
            'entry_id' => (string) $draft->id(),
            'status' => self::STATUS_PUBLISHED,
            'url' => $draft->absoluteUrl(),
            'cp_url' => $draft->editUrl(),
            'images_needed' => $imagesNeeded,
        ];
    }

    /** @return array<string, mixed> */
    private function create(CollectionContract $collection, string $site, array $fields, DraftAuthor $by, Blueprint $blueprint, ?EntryContract $parent): array
    {
        $normalised = $this->normaliser->normalise($blueprint, $collection->handle(), $site, Arr::except($fields, ['slug', 'date']), []);
        $values = $this->process($blueprint, $normalised['data'], $normalised, $collection, $site, null);

        $entry = Entry::make()
            ->collection($collection)
            ->blueprint($blueprint->handle())
            ->locale($site)
            ->slug($this->slug((string) ($fields['slug'] ?? $values['title'] ?? ''), $collection, $site, null))
            ->data(Arr::whereNotNull($values))
            ->published(false);

        if ($collection->dated()) {
            $entry->date(self::onTheDay($blueprint, $this->date($fields['date'] ?? null) ?? Carbon::now()));
        }

        $changed = [...array_keys(Arr::whereNotNull($values)), 'slug', ...($collection->dated() ? ['date'] : [])];

        if ($this->dryRun) {
            return $this->checked(self::STATUS_DRAFT_ENTRY, $changed, $normalised);
        }

        if ($parent !== null) {
            $this->placeUnder($collection, $site, $entry, $parent);
        }

        // Entry::store() as the CP does it, with the provenance on the revision.
        $entry->updateLastModified($by->user)->save();

        if ($entry->revisionsEnabled()) {
            DraftProvenance::stamp($entry->makeRevision()->user($by->user)->message($by->message), $by->provenance)->save();
        }

        return $this->result($entry, self::STATUS_DRAFT_ENTRY, $changed, $normalised);
    }

    /**
     * The page a new entry of a structured collection goes under, which also
     * decides its site; none for any other draft.
     *
     * @param  array<string, mixed>  $request
     *
     * @throws ValidationException
     */
    private function parent(?CollectionContract $collection, array $request): ?EntryContract
    {
        if ($collection === null || ! DraftableCollections::structured($collection->handle()) || filled($request['entry_id'] ?? null)) {
            return null;
        }

        if (blank($request['parent_id'] ?? null)) {
            throw ValidationException::withMessages(['parent_id' => 'A new entry of this collection needs the entry it goes under: `parent_id`, the Statamic id of an entry in its tree (the site\'s root entry for the top level).']);
        }

        $parent = Entry::find($request['parent_id']);

        if (! $parent || $parent->collectionHandle() !== $collection->handle()) {
            throw ValidationException::withMessages(['parent_id' => "No entry `{$request['parent_id']}` to put the entry under."]);
        }

        if ($collection->structure()?->in($parent->locale())?->find($parent->id()) === null) {
            throw ValidationException::withMessages(['parent_id' => 'The parent entry is not in its site\'s tree.']);
        }

        return $parent;
    }

    /**
     * A new entry's blueprint: the one asked for when the site allows drafts
     * of it, else the first allowed; with none configured, any of the
     * collection's (its default when none is asked for).
     *
     * @throws ValidationException
     */
    private function blueprint(CollectionContract $collection, ?string $handle): Blueprint
    {
        $allowed = DraftableCollections::blueprints($collection->handle());

        if ($allowed === []) {
            $allowed = $collection->entryBlueprints()->map(fn (Blueprint $blueprint) => $blueprint->handle())->values()->all();
            $handle = filled($handle) ? $handle : $collection->entryBlueprint()?->handle();
        }

        $handle = filled($handle) ? $handle : ($allowed[0] ?? null);

        if (! in_array($handle, $allowed, true) || ($blueprint = $collection->entryBlueprint($handle)) === null) {
            throw ValidationException::withMessages(['blueprint' => "A draft of {$collection->handle()} takes the blueprint ".implode(' or ', $allowed).'.']);
        }

        return $blueprint;
    }

    /**
     * Puts a new entry last among its parent's children in its site's tree as
     * it is saved, as the CP's store does; an entry under the root goes on
     * the top level, since the root of a tree takes no children.
     */
    private function placeUnder(CollectionContract $collection, string $site, EntryContract $entry, EntryContract $parent): void
    {
        $tree = $collection->structure()->in($site);
        $under = $tree->find($parent->id())?->isRoot() ? null : $parent->id();

        $entry->afterSave(fn (EntryContract $saved) => $tree->appendTo($under, $saved)->save());
    }

    /** @return array<string, mixed> */
    private function draftEntry(EntryContract $entry, array $fields, DraftAuthor $by): array
    {
        $this->guard($entry, $by->user);

        $before = clone $entry;
        $collection = $entry->collection();
        $blueprint = $entry->blueprint();
        $current = EntryFields::stored($entry);
        $normalised = $this->normaliser->normalise($blueprint, $collection->handle(), $entry->locale(), Arr::except($fields, ['slug', 'date']), $current);
        $values = $this->process($blueprint, [...$current, ...$normalised['data']], $normalised, $collection, $entry->locale(), $entry);

        // A check works on a copy: the entry found is the one the Stache holds for the rest of the request.
        if ($this->dryRun) {
            $copy = (clone $entry)->merge($values);
            $this->applySlugAndDate($copy, $fields);

            return $this->checked(self::STATUS_DRAFT_ENTRY, self::changes($before, $copy), $normalised);
        }

        $entry->merge($values);
        $this->applySlugAndDate($entry, $fields);
        $entry->updateLastModified($by->user)->save();

        if ($entry->revisionsEnabled()) {
            DraftProvenance::stamp($entry->makeRevision()->user($by->user)->message($by->message), $by->provenance)->save();
        }

        return $this->result($entry, self::STATUS_DRAFT_ENTRY, self::changes($before, $entry), $normalised);
    }

    /** @return array<string, mixed> */
    private function workingCopy(EntryContract $live, array $fields, DraftAuthor $by): array
    {
        $this->guard($live, $by->user);

        $before = $live->fromWorkingCopy();

        if ($live->hasWorkingCopy()) {
            $unstated = array_values(array_diff(self::changes($live, $before), array_keys($fields)));

            if ($unstated !== []) {
                throw DraftRefused::restate($unstated);
            }
        }

        $collection = $live->collection();
        $blueprint = $live->blueprint();
        $liveData = EntryFields::stored($live);
        // What the draft leaves alone in a set it restates (an image) is the copy's.
        $normalised = $this->normaliser->normalise($blueprint, $collection->handle(), $live->locale(), Arr::except($fields, ['slug', 'date']), EntryFields::stored($before));
        $values = $this->process($blueprint, [...$liveData, ...$normalised['data']], $normalised, $collection, $live->locale(), $live);

        $draft = (clone $live)->data([...$liveData, ...$values]);
        $this->applySlugAndDate($draft, $fields);

        if ($this->dryRun) {
            return $this->checked(self::STATUS_WORKING_COPY, self::changes($before, $draft), $normalised);
        }

        DraftProvenance::stamp($draft->makeWorkingCopy()->user($by->user)->message($by->message), $by->provenance)->save();

        return $this->result($live, self::STATUS_WORKING_COPY, self::changes($before, $live->fromWorkingCopy()), $normalised);
    }

    /**
     * Refuses a draft someone other than the author holds: their working
     * copy, or an unpublished entry they saved last.
     *
     * @throws DraftRefused
     */
    private function guard(EntryContract $entry, UserContract $user): void
    {
        $holder = $this->holder($entry);

        if ($holder['user'] !== null && (string) $holder['user']->id() === (string) $user->id()) {
            return;
        }

        if ($holder['status'] !== self::STATUS_PUBLISHED) {
            throw DraftRefused::heldBy($holder['user'], $holder['since'], workingCopy: $holder['status'] === self::STATUS_WORKING_COPY);
        }
    }

    /**
     * Whose draft the entry is: the working copy's user, an unpublished
     * entry's last editor, or nobody's for a published one without a copy.
     *
     * @return array{status: string, user: ?UserContract, since: ?CarbonInterface, message: ?string, via: ?string}
     */
    private function holder(EntryContract $entry): array
    {
        if ($entry->revisionsEnabled() && $copy = $entry->workingCopy()) {
            return ['status' => self::STATUS_WORKING_COPY, 'user' => $copy->user() ?: null, 'since' => $copy->date(), 'message' => $copy->message() ?: null, 'via' => DraftProvenance::via($copy)];
        }

        if (! $entry->published()) {
            $user = $entry->lastModifiedBy();
            $revision = $entry->revisionsEnabled() ? $entry->latestRevision() : null;
            // The draft's message and provenance are the person's last save's only when it was a draft's.
            $ours = $revision !== null && $user !== null && (string) $revision->user()?->id() === (string) $user->id();
            $draftRevision = $ours ? $revision : null;

            return [
                'status' => self::STATUS_DRAFT_ENTRY,
                'user' => $user,
                'since' => $entry->lastModified(),
                'message' => $draftRevision?->message() ?: null,
                'via' => DraftProvenance::via($draftRevision),
            ];
        }

        return ['status' => self::STATUS_PUBLISHED, 'user' => null, 'since' => null, 'message' => null, 'via' => null];
    }

    /**
     * Validated against the blueprint as the CP validates a save (the slug
     * and date are the writer's own), and processed as the CP stores it.
     * Only the fields the draft sent are kept; an image a block requires but
     * a draft cannot add is the editor's to fill, so its required rule is
     * waived (the block renders nothing without its image until then). A
     * value its field's conditions hide is left out, as the CP's form leaves
     * it out of a save, and named among the ignored fields when the draft
     * sent it.
     *
     * @param  array<string, mixed>  $all  the entry as it will be, for validation
     * @param  array{data: array<string, mixed>, ignored: list<string>, images_needed: list<string>, unchanged: list<string>}  $normalised  the draft's values, as the normaliser made them
     * @return array<string, mixed>
     */
    private function process(Blueprint $blueprint, array $all, array &$normalised, CollectionContract $collection, string $site, ?EntryContract $entry): array
    {
        $sent = $normalised['data'];
        [$fields, $hidden] = self::visible($blueprint, $all);

        // A field sent exactly as stored is the page's own, hidden values and all.
        $touched = array_values(array_filter($hidden, fn (string $path) => ! in_array(Str::before($path, '.'), $normalised['unchanged'], true)));
        $sentHidden = array_filter($touched, fn (string $path) => ! blank(Arr::get($sent, $path)));
        $normalised['ignored'] = array_values(array_unique([...$normalised['ignored'], ...$sentHidden]));
        $normalised['images_needed'] = self::outside($normalised['images_needed'], $hidden);

        $this->validate($fields, $normalised['images_needed'], $collection, $site, $entry);

        $processed = Arr::only($fields->process()->values()->all(), array_keys($sent));

        // Processing puts an empty list back for a hidden grid or set; a Bard that drops empty nodes renumbers what follows, so only blanks go.
        Arr::forget($processed, array_filter($touched, fn (string $path) => blank(Arr::get($processed, $path))));

        // A field sent as stored stays byte for byte, without the defaults processing would add.
        return [...$processed, ...Arr::only($sent, $normalised['unchanged'])];
    }

    /**
     * The blueprint's fields holding the entry as the CP's form would send
     * it on a save: without the values their conditions hide.
     *
     * @param  array<string, mixed>  $all
     * @return array{0: Fields, 1: list<string>} the fields, and the paths of the values left out
     */
    private static function visible(Blueprint $blueprint, array $all): array
    {
        $fields = $blueprint->fields()->except('slug', 'date')->addValues($all)->preProcess();
        $values = $fields->values()->all();
        $hidden = FieldConditions::omitted($fields, $values);

        if ($hidden === []) {
            return [$fields, []];
        }

        Arr::forget($values, $hidden);

        // Validated as the fields the entry holds, as before any were hidden.
        return [$fields->addValues($values)->setFilled(array_values(array_diff(array_keys($all), $hidden))), $hidden];
    }

    /**
     * The paths not at or under any of the hidden ones.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $hidden
     * @return list<string>
     */
    private static function outside(array $paths, array $hidden): array
    {
        return array_values(array_filter($paths, fn (string $path) => ! collect($hidden)->contains(fn (string $at) => $path === $at || str_starts_with($path, "{$at}."))));
    }

    /**
     * @param  list<string>  $imagesNeeded  paths whose required rule is waived
     *
     * @throws ValidationException keyed `fields.{path}`
     */
    private function validate(Fields $fields, array $imagesNeeded, CollectionContract $collection, string $site, ?EntryContract $entry): void
    {
        $validator = $fields->validator()->withReplacements(array_filter([
            'id' => $entry?->id(),
            'collection' => $collection->handle(),
            'site' => $site,
        ]))->validator();

        // Statamic's asset file rules look an asset up by id, and a draft holds paths: the normaliser checks the images a draft adds, and the rest are the editors'.
        $rules = array_map(fn (array $rules): array => array_values(array_filter($rules, fn (mixed $rule): bool => ! self::assetFileRule($rule))), $validator->getRules());
        foreach ($imagesNeeded as $path) {
            if (isset($rules[$path])) {
                $rules[$path] = array_values(array_filter($rules[$path], fn ($rule) => ! is_string($rule) || ! str_starts_with($rule, 'required')));
            }
        }
        $validator->setRules($rules);

        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())->mapWithKeys(fn (array $messages, string $key) => ["fields.{$key}" => $messages])->all());
        }
    }

    private static function assetFileRule(mixed $rule): bool
    {
        if (is_object($rule)) {
            return str_starts_with($rule::class, 'Statamic\\Fieldtypes\\Assets\\');
        }

        return is_string($rule) && in_array(Str::before($rule, ':'), self::ASSET_FILE_RULES, true);
    }

    private function applySlugAndDate(EntryContract $entry, array $fields): void
    {
        if (filled($fields['slug'] ?? null)) {
            $entry->slug($this->slug((string) $fields['slug'], $entry->collection(), $entry->locale(), (string) $entry->id()));
        }

        if (($date = $this->date($fields['date'] ?? null)) !== null) {
            $entry->date(self::onTheDay($entry->blueprint(), $date));
        }
    }

    /** A date without its time where the blueprint's date takes none, as the CP saves it (and names the file). */
    private static function onTheDay(Blueprint $blueprint, CarbonInterface $date): CarbonInterface
    {
        return $blueprint->field('date')?->fieldtype()->timeEnabled() ? $date : $date->copy()->startOfDay();
    }

    /**
     * The slug as the CP makes it from the title in the site's language
     * (Swedish: å and ä become a, ö becomes o), with -2, -3 … after it when
     * another entry of the collection on the site already has it.
     */
    private function slug(string $from, CollectionContract $collection, string $site, ?string $id): string
    {
        $base = Str::slug($from, '-', Site::get($site)?->lang() ?? 'en');

        if ($base === '') {
            throw ValidationException::withMessages(['fields.slug' => 'A slug needs a title or slug with letters or digits in it.']);
        }

        $slug = $base;
        for ($n = 2; $this->slugTaken($slug, $collection, $site, $id); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    private function slugTaken(string $slug, CollectionContract $collection, string $site, ?string $id): bool
    {
        return Entry::query()
            ->where('collection', $collection->handle())
            ->where('site', $site)
            ->where('slug', $slug)
            ->get()
            ->contains(fn (EntryContract $other) => (string) $other->id() !== $id);
    }

    private function date(mixed $value): ?CarbonInterface
    {
        if (blank($value)) {
            return null;
        }

        foreach (['Y-m-d H:i', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, (string) $value, config('app.timezone'));

                if ($date !== false && $date->format($format) === (string) $value) {
                    return $date;
                }
            } catch (Throwable) {
                //
            }
        }

        throw ValidationException::withMessages(['fields.date' => 'A date is `Y-m-d` or `Y-m-d H:i`.']);
    }

    /**
     * What a write would do, for a dry run: nothing is saved, so there is no
     * entry, CP page or preview to link.
     *
     * @param  list<string>  $changed
     * @param  array{ignored: list<string>, images_needed: list<string>, unchanged: list<string>}  $normalised
     * @return array<string, mixed>
     */
    private function checked(string $status, array $changed, array $normalised): array
    {
        return [
            'dry_run' => true,
            'status' => $status,
            'changed_fields' => array_values(array_unique($changed)),
            'ignored_fields' => $normalised['ignored'],
            'images_needed' => $normalised['images_needed'],
        ];
    }

    /**
     * @param  list<string>  $changed
     * @param  array{ignored: list<string>, images_needed: list<string>, unchanged: list<string>}  $normalised
     * @return array<string, mixed>
     */
    private function result(EntryContract $entry, string $status, array $changed, array $normalised): array
    {
        return [
            'entry_id' => (string) $entry->id(),
            'status' => $status,
            'cp_url' => $entry->editUrl(),
            'preview_url' => $this->preview->link($entry),
            'changed_fields' => array_values(array_unique($changed)),
            'ignored_fields' => $normalised['ignored'],
            'images_needed' => $normalised['images_needed'],
        ];
    }

    /**
     * The fields one version of an entry differs from another in: its data,
     * slug and date.
     *
     * @return list<string>
     */
    private static function changes(EntryContract $from, EntryContract $to): array
    {
        $a = EntryFields::of($from);
        $b = EntryFields::of($to);

        return collect(array_keys($a + $b))
            ->filter(fn (string $key) => ($a[$key] ?? null) != ($b[$key] ?? null))
            ->values()
            ->all();
    }

    private static function iso(CarbonInterface $date): string
    {
        return $date->copy()->utc()->toIso8601String();
    }
}
