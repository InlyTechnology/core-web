<?php

namespace Inly\CoreWeb\Media;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/** Copies each media disk's files, `.meta` folders included, between its local folder and the bucket: skips files of the same size, never deletes. */
class SyncMediaCommand extends Command
{
    protected $signature = 'media:sync
        {direction : push (local folder to bucket) or pull (bucket to local folder)}
        {--disk=* : Only these disks; every disk in core-web.media.disks when omitted}
        {--dry-run : List what would be copied, copy nothing}
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
            $this->error('No bucket key here: set CORE_MEDIA_KEY and CORE_MEDIA_SECRET.');

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
            && ! $this->confirm('Push writes production\'s bucket (it never overwrites a file of the same size or deletes one). Continue?')) {
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
            $copied = $this->copy($from, $to, $dry);

            $this->info(sprintf('%s: %d file(s) %s.', $disk, $copied, $dry ? 'to copy' : 'copied'));
        }

        return self::SUCCESS;
    }

    private function copy(Filesystem $from, Filesystem $to, bool $dry): int
    {
        $copied = 0;

        foreach ($from->allFiles() as $path) {
            if ($to->exists($path) && $to->size($path) === $from->size($path)) {
                continue;
            }

            $copied++;

            if ($dry) {
                $this->line("  {$path}");

                continue;
            }

            if (! $to->writeStream($path, $from->readStream($path))) {
                $this->warn("  {$path}: copy failed.");
                $copied--;
            }
        }

        return $copied;
    }
}
