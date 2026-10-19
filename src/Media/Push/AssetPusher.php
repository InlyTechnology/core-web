<?php

namespace Inly\CoreWeb\Media\Push;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Inly\CoreWeb\Media\LayeredAdapter;
use Statamic\Facades\AssetContainer;
use Statamic\Fields\Fields;

/**
 * The sending end of a content push: the files an entry's values point at that the remote site does not
 * have yet are uploaded into its holding folder, and the values are pointed at them there. A file a
 * layered disk does not hold in its layer is the remote's own and is never read or sent.
 */
final class AssetPusher
{
    public function __construct(private readonly PushTransport $transport) {}

    /**
     * @param  array<string, mixed>  $values  stored values
     * @return array{values: array<string, mixed>, files: list<array{id: string, status: string, path: string, reason?: string}>}
     */
    public function push(Fields $fields, array $values, bool $dryRun = false): array
    {
        $candidates = [];

        foreach (AssetReferences::collect($fields, $values) as $id) {
            [$container, $path] = explode('::', $id, 2);

            if (($file = $this->local($container, $path)) !== null) {
                $candidates[$id] = $file;
            }
        }

        if ($candidates === []) {
            return ['values' => $values, 'files' => []];
        }

        $answers = $this->transport->check(array_values(array_map(fn (array $file) => [
            'container' => $file['container'],
            'path' => $file['path'],
            'sha256' => $file['sha256'],
            'size' => $file['size'],
        ], $candidates)));

        $map = [];
        $files = [];

        foreach (array_values($candidates) as $index => $file) {
            $id = "{$file['container']}::{$file['path']}";
            $answer = $answers[$index] ?? ['status' => 'refused', 'path' => $file['path'], 'reason' => 'no_answer'];

            if ($answer['status'] === 'needed' && ! $dryRun) {
                $stream = $file['disk']->readStream($file['path']);
                $answer = is_resource($stream)
                    ? $this->transport->upload($file['container'], $file['path'], $file['sha256'], $stream)
                    : ['status' => 'refused', 'path' => $file['path'], 'reason' => 'unreadable'];
            }

            if (in_array($answer['status'], ['held', 'uploaded', 'needed'], true) && $answer['path'] !== $file['path']) {
                $map[$id] = $answer['path'];
            }

            $files[] = ['id' => $id, ...array_intersect_key($answer, array_flip(['status', 'path', 'reason']))];
        }

        return ['values' => AssetReferences::rewrite($fields, $values, $map), 'files' => $files];
    }

    /** @return array{container: string, path: string, sha256: string, size: int, disk: Filesystem}|null */
    private function local(string $handle, string $path): ?array
    {
        $container = AssetContainer::find($handle);

        if (! $container || HeldAssets::isHeld($path)) {
            return null;
        }

        $disk = Storage::disk($container->diskHandle());
        $adapter = $disk instanceof FilesystemAdapter ? $disk->getAdapter() : null;

        if (($adapter instanceof LayeredAdapter && ! $adapter->inLayer($path)) || ! $disk->exists($path)) {
            return null;
        }

        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            return null;
        }

        $context = hash_init('sha256');
        $size = 0;

        while (! feof($stream)) {
            $chunk = (string) fread($stream, 1 << 20);
            $size += strlen($chunk);
            hash_update($context, $chunk);
        }

        fclose($stream);

        return ['container' => $handle, 'path' => $path, 'sha256' => hash_final($context), 'size' => $size, 'disk' => $disk];
    }
}
