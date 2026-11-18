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
 * text and focal points, straight to the site's bucket on signed uploads, so
 * this machine needs no bucket key (docs/media.md, Copying files into a
 * container).
 */
class MediaPushCommand extends Command
{
    use SignsInToRemoteSite;

    protected $signature = 'media:push
        {from : The folder to copy; its files keep their paths below it in the container (public/assets, or a copy of an old site\'s)}
        {--container=assets : The asset container on the site}
        {--dry-run : Check every file on the site and send nothing}';

    protected $description = 'Copy a folder of files into an asset container on the remote site (core-web.content_sync.remote_url)';

    /** The most bytes one batch of signed uploads carries. */
    private const UPLOAD_BATCH_BYTES = 256 * 1024 * 1024;

    /** The most bytes one finish request names, since saving an image's asset reads it back from the bucket. */
    private const FINISH_BATCH_BYTES = 100 * 1024 * 1024;

    /** Seconds after which the addresses not used yet are signed again, well inside MediaPush::URL_MINUTES. */
    private const SIGN_AGAIN_AFTER = 600;

    /** The slowest upload, in bytes a second (about 2 Mbit/s), a large file's timeout allows for. */
    private const SLOWEST_UPLOAD = 256 * 1024;

    private const BUSY_RETRIES = 5;

    private const PUT_TRIES = 3;

    /** @var array<string, array{file: string, size: int, md5?: string, alt?: string, focus?: string}> path => the file and what is sent for it */
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

            // An SVG is stored sanitised, so the site compares the size and MD5 it will have.
            $stored = strtolower($file->getExtension()) === 'svg' ? AssetPush::stored($file->getFilename(), (string) file_get_contents($file->getPathname())) : null;
            $sizes = $stored === null ? ['size' => (int) $file->getSize()] : ['size' => strlen($stored), 'md5' => md5($stored)];

            $this->files[$path] = ['file' => $file->getPathname(), ...$sizes, ...AssetPush::localMeta($file)];
        }

        ksort($this->files);
    }

    private function copy(RemoteSite $remote): int
    {
        $this->tally = ['stored' => 0, 'same' => 0, 'conflicts' => [], 'refused' => $this->skipped, 'failed' => []];
        $missing = [];
        $unfinished = [];

        // The site answers for every file before anything else is shown.
        $this->line(sprintf('Checking %d file(s) on %s.', count($this->files), $remote->url()));

        foreach ($this->check($remote, array_keys($this->files)) as $result) {
            match ($result['status']) {
                AssetPush::MISSING => $missing[] = $result['path'],
                MediaPush::UNFINISHED => $unfinished[] = $result['path'],
                default => $this->count($result),
            };
        }

        $bytes = array_sum(array_map(fn (string $path) => $this->files[$path]['size'], $missing));

        $this->line(sprintf(
            '%s: %d file(s), %d to copy (%s), %d to finish, %d already there, %d conflicting, %d refused.',
            (string) $this->option('container'),
            count($this->files) + count($this->skipped),
            count($missing),
            Number::fileSize($bytes),
            count($unfinished),
            $this->tally['same'],
            count($this->tally['conflicts']),
            count($this->tally['refused']),
        ));

        $todo = [...$missing, ...$unfinished];
        sort($todo);

        if (! $this->option('dry-run') && $todo !== []) {
            $this->send($remote, $todo);
            $this->recheck($remote, $todo);
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
            $answers = $this->uploads($remote, $batch);
            $signedAt = now()->getTimestamp();

            foreach ($batch as $i => $path) {
                // Addresses expire: what is left of a batch slow to send is signed again before it is used.
                if (now()->getTimestamp() - $signedAt > self::SIGN_AGAIN_AFTER && ($answers[$path]['status'] ?? null) === MediaPush::UPLOAD) {
                    $answers = [...$answers, ...$this->uploads($remote, array_slice($batch, $i))];
                    $signedAt = now()->getTimestamp();
                }

                $answer = $answers[$path];
                $ready = match ($answer['status']) {
                    MediaPush::UPLOAD => $this->put($path, $answer, $signedAt, fn () => $this->uploads($remote, [$path])[$path]),
                    MediaPush::FINISH => true,
                    MediaPush::DIRECT => $this->direct($remote, $path),
                    default => $this->count($answer),
                };

                if ($ready === true) {
                    $finish[] = ['path' => $path, 'ticket' => (string) $answer['ticket']];
                }

                $bar->advance();
            }

            $this->finish($remote, $finish);
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * Saves the asset of each file sent, sending again the files a request ran out of time for. The site finishes
     * at least the first file of each request, so every round leaves fewer.
     *
     * @param  list<array{path: string, ticket: string}>  $items
     */
    private function finish(RemoteSite $remote, array $items): void
    {
        while ($items !== []) {
            $later = [];

            foreach ($this->finishBatches($items) as $batch) {
                // The site may read each file back from the bucket, which can take longer than an ordinary request.
                foreach ($this->batch($remote, 'media/finish', array_map(fn (array $item) => array_filter([
                    'ticket' => $item['ticket'],
                    'alt' => $this->files[$item['path']]['alt'] ?? null,
                    'focus' => $this->files[$item['path']]['focus'] ?? null,
                ], fn (mixed $value) => $value !== null), $batch), $this->uploadTimeout(0)) as $i => $result) {
                    // A refused ticket names no path, so the request's own order says which file it was.
                    if (($result['status'] ?? null) === MediaPush::LATER) {
                        $later[] = $batch[$i];
                    } else {
                        $this->count(['path' => $batch[$i]['path'], ...array_diff_key($result, ['path' => true])]);
                    }
                }
            }

            $items = $later;
        }
    }

    /**
     * The site's answer for each file of a batch, by path: how to send it, signed for the file's MD5.
     *
     * @param  list<string>  $paths
     * @return array<string, array<string, mixed>>
     */
    private function uploads(RemoteSite $remote, array $paths): array
    {
        $answers = $this->batch($remote, 'media/uploads', array_map(fn (string $path) => [
            'path' => $path,
            'size' => $this->files[$path]['size'],
            'md5' => $this->md5($path),
        ], $paths));

        // The request's own order says which file each answer is for.
        return array_combine($paths, $answers);
    }

    private function md5(string $path): string
    {
        return $this->files[$path]['md5'] ??= (string) md5_file($this->files[$path]['file']);
    }

    /** Seconds to wait for a file of this size to upload: `timeouts.upload`, or longer for a large file. */
    private function uploadTimeout(int $size): int
    {
        return max(1, (int) config('core-web.content_sync.timeouts.upload', 600), (int) ceil($size / self::SLOWEST_UPLOAD));
    }

    /**
     * Sends one file to the address the site signed. True once the bucket has a file at the path, which finishing
     * then compares with this one. A try after the address is old is made on a fresh one, which `$answer` then holds.
     *
     * @param  array<string, mixed>  $answer
     * @param  Closure(): array<string, mixed>  $signAgain
     */
    private function put(string $path, array &$answer, int $signedAt, Closure $signAgain): bool
    {
        $reason = '';

        for ($try = 1; $try <= self::PUT_TRIES; $try++) {
            if ($try > 1) {
                Sleep::for(2 ** $try)->seconds();

                if (now()->getTimestamp() - $signedAt > self::SIGN_AGAIN_AFTER) {
                    $fresh = $signAgain();
                    $signedAt = now()->getTimestamp();

                    // A file of this size without meta is at the path now, perhaps from an earlier try: finishing on
                    // this upload's own ticket compares it and still takes it out when it is this run's and refused.
                    if ($fresh['status'] === MediaPush::FINISH) {
                        return true;
                    }

                    if ($fresh['status'] !== MediaPush::UPLOAD) {
                        return $this->count(['path' => $path, ...array_diff_key($fresh, ['path' => true])]);
                    }

                    $answer = $fresh;
                }
            }

            $request = $this->putRequest($path, $answer);

            if ($request === null) {
                return false;
            }

            $stream = fopen($this->files[$path]['file'], 'rb');

            if ($stream === false) {
                $this->tally['failed'][$path] = 'could not be read here.';

                return false;
            }

            try {
                $response = Http::withoutRedirecting()
                    ->withHeaders($request['headers'])
                    ->connectTimeout(max(1, (int) config('core-web.content_sync.timeouts.connect', 5)))
                    ->timeout($this->uploadTimeout($this->files[$path]['size']))
                    ->withBody(Utils::streamFor($stream), $request['type'])
                    ->put($request['url']);
            } catch (ConnectionException $e) {
                $reason = $e->getMessage();

                continue;
            } finally {
                is_resource($stream) && fclose($stream);
            }

            // 412: a file is at the path already, perhaps this one from a try whose answer was lost.
            if ($response->successful() || $response->status() === 412) {
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

    /**
     * The address and headers of one upload, or null (counted as failed) for an address that is not https.
     *
     * @param  array<string, mixed>  $answer
     * @return array{url: string, type: string, headers: array<string, string>}|null
     */
    private function putRequest(string $path, array $answer): ?array
    {
        $url = (string) ($answer['url'] ?? '');

        try {
            RemoteSite::assertSecure($url);
        } catch (RemoteSiteError) {
            $this->tally['failed'][$path] = __('core-web::sync.media_push_https');

            return null;
        }

        /** @var array<string, string> $headers */
        $headers = array_map('strval', (array) ($answer['headers'] ?? []));
        $named = array_change_key_case($headers);
        unset($headers['Content-Type'], $headers['content-type']);
        // The bucket refuses a body whose MD5 differs (BadDigest); the site's signature names the same MD5.
        isset($named['content-md5']) || $headers['Content-MD5'] = base64_encode((string) hex2bin($this->md5($path)));
        // Asks the bucket not to replace a file that reached the path since the site looked.
        $headers['If-None-Match'] = '*';

        return ['url' => $url, 'type' => $named['content-type'] ?? 'application/octet-stream', 'headers' => $headers];
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

        if ($copied !== []) {
            $this->line(sprintf('Checking %d file(s) on %s again.', count($copied), $remote->url()));
        }

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
    private function batch(RemoteSite $remote, string $endpoint, array $files, ?int $timeout = null): array
    {
        $response = $this->whenNotBusy(fn () => $remote->post($endpoint, [
            'container' => (string) $this->option('container'),
            'files' => $files,
        ], $timeout));

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
