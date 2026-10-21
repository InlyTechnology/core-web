<?php

namespace Inly\CoreWeb\ContentSync;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Statamic\Facades\Collection;
use Statamic\Facades\YAML;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Sends what changed in content/ since the last pull to the live site, as
 * drafts and proposals an editor publishes or applies there (ContentPush),
 * with the files uploaded here that they name (AssetPush). Nothing it sends
 * goes live by itself unless `--publish` asks and the site allows it, and
 * content.lock records only what was pushed: the live site is unchanged
 * until an editor acts, and the next pull brings it.
 */
class ContentPushCommand extends Command
{
    use SignsInToRemoteSite;

    protected $signature = 'content:push
        {paths?* : Only these files: each a file or folder under content/ (content/ prefix optional) or a * pattern}
        {--only= : Types to push, comma separated, each optionally narrowed to a handle (entries:pages,terms:tags,globals,collection-trees,nav-trees:main); every pushable type when omitted}
        {--site= : Only this site\'s content}
        {--dry-run : Check every file on the site and write nothing}
        {--partial : Write the files that pass even when others are refused}
        {--force : Push over a change made on the site since the last pull (never over someone else\'s draft)}
        {--publish : Publish the drafts and apply the proposals once all are written, within your permissions (core-web.content_sync.push_publish on the site)}
        {--staged : Only files whose contents are as git\'s index has them (git add)}
        {--committed : Only files whose contents are as the last commit has them}
        {--json : Print one JSON document instead of text, and never ask anything}';

    protected $description = 'Push content/ changes to the remote site (core-web.content_sync.remote_url) as drafts and proposals';

    /** @var array{files: list<string>, written: bool, results: list<array<string, mixed>>} what the run sent and the site answered, for --json */
    private array $outcome = ['files' => [], 'written' => false, 'results' => []];

    public function handle(LocalContent $local): int
    {
        if (! $this->option('json')) {
            return $this->push($local);
        }

        // An agent reads one document: the text goes into it as messages, and a login it would ask for is an error instead.
        $this->input->setInteractive(false);
        $terminal = $this->output;
        $buffer = new BufferedOutput;
        $this->output = new OutputStyle($this->input, $buffer);

        try {
            $exit = $this->push($local);
        } finally {
            $this->output = $terminal;
        }

        $this->output->writeln((string) json_encode([
            'ok' => $exit === self::SUCCESS,
            'remote' => RemoteSite::configuredUrl() ?: null,
            'dry_run' => (bool) $this->option('dry-run'),
            ...$this->outcome,
            'messages' => array_values(array_filter(array_map('trim', explode("\n", $buffer->fetch())), fn (string $line) => $line !== '')),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $exit;
    }

    private function push(LocalContent $local): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('content:push sends this checkout\'s content to another site, so it runs only where APP_ENV is local.');

            return self::FAILURE;
        }

        if (RemoteSite::configuredUrl() === '') {
            $this->error('Set core-web.content_sync.remote_url in config/core-web.php: the live site to push content to.');

            return self::FAILURE;
        }

        try {
            $selection = $this->selection($local);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $lock = $local->lock();

        if ($lock['remote'] === null) {
            $this->error('Nothing has been pulled here yet: run php artisan content:pull first, so a push knows which version of each file it changes.');

            return self::FAILURE;
        }

        try {
            $files = $this->changed($local, $lock, $selection);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->outcome['files'] = array_keys($files);

        if ($files === []) {
            $this->line('Nothing to push: no selected file changed since the last pull or push (php artisan content:status lists what would go).');

            return self::SUCCESS;
        }

        $this->line('<comment>To push:</comment>');

        foreach ($files as $path => $file) {
            $this->line('  '.$path.($file['assets'] === [] ? '' : ' (with '.implode(', ', array_keys($file['assets'])).')'));
        }

        $unbased = array_keys(array_filter($files, fn (array $file, string $path) => $file['base'] === null && $file['draft'] === null && isset($lock['items'][$path]), ARRAY_FILTER_USE_BOTH));

        if ($this->option('force') && $unbased !== []) {
            $this->warn(count($unbased).' file(s) were pulled before the lock kept bases, so --force cannot tell whether the site changed them since: '.implode(', ', $unbased).'. Pull first to keep an editor\'s edit.');
        }

        $remote = $this->signedInSite();

        if ($remote === null) {
            return self::FAILURE;
        }

        try {
            return $this->send($local, $remote, $files);
        } catch (RemoteLoginNeeded) {
            if (($remote = $this->signInAgain()) === null) {
                return self::FAILURE;
            }

            try {
                return $this->send($local, $remote, $files);
            } catch (RemoteLoginNeeded) {
                $this->refusedFreshLogin();

                return self::FAILURE;
            }
        } catch (RemoteSiteError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, array{hash: string, contents: string, base: ?string, draft: ?string, parent_id: ?string, assets: array<string, array<string, mixed>>}>  $files
     */
    private function send(LocalContent $local, RemoteSite $remote, array $files): int
    {
        $assets = array_merge(...array_values(array_map(fn (array $file) => $file['assets'], $files)));
        $payload = [
            'items' => array_values(array_map(fn (string $path, array $file) => array_filter([
                'path' => $path,
                'contents' => $file['contents'],
                'base' => $file['base'],
                'draft' => $file['draft'],
                'parent_id' => $file['parent_id'],
                'assets' => array_keys($file['assets']),
            ], fn (mixed $value) => $value !== null && $value !== []), array_keys($files), $files)),
            'assets' => array_values(array_map(fn (array $asset) => Arr::only($asset, ['container', 'path', 'hash', 'size']), $assets)),
            'git_sha' => $this->gitSha(),
            'partial' => (bool) $this->option('partial'),
            'force' => (bool) $this->option('force'),
            'publish' => (bool) $this->option('publish'),
        ];

        $checked = $this->request($remote, [...$payload, 'dry_run' => true]);
        $refused = array_filter($checked['results'], fn (array $result) => $result['status'] === ContentPush::STATUS_REFUSED);
        $this->outcome['results'] = $checked['results'];

        if ($this->option('dry-run') || ($refused !== [] && ! $this->option('partial'))) {
            $this->report($checked['results']);

            if ($refused !== [] && ! $this->option('dry-run')) {
                $this->error('Nothing was pushed: '.count($refused).' of '.count($files).' files were refused (--partial pushes the rest).');

                return self::FAILURE;
            }

            $this->line('Dry run: nothing was pushed.');

            return $refused === [] ? self::SUCCESS : self::FAILURE;
        }

        $passing = array_column(array_filter($checked['results'], fn (array $result) => $result['status'] !== ContentPush::STATUS_REFUSED), 'path');
        $statuses = collect($checked['assets'] ?? [])->keyBy(fn (array $asset) => $asset['container'].'::'.$asset['path']);

        foreach ($passing as $path) {
            foreach ($files[$path]['assets'] as $key => $asset) {
                if (($statuses[$key]['status'] ?? null) !== AssetPush::MISSING) {
                    continue;
                }

                $this->upload($remote, $asset);
                $statuses[$key] = ['status' => AssetPush::SAME];
            }
        }

        $result = $this->request($remote, [...$payload, 'dry_run' => false]);
        $this->outcome = [...$this->outcome, 'written' => (bool) ($result['written'] ?? false), 'results' => $result['results']];
        $this->report($result['results']);

        $pushed = $local->lock()['pushed'];
        $drafts = [];
        $bases = [];
        $published = [];
        $written = 0;
        $live = 0;

        foreach ($result['results'] as $item) {
            if (! isset($files[$item['path']])) {
                continue;
            }

            // A refused page tree the push still placed new entries in names the site's version, so a retry is not refused as changed.
            if ($item['status'] === ContentPush::STATUS_REFUSED) {
                if (is_string($item['base'] ?? null)) {
                    $bases[$item['path']] = $item['base'];
                }

                continue;
            }

            $written++;
            $isLive = in_array($item['status'], [ContentPush::STATUS_PUBLISHED, ContentPush::STATUS_APPLIED], true);
            // A file the site already held as sent is as live as one the push made live, and its version is the site's.
            $isLanded = $isLive || $item['status'] === ContentPush::STATUS_UNCHANGED;

            // What a --publish push left as a draft is not recorded as pushed, so the next one sends it again.
            if (! $this->option('publish') || $isLanded) {
                $pushed[$item['path']] = $files[$item['path']]['hash'];
            }

            if (is_string($item['draft'] ?? null)) {
                $drafts[$item['path']] = $item['draft'];
            }

            if ($isLive) {
                $live++;
            }

            if ($isLanded) {
                $published[$item['path']] = $files[$item['path']]['hash'];

                if (is_string($item['base'] ?? null)) {
                    $bases[$item['path']] = $item['base'];
                }
            }
        }

        if ($result['written'] ?? false) {
            $local->writePushed($pushed, $drafts, $bases, $published);
        }

        if ($this->option('publish') && ! self::reportsPublishing($result['results'])) {
            $this->warn('The site said nothing about publishing: it may predate content:push --publish. What was pushed is a draft or proposal; publish it in the CP, or update core-web on the site.');
        }

        $failed = count($result['results']) - $written;

        if ($this->option('publish')) {
            $waiting = $written - $live - count(array_filter($result['results'], fn (array $item) => $item['status'] === ContentPush::STATUS_UNCHANGED));
            $this->info(sprintf('Pushed to %s: %d published or applied, %d left for an editor in the CP, %d refused.', $remote->url(), $live, $waiting, $failed));
        } else {
            $this->info(sprintf('Pushed to %s: %d drafted or proposed, %d refused. An editor publishes or applies them in the CP.', $remote->url(), $written, $failed));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{written: bool, results: list<array<string, mixed>>, assets?: list<array<string, mixed>>}
     */
    private function request(RemoteSite $remote, array $payload): array
    {
        $response = $remote->post('push', $payload);

        if ($response->status() === 404 && $response->json('error') === null) {
            throw new RemoteSiteError("{$remote->url()} takes no content push: its core-web predates content:push.");
        }

        if (! $response->successful() || ! is_array($response->json('results'))) {
            throw new RemoteSiteError("{$remote->url()} answered HTTP {$response->status()} to the push: ".($response->json('message') ?? $response->json('error') ?? 'no reason given').'.');
        }

        return $response->json();
    }

    /** @param  array<string, mixed>  $asset */
    private function upload(RemoteSite $remote, array $asset): void
    {
        $response = $remote->upload('push/asset', array_filter([
            'container' => $asset['container'],
            'path' => $asset['path'],
            'alt' => $asset['alt'] ?? null,
            'focus' => $asset['focus'] ?? null,
        ], fn (mixed $value) => is_string($value) && $value !== ''), basename($asset['path']), (string) file_get_contents($asset['file']));

        if (! $response->successful()) {
            throw new RemoteSiteError("Uploading {$asset['container']}::{$asset['path']} was refused (HTTP {$response->status()}): ".($response->json('message') ?? 'no reason given').' Nothing else was pushed.');
        }

        $this->line("Uploaded {$asset['container']}::{$asset['path']}.");
    }

    /**
     * The files the selection sends, each with what the push sends for it.
     *
     * @param  array{items: array<string, string>, bases: array<string, string>, pushed: array<string, string>, drafts: array<string, string>, published: array<string, string>}  $lock
     * @return array<string, array{hash: string, contents: string, base: ?string, draft: ?string, parent_id: ?string, assets: array<string, array<string, mixed>>}>
     */
    private function changed(LocalContent $local, array $lock, PushSelection $selection): array
    {
        $selected = $selection->files($lock);
        $max = max(1, (int) config('core-web.content_sync.push_items', 200));

        if (count($selected) > $max) {
            throw new InvalidArgumentException(count($selected)." files changed, more than one push carries ({$max}): narrow it with paths, --only or --site.");
        }

        $layers = PushSelection::assetLayers();
        $files = [];

        foreach ($selected as $path => $file) {
            $contents = (string) $local->read($path);
            $files[$path] = [
                'hash' => $file['hash'],
                'contents' => $contents,
                'base' => $lock['bases'][$path] ?? null,
                'draft' => $lock['drafts'][$path] ?? null,
                'parent_id' => $file['type'] === 'entries' ? $this->parentOf($local, $path, $contents) : null,
                'assets' => PushSelection::assetsIn($contents, $layers),
            ];
        }

        return $files;
    }

    /**
     * Where a new entry of a structured collection goes on the site: its parent in this checkout's tree, or the root for the top level.
     */
    private function parentOf(LocalContent $local, string $path, string $contents): ?string
    {
        $scope = ContentPaths::parse('entries', $path);
        $collection = $scope === null ? null : Collection::findByHandle($scope['handle']);

        if ($collection === null || ! $collection->hasStructure()) {
            return null;
        }

        try {
            $id = YAML::file($path)->parse($contents)['id'] ?? null;
        } catch (Throwable) {
            return null;
        }

        $directory = ContentPaths::directory('collection-trees');
        $tree = null;

        foreach (["{$directory}/{$scope['site']}/{$scope['handle']}.yaml", "{$directory}/{$scope['handle']}.yaml"] as $candidate) {
            if (($found = $local->read($candidate)) !== null) {
                $tree = $found;

                break;
            }
        }

        if (! is_string($id) || $tree === null) {
            return null;
        }

        try {
            $branches = (array) (YAML::parse($tree)['tree'] ?? []);
        } catch (Throwable) {
            return null;
        }

        $parent = self::findParent($branches, $id, null);

        if ($parent !== false) {
            return $parent ?? ($collection->structure()->expectsRoot() ? ($branches[0]['entry'] ?? null) : null);
        }

        return null;
    }

    /**
     * The entry of the branch whose children hold the id: null when it is on the top level, false when the tree does not hold it.
     *
     * @param  array<mixed>  $branches
     */
    private static function findParent(array $branches, string $id, ?string $parent): string|false|null
    {
        foreach ($branches as $branch) {
            if (! is_array($branch)) {
                continue;
            }

            if (($branch['entry'] ?? null) === $id) {
                return $parent;
            }

            if (is_array($branch['children'] ?? null) && ($found = self::findParent($branch['children'], $id, is_string($branch['entry'] ?? null) ? $branch['entry'] : $parent)) !== false) {
                return $found;
            }
        }

        return false;
    }

    private function selection(LocalContent $local): PushSelection
    {
        return PushSelection::fromOptions(
            $local,
            $this->option('only'),
            $this->option('site'),
            (array) $this->argument('paths'),
            (bool) $this->option('staged'),
            (bool) $this->option('committed'),
            (bool) $this->option('publish'),
        );
    }

    private function gitSha(): ?string
    {
        try {
            $result = Process::path(base_path())->timeout(10)->run(['git', 'rev-parse', 'HEAD']);
        } catch (Throwable) {
            return null;
        }

        $sha = trim($result->output());

        return $result->successful() && preg_match('/^[0-9a-f]{7,64}$/', $sha) === 1 ? $sha : null;
    }

    /**
     * Whether the site answered a --publish push with what it published or left (a site that predates the flag answers with drafts alone).
     *
     * @param  list<array<string, mixed>>  $results
     */
    private static function reportsPublishing(array $results): bool
    {
        $drafted = array_filter($results, fn (array $result) => ! in_array($result['status'], [ContentPush::STATUS_REFUSED, ContentPush::STATUS_UNCHANGED], true));

        return $drafted === [] || collect($drafted)->contains(fn (array $result) => isset($result['not_published']) || in_array($result['status'], [ContentPush::STATUS_PUBLISHED, ContentPush::STATUS_APPLIED], true));
    }

    /** @param  list<array<string, mixed>>  $results */
    private function report(array $results): void
    {
        foreach ($results as $result) {
            $line = sprintf('  %-13s %s', $result['status'], $result['path']);

            if ($result['status'] === ContentPush::STATUS_REFUSED) {
                $this->line("<error>{$line}</error>  [".($result['error'] ?? 'refused').'] '.($result['message'] ?? ''));

                continue;
            }

            $this->line($line.(isset($result['cp_url']) ? "  {$result['cp_url']}" : ''));

            if (isset($result['not_published'])) {
                $this->line("  <comment>{$result['not_published']}</comment>");
            }

            if (($result['ignored_fields'] ?? []) !== []) {
                $this->line('  <comment>Left out, as the site does not take them: '.implode(', ', (array) $result['ignored_fields']).'.</comment>');
            }

            if ($result['status'] === ContentPush::STATUS_PUBLISHED && ($result['images_needed'] ?? []) !== []) {
                $this->line('  <comment>Live without the required images: '.implode(', ', (array) $result['images_needed']).'; add them in the CP.</comment>');
            }
        }
    }
}
