<?php

namespace Inly\CoreWeb\Media\Push;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Statamic\Contracts\Assets\AssetContainer as Container;
use Statamic\Contracts\Auth\User;
use Statamic\Rules\AllowedFile;

/**
 * The two calls a content push makes for its files: `check` says which this site already has, and `store`
 * holds one it does not. The pushing person needs upload rights on the container, as in the CP.
 */
final class HeldAssetsController
{
    public function __construct(private readonly HeldAssets $assets) {}

    public function check(Request $request): JsonResponse
    {
        $items = $request->validate([
            'files' => ['required', 'array', 'max:500'],
            'files.*.container' => ['required', 'string'],
            'files.*.path' => ['required', 'string', 'max:1024'],
            'files.*.sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'files.*.size' => ['required', 'integer', 'min:0'],
        ])['files'];

        $user = $this->user($request);

        return response()->json(['files' => array_map(function (array $item) use ($user): array {
            try {
                $container = $this->container($user, $item['container']);

                return $this->assets->lookup($container, self::path($item['path']), $item['sha256'], (int) $item['size']);
            } catch (PushRefused $e) {
                return ['status' => 'refused', 'path' => $item['path'], 'reason' => $e->reason, 'message' => $e->getMessage()];
            }
        }, $items)]);
    }

    public function store(Request $request): JsonResponse
    {
        $max = max(1, (int) config('core-web.media.push.max_megabytes', 100)) * 1024;

        $input = $request->validate([
            'container' => ['required', 'string'],
            'path' => ['required', 'string', 'max:1024'],
            'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'file' => ['required', 'file', "max:{$max}"],
        ]);

        $user = $this->user($request);

        try {
            $container = $this->container($user, $input['container']);
            $path = self::path($input['path'], forUpload: true);

            /** @var UploadedFile $file */
            $file = $request->file('file');

            if (! self::allowed($file, $path)) {
                throw new PushRefused('file_type', 'This kind of file cannot be uploaded.');
            }

            $stream = fopen($file->getRealPath(), 'rb');

            try {
                return response()->json($this->assets->hold($container, $path, $input['sha256'], $stream, (string) ($user->email() ?? $user->id())));
            } finally {
                is_resource($stream) && fclose($stream);
            }
        } catch (PushRefused $e) {
            return response()->json(['status' => 'refused', 'reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function container(User $user, string $handle): Container
    {
        $container = $this->assets->container($handle);

        if ($container === null) {
            throw new PushRefused('unknown_container', "There is no asset container [{$handle}].");
        }

        if (! $user->can("upload {$handle} assets")) {
            throw new PushRefused('forbidden', "You cannot upload to [{$handle}].");
        }

        return $container;
    }

    /** A plain relative path: no `..`, no backslash, no control characters; an upload never names the holding folder. */
    private static function path(string $path, bool $forUpload = false): string
    {
        $path = trim($path, '/');
        $segments = explode('/', $path);

        if ($path === '' || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || array_intersect($segments, ['', '.', '..']) !== []) {
            throw new PushRefused('bad_path', "[{$path}] is not a file path.");
        }

        if ($forUpload && HeldAssets::isHeld($path)) {
            throw new PushRefused('bad_path', 'A file is pushed for the folder it belongs in, not the holding folder.');
        }

        return $path;
    }

    /** Statamic's own upload rule, on the name the file will have here rather than the name it was sent under. */
    private static function allowed(UploadedFile $file, string $path): bool
    {
        return Validator::make(
            ['file' => new UploadedFile($file->getRealPath(), basename($path), test: true)],
            ['file' => [new AllowedFile]],
        )->passes();
    }
}
