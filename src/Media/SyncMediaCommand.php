<?php

namespace Inly\CoreWeb\Media;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Copies each media disk's files, `.meta` folders included, between its local folder and the bucket. Never
 * deletes; a push leaves every file the bucket already has unless --overwrite, which still skips same-size files.
 */
class SyncMediaCommand extends Command
{
    protected $signature = 'media:sync
        {direction : push (local folder to bucket) or pull (bucket to local folder)}
        {--disk=* : Only these disks; every disk in core-web.media.disks when omitted}
        {--dry-run : List what would be copied, copy nothing}
        {--overwrite : Push over files in the bucket that differ in size (edited meta included)}
        {--force : Push without asking}';

    protected $description = 'Copy the media disks between their local folders and the bucket';

    public function handle(): int
    {
        $direction = $this->argument('direction');

        if (! in_array($direction, ['push', 'pull'], true)) {
            $this->error('The direction is push or pull.');

            return self::FAILURE;
        }

        if (! MediaDisks::bucketConfigured()) {
            $this->error('No bucket key here: set CORE_MEDIA_KEY and CORE_MEDIA_SECRET, or attach a Laravel Cloud bucket.');

            return self::FAILURE;
        }

        $disks = (array) config('core-web.media.disks', []);
        $only = (array) $this->option('disk');

        if ($only !== [] && ($unknown = array_diff($only, array_keys($disks))) !== []) {
            $this->error('Not a media disk: '.implode(', ', $unknown).'.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');

        if ($direction === 'push' && ! $dry && ! $this->option('force')
            && ! $this->confirm($this->option('overwrite')
                ? 'Push writes production\'s bucket and replaces its files that differ in size, edited meta included. Continue?'
                : 'Push adds the files production\'s bucket does not have yet; it changes and deletes none. Continue?')) {
            return self::FAILURE;
        }

        foreach ($disks as $disk => $folder) {
            if ($only !== [] && ! in_array($disk, $only, true)) {
                continue;
            }

            $folder = trim((string) $folder, '/');
            $local = Storage::build(MediaDisks::local($folder));
            $bucket = Storage::build(MediaDisks::bucket($folder));

            [$from, $to] = $direction === 'push' ? [$local, $bucket] : [$bucket, $local];
            $copied = $this->copy($from, $to, $dry, $direction === 'pull' || (bool) $this->option('overwrite'));

            $this->info(sprintf('%s: %d file(s) %s.', $disk, $copied, $dry ? 'to copy' : 'copied'));
        }

        return self::SUCCESS;
    }

    private function copy(Filesystem $from, Filesystem $to, bool $dry, bool $overwrite): int
    {
        $copied = 0;

        foreach ($from->allFiles() as $path) {
            if ($to->exists($path) && (! $overwrite || $to->size($path) === $from->size($path))) {
                continue;
            }

            $copied++;

            if ($dry) {
                $this->line("  {$path}");

                continue;
            }

            $stream = $from->readStream($path);

            if ($stream === null || ! $to->writeStream($path, $stream)) {
                $this->warn("  {$path}: copy failed.");
                $copied--;
            }
        }

        return $copied;
    }
}
