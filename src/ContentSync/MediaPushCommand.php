<?php

namespace Inly\CoreWeb\ContentSync;

use Closure;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Number;
use Illuminate\Support\Sleep;

/**
 * Copies a folder of files into an asset container on the remote site
 * (core-web.content_sync.remote_url), at the same paths, with their alt
 * text and focal points: straight to the site's bucket on signed uploads,
 * so neither a bucket key nor the files' size passes through this machine's
 * config or the site's memory. Never replaces or deletes a file
 * (docs/media.md, Copying files into a container).
 */
class MediaPushCommand extends Command
{
    use SignsInToRemoteSite;

    protected $signature = 'media:push
        {from : The folder to copy; its files keep their paths below it in the container (public/assets, or a copy of an old site\'s)}
        {--container=assets : The asset container on the site}
        {--dry-run : Check every file on the site and send nothing}';

    protected $description = 'Copy a folder of files into an asset container on the remote site (core-web.content_sync.remote_url)';

    /** The most bytes one batch of signed uploads carries, so its last upload starts before the addresses expire. */
    private const UPLOAD_BATCH_BYTES = 256 * 1024 * 1024;

    /** The most bytes one finish request names, since the site reads each file to measure it. */
    private const FINISH_BATCH_BYTES = 200 * 1024 * 1024;

    private const BUSY_RETRIES = 5;

    private const PUT_TRIES = 3;

    /** @var array<string, array{file: string, size: int, alt?: string, focus?: string}> path => the file and what is sent for it */
    private array $files = [];

    /** @var array<string, string> path => why, for the files the folder holds that no container may name */
    private array $skipped = [];

    /** @var array{stored: int, same: int, conflicts: array<string, string>, refused: array<string, string>, failed: array<string, string>} path => why */
    private array $tally = ['stored' => 0, 'same' => 0, 'conflicts' => [], 'refused' => [], 'failed' => []];

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('media:push copies this checkout\'s files to another site, so it runs only where APP_ENV is local.');

            return self::FAILURE;
        }

        if (RemoteSite::configuredUrl() === '') {
            $this->error('Set core-web.content_sync.remote_url in config/core-web.php: the site to copy the files to.');

            return self::FAILURE;
        }

        $from = rtrim((string) $this->argument('from'), '/');

        if (! is_dir($from)) {
            $this->error("No folder [{$from}].");

            return self::FAILURE;
        }

        $this->collect($from);

        if ($this->files === []) {
            $this->info("Nothing to copy: [{$from}] holds no files.");

            return $this->skipped === [] ? self::SUCCESS : self::FAILURE;
        }

        $remote = $this->signedInSite();

        if ($remote === null) {
            return self::FAILURE;
        }

        try {
            return $this->copy($remote);
        } catch (RemoteLoginNeeded) {
            if (($remote = $this->signInAgain()) === null) {
                return self::FAILURE;
            }

            try {
                // What the first run copied is now `same`, so it is not sent twice.
                return $this->copy($remote);
            } catch (RemoteLoginNeeded) {
                $this->refusedFreshLogin();

                return self::FAILURE;
            }
        } catch (RemoteSiteError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function collect(string $from): void
    {
        // Hidden files and folders (.meta among them) are left out, as a container never lists them.
        foreach (File::allFiles($from) as $file) {
            $path = str_replace('\\', '/', $file->getRelativePathname());

            if (strlen($path) > 500 || ! AssetPush::safePath($path)) {
                $this->skipped[$path] = __('core-web::sync.push_asset_path', ['path' => $path]);

                continue;
            }

            // An SVG is stored sanitised, so the site compares the size it will have.
            $size = strtolower($file->getExtension()) === 'svg'
                ? strlen(AssetPush::stored($file->getFilename(), (string) file_get_contents($file->getPathname())))
                : (int) $file->getSize();

            $this->files[$path] = ['file' => $file->getPathname(), 'size' => $size, ...AssetPush::localMeta($file)];
        }

        ksort($this->files);
    }

    private function copy(RemoteSite $remote): int
    {
        $this->tally = ['stored' => 0, 'same' => 0, 'conflicts' => [], 'refused' => $this->skipped, 'failed' => []];
        $missing = [];

        foreach ($this->check($remote, array_keys($this->files)) as $result) {
            match ($result['status']) {
                AssetPush::MISSING, MediaPush::UNFINISHED => $missing[] = $result['path'],
                default => $this->count($result),
            };
        }

        $bytes = array_sum(array_map(fn (string $path) => $this->files[$path]['size'], $missing));
        $container = (string) $this->option('container');

        $this->line(sprintf(
            '%s: %d file(s), %d to copy (%s), %d already there, %d conflicting, %d refused.',
            $container,
            count($this->files) + count($this->skipped),
            count($missing),
            Number::fileSize($bytes),
            $this->tally['same'],
            count($this->tally['conflicts']),
            count($this->tally['refused']),
        ));

        if (! $this->option('dry-run') && $missing !== []) {
            $this->send($remote, $missing);
            $this->recheck($remote, $missing);
        }

        return $this->report();
    }

    /**
     * @param  list<string>  $paths
     * @return list<array{path: string, status: string, message?: string}>
     */
    private function check(RemoteSite $remote, array $paths): array
    {
        $results = [];

        foreach (array_chunk($paths, MediaPush::CHECK_FILES) as $chunk) {
            array_push($results, ...$this->batch($remote, 'media/check', array_map(fn (string $path) => [
                'path' => $path,
                'size' => $this->files[$path]['size'],
            ], $chunk)));
        }

        return $results;
    }

    /** @param  list<string>  $paths */
    private function send(RemoteSite $remote, array $paths): void
    {
        $bar = $this->output->createProgressBar(count($paths));
        $bar->start();

        foreach ($this->uploadBatches($paths) as $batch) {
            $finish = [];

            foreach ($this->batch($remote, 'media/uploads', array_map(fn (string $path) => [
                'path' => $path,
                'size' => $this->files[$path]['size'],
            ], $batch)) as $answer) {
                $path = (string) $answer['path'];
                $ready = match ($answer['status']) {
                    MediaPush::UPLOAD => $this->put($path, $answer),
                    MediaPush::FINISH => true,
                    MediaPush::DIRECT => $this->direct($remote, $path),
                    default => $this->count($answer),
                };

                if ($ready === true) {
                    $finish[] = ['path' => $path, 'ticket' => (string) $answer['ticket']];
                } else {
                    $bar->advance();
                }
            }

            foreach ($this->finishBatches($finish) as $items) {
                foreach ($this->batch($remote, 'media/finish', array_map(fn (array $item) => array_filter([
                    'ticket' => $item['ticket'],
                    'alt' => $this->files[$item['path']]['alt'] ?? null,
                    'focus' => $this->files[$item['path']]['focus'] ?? null,
                ], fn (mixed $value) => $value !== null), $items)) as $i => $result) {
                    // A refused ticket names no path, so the request's own order says which file it was.
                    $this->count(['path' => $items[$i]['path'], ...array_diff_key($result, ['path' => true])]);
                    $bar->advance();
                }
            }
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * Sends one file to the address the site signed. True once the bucket has it whole.
     *
     * @param  array<string, mixed>  $answer
     */
    private function put(string $path, array $answer): bool
    {
        $url = (string) ($answer['url'] ?? '');

        try {
            RemoteSite::assertSecure($url);
        } catch (RemoteSiteError) {
            $this->tally['failed'][$path] = __('core-web::sync.media_push_https');

            return false;
        }

        /** @var array<string, string> $headers */
        $headers = array_map('strval', (array) ($answer['headers'] ?? []));
        $type = $headers['Content-Type'] ?? $headers['content-type'] ?? 'application/octet-stream';
        // The bucket refuses a body whose MD5 differs (BadDigest), so a file is never stored half sent or changed.
        $headers['Content-MD5'] = base64_encode((string) md5_file($this->files[$path]['file'], true));
        $reason = '';

        for ($try = 1; $try <= self::PUT_TRIES; $try++) {
            $stream = fopen($this->files[$path]['file'], 'rb');

            if ($stream === false) {
                $this->tally['failed'][$path] = 'could not be read here.';

                return false;
            }

            try {
                $response = Http::withoutRedirecting()
                    ->withHeaders($headers)
                    ->connectTimeout(max(1, (int) config('core-web.content_sync.timeouts.connect', 5)))
                    ->timeout(max(1, (int) config('core-web.content_sync.timeouts.upload', 600)))
                    ->withBody(Utils::streamFor($stream), $type)
                    ->put($url);
            } catch (ConnectionException $e) {
                $reason = $e->getMessage();

                continue;
            } finally {
                is_resource($stream) && fclose($stream);
            }

            if ($response->successful()) {
                return true;
            }

            $reason = "HTTP {$response->status()}".(self::bucketError($response) === '' ? '' : ' ('.self::bucketError($response).')');

            // The bucket refused the request itself (a signature, a policy): sending it again changes nothing.
            if ($response->status() < 500) {
                break;
            }
        }

        $this->tally['failed'][$path] = "the bucket refused the upload: {$reason}.";

        return false;
    }

    /** Sends one file through the site, which stores and saves it in one request; never ready to finish. */
    private function direct(RemoteSite $remote, string $path): false
    {
        $file = $this->files[$path];

        $response = $this->whenNotBusy(fn () => $remote->upload('media/file', array_filter([
            'container' => (string) $this->option('container'),
            'path' => $path,
            'alt' => $file['alt'] ?? null,
            'focus' => $file['focus'] ?? null,
        ], fn (mixed $value) => is_string($value)), basename($path), (string) file_get_contents($file['file'])));

        if ($response->status() === 404 && $response->json('error') === null) {
            throw new RemoteSiteError(RemoteSite::configuredUrl().' takes no media:push: its core-web predates it.');
        }

        $result = $response->json();

        return $this->count(is_array($result) && is_string($result['status'] ?? null)
            ? ['path' => $path, ...array_diff_key($result, ['path' => true])]
            : ['path' => $path, 'status' => 'failed', 'message' => "HTTP {$response->status()}: ".($response->json('message') ?? 'no reason given')]);
    }

    /** @param  list<string>  $paths */
    private function recheck(RemoteSite $remote, array $paths): void
    {
        $copied = array_values(array_diff($paths, array_keys($this->tally['failed']), array_keys($this->tally['conflicts']), array_keys($this->tally['refused'])));

        foreach ($copied === [] ? [] : $this->check($remote, $copied) as $result) {
            if ($result['status'] !== AssetPush::SAME) {
                $this->tally['failed'][$result['path']] = "not in the container after the copy ({$result['status']}); run media:push again.";
            }
        }
    }

    /**
     * POSTs one batch of files and returns the site's answer for each, waiting out a rate limit.
     *
     * @param  list<array<string, mixed>>  $files
     * @return list<array<string, mixed>>
     */
    private function batch(RemoteSite $remote, string $endpoint, array $files): array
    {
        $response = $this->whenNotBusy(fn () => $remote->post($endpoint, [
            'container' => (string) $this->option('container'),
            'files' => $files,
        ]));

        if ($response->status() === 404 && $response->json('error') === null) {
            throw new RemoteSiteError("{$remote->url()} takes no media:push: its core-web predates it.");
        }

        $answers = $response->json('files');

        if (! $response->successful() || ! is_array($answers) || count($answers) !== count($files)) {
            throw new RemoteSiteError("{$remote->url()} answered HTTP {$response->status()} to {$endpoint}: ".($response->json('message') ?? $response->json('error') ?? 'no reason given').'.');
        }

        return array_values($answers);
    }

    /** @param  Closure(): Response  $call */
    private function whenNotBusy(Closure $call): Response
    {
        for ($try = 1; ; $try++) {
            try {
                return $call();
            } catch (RemoteSiteBusy $e) {
                if ($try > self::BUSY_RETRIES) {
                    throw $e;
                }

                Sleep::for(max(1, min(120, $e->retryAfter ?? 30)))->seconds();
            }
        }
    }

    /**
     * Tallies the site's answer for a file that needs nothing more sent.
     *
     * @param  array<string, mixed>  $result
     */
    private function count(array $result): false
    {
        $path = (string) $result['path'];
        $why = (string) ($result['message'] ?? $result['status']);

        match ($result['status']) {
            MediaPush::STORED => $this->tally['stored']++,
            AssetPush::SAME => $this->tally['same']++,
            AssetPush::CONFLICT => $this->tally['conflicts'][$path] = $why,
            AssetPush::INVALID, AssetPush::FORBIDDEN => $this->tally['refused'][$path] = $why,
            default => $this->tally['failed'][$path] = $why,
        };

        return false;
    }

    private function report(): int
    {
        foreach (['conflicts' => 'warn', 'refused' => 'error', 'failed' => 'error'] as $kind => $style) {
            foreach (array_slice($this->tally[$kind], 0, 50, true) as $path => $why) {
                $this->{$style}("  {$path}: {$why}");
            }

            if (count($this->tally[$kind]) > 50) {
                $this->{$style}('  and '.(count($this->tally[$kind]) - 50)." more {$kind}.");
            }
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was sent.');
        } else {
            $this->info("Copied {$this->tally['stored']} file(s).");
        }

        if ($this->tally['conflicts'] !== []) {
            $this->warn('A conflicting file is another file at the same path on the site; it was left as it is.');
        }

        return $this->tally['refused'] === [] && $this->tally['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $paths
     * @return list<list<string>>
     */
    private function uploadBatches(array $paths): array
    {
        return self::bounded($paths, MediaPush::UPLOAD_FILES, self::UPLOAD_BATCH_BYTES, fn (string $path) => $this->files[$path]['size']);
    }

    /**
     * @param  list<array{path: string, ticket: string}>  $items
     * @return list<list<array{path: string, ticket: string}>>
     */
    private function finishBatches(array $items): array
    {
        return self::bounded($items, MediaPush::FINISH_FILES, self::FINISH_BATCH_BYTES, fn (array $item) => $this->files[$item['path']]['size']);
    }

    /**
     * Splits a list into batches of at most `$files` items and `$bytes` bytes; an item larger than that goes alone.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @param  Closure(T): int  $size
     * @return list<list<T>>
     */
    private static function bounded(array $items, int $files, int $bytes, Closure $size): array
    {
        $batches = [];
        $batch = [];
        $total = 0;

        foreach ($items as $item) {
            if ($batch !== [] && (count($batch) >= $files || $total + $size($item) > $bytes)) {
                $batches[] = $batch;
                $batch = [];
                $total = 0;
            }

            $batch[] = $item;
            $total += $size($item);
        }

        return $batch === [] ? $batches : [...$batches, $batch];
    }

    /** The error code an S3-compatible bucket answers with (SignatureDoesNotMatch, AccessDenied), when it gives one. */
    private static function bucketError(Response $response): string
    {
        return preg_match('/<Code>([^<]+)<\/Code>/', $response->body(), $match) === 1 ? $match[1] : '';
    }
}
