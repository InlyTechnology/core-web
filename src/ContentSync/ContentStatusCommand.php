<?php

namespace Inly\CoreWeb\ContentSync;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * What `content:push` with the same arguments would send, read from content/
 * and content.lock alone: it never calls the site, so it says nothing about
 * whether the site would take a file (`content:push --dry-run` does).
 */
class ContentStatusCommand extends Command
{
    protected $signature = 'content:status
        {paths?* : Only these files: each a file or folder under content/ (content/ prefix optional) or a * pattern}
        {--only= : Types, each optionally narrowed to a handle, as content:push takes them}
        {--site= : Only this site\'s content}
        {--staged : Only files whose contents are as git\'s index has them (git add)}
        {--committed : Only files whose contents are as the last commit has them}
        {--publish : What a content:push --publish would send: also what an earlier push left as a draft}
        {--json : Print one JSON document instead of text}';

    protected $description = 'List what content:push would send to the remote site, without calling it';

    public function handle(LocalContent $local): int
    {
        try {
            return $this->status($local);
        } catch (Throwable $e) {
            if (! $this->option('json')) {
                throw $e;
            }

            report($e);

            return $this->refuse($e->getMessage());
        }
    }

    private function status(LocalContent $local): int
    {
        try {
            $selection = PushSelection::fromOptions(
                $local,
                $this->option('only'),
                $this->option('site'),
                (array) $this->argument('paths'),
                (bool) $this->option('staged'),
                (bool) $this->option('committed'),
                (bool) $this->option('publish'),
            );
            $lock = $local->lock();

            if ($lock['remote'] === null) {
                throw new InvalidArgumentException('Nothing has been pulled here yet: run php artisan content:pull first, so a push knows which version of each file it changes.');
            }

            $files = $selection->files($lock);
        } catch (InvalidArgumentException $e) {
            return $this->refuse($e->getMessage());
        }

        $layers = PushSelection::assetLayers();
        $push = [];

        foreach ($files as $path => $file) {
            $push[] = [
                'path' => $path,
                'type' => $file['type'],
                'state' => $file['state'],
                'draft_on_site' => $file['draft'],
                'assets' => array_keys(PushSelection::assetsIn((string) $local->read($path), $layers)),
            ];
        }

        $waiting = $this->option('publish') ? [] : $selection->waiting($lock);
        $limit = max(1, (int) config('core-web.content_sync.push_items', 200));

        $tooMany = count($push) > $limit;

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => ! $tooMany,
                ...($tooMany ? ['error' => count($push)." files is more than one push carries ({$limit}): narrow it with paths, --only or --site."] : []),
                'remote' => $lock['remote'],
                'push' => $push,
                'waiting' => $waiting,
                'limit' => $limit,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $tooMany ? self::FAILURE : self::SUCCESS;
        }

        if ($push === []) {
            $this->line('Nothing to push: no selected file changed since the last pull or push.');
        } else {
            $this->line(sprintf('<comment>A push to %s sends %d file(s):</comment>', $lock['remote'], count($push)));

            foreach ($push as $file) {
                $notes = array_filter([
                    $file['draft_on_site'] ? 'updates the draft its last push left' : null,
                    $file['assets'] === [] ? null : 'with '.implode(', ', $file['assets']),
                ]);
                $this->line(sprintf('  %-9s %s', $file['state'], $file['path']).($notes === [] ? '' : '  ('.implode('; ', $notes).')'));
            }
        }

        if ($tooMany) {
            $this->error(count($push)." files is more than one push carries ({$limit}): narrow it with paths, --only or --site.");
        }

        if ($waiting !== []) {
            $this->line(sprintf('<comment>Pushed and waiting for an editor in the CP (%d), sent again only by --publish:</comment>', count($waiting)));

            foreach ($waiting as $path) {
                $this->line('  '.$path);
            }
        }

        return $tooMany ? self::FAILURE : self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => false, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
