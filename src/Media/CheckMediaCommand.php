<?php

namespace Inly\CoreWeb\Media;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** What each media disk is in this environment, and whether the bucket serves its files the way browsers and the CDN need. */
class CheckMediaCommand extends Command
{
    protected $signature = 'media:check {path? : A file to check, relative to the disk; the first image found when omitted} {--disk=assets}';

    protected $description = 'Check the media disks, the bucket\'s headers and a CDN rendition';

    private bool $failed = false;

    public function handle(UrlScheme $scheme): int
    {
        $name = (string) $this->option('disk');
        $folder = config("core-web.media.disks.{$name}");

        if (! is_string($folder)) {
            $this->error("[{$name}] is not in core-web.media.disks.");

            return self::FAILURE;
        }

        if (! MediaDisks::defines($name)) {
            $this->warn("{$name}: config/filesystems.php defines it, so core-web does not. Remove it there to get the bucket, the layer and the local fallback.");

            return self::SUCCESS;
        }

        $driver = config("filesystems.disks.{$name}.driver");
        $this->line(sprintf('%s: %s', $name, match ($driver) {
            MediaDisks::LAYERED_DRIVER => 'the bucket, behind a layer (writes never reach production\'s files)',
            's3' => 'the bucket',
            default => 'the local folder public/'.trim($folder, '/'),
        }));

        if (! MediaDisks::bucketConfigured()) {
            $this->line('No bucket key here, so nothing more to check.');

            return self::SUCCESS;
        }

        $bucket = Storage::build(MediaDisks::bucket(trim($folder, '/')));
        $path = $this->argument('path') ?? $this->firstImage($bucket);

        if ($path === null) {
            $this->warn('No image in the bucket to check.');

            return self::SUCCESS;
        }

        $url = $bucket->url($path);
        $this->line("File: {$url}");
        $this->check($scheme->serves($url), 'its URL is under the CDN origin (core-web.media.cdn.origins)');

        try {
            $raw = Http::timeout(15)->withHeaders(['Origin' => 'https://example.com'])->head($url);
            $this->check($raw->successful(), "the raw file answers 200 (got {$raw->status()})");
            $this->check($raw->header('Cache-Control') !== '', 'the raw file has Cache-Control');
            $this->check($raw->header('Access-Control-Allow-Origin') !== '', 'the bucket answers CORS (Access-Control-Allow-Origin)');

            if ($scheme->serves($url)) {
                $rendition = Http::timeout(60)->get($scheme->image($url, ['width' => 16, 'format' => 'auto']));
                $this->check($rendition->successful() && str_starts_with($rendition->header('Content-Type'), 'image/'), "a CDN rendition answers 200 with an image (got {$rendition->status()})");
            }
        } catch (Throwable $e) {
            $this->check(false, "the file is reachable ({$e->getMessage()})");
        }

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function firstImage(Filesystem $bucket): ?string
    {
        foreach ($bucket->getDriver()->listContents('', true) as $item) {
            if ($item->isFile() && ! str_contains($item->path(), '.meta/') && preg_match('/\.(jpe?g|png|webp|gif|avif)$/i', $item->path()) === 1) {
                return $item->path();
            }
        }

        return null;
    }

    private function check(bool $passed, string $what): void
    {
        $passed ? $this->info("  ✓ {$what}") : $this->error("  ✗ {$what}");
        $this->failed = $this->failed || ! $passed;
    }
}
