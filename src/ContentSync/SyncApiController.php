<?php

namespace Inly\CoreWeb\ContentSync;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\DraftProvenance;
use Inly\CoreWeb\Snapshots\SnapshotExport;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Statamic\Facades\User;

/** The machine side of content sync: the device login, logging out, the exports a pull reads and what a push sends. */
class SyncApiController
{
    public function device(Request $request, DeviceAuthorizations $devices): JsonResponse
    {
        $machine = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', (string) $request->input('machine')) ?? '');

        if ($machine === '') {
            return new JsonResponse(['error' => 'invalid_request', 'message' => __('core-web::sync.machine_required')], 422);
        }

        $started = $devices->start(mb_substr($machine, 0, 100), (string) $request->ip());
        $uri = cp_route('core-web.sync.authorize');

        return new JsonResponse([
            'device_code' => $started['device_code'],
            'user_code' => $started['user_code'],
            'verification_uri' => $uri,
            'verification_uri_complete' => $uri.'?'.http_build_query(['code' => $started['user_code']]),
            'expires_in' => $started['expires_in'],
            'interval' => $started['interval'],
        ]);
    }

    public function token(Request $request, DeviceAuthorizations $devices): JsonResponse
    {
        $deviceCode = $request->input('device_code');

        if (! is_string($deviceCode) || $deviceCode === '') {
            return new JsonResponse(['error' => 'expired_token'], 400);
        }

        $result = $devices->exchange($deviceCode);

        if (isset($result['error'])) {
            return new JsonResponse(['error' => $result['error']], 400);
        }

        return new JsonResponse([
            'token' => $result['token'],
            'expires_at' => $result['record']->expiresAt->toIso8601String(),
            'user' => ['name' => (string) $result['user']->name(), 'email' => (string) $result['user']->email()],
        ]);
    }

    public function logout(Request $request, SyncTokens $tokens): JsonResponse
    {
        if ($token = AuthenticateSyncToken::token($request)) {
            $tokens->revoke($token);
        }

        return new JsonResponse(['status' => 'revoked']);
    }

    /** Who the token signs in as, so a local checkout can mirror the account in its own CP (LocalSignIn). */
    public function me(): JsonResponse
    {
        $user = User::current();

        return new JsonResponse([
            'id' => (string) $user->id(),
            'name' => (string) $user->name(),
            'email' => (string) $user->email(),
            'super' => $user->isSuper(),
            'roles' => $user->explicitRoles()->keys()->values()->all(),
            'groups' => $user->groups()->keys()->values()->all(),
        ]);
    }

    public function export(Request $request, ContentExport $export, AssetExport $assets): JsonResponse
    {
        $type = (string) $request->query('type');
        $cursor = (string) $request->query('cursor', '0');
        $types = [...array_keys(ContentPaths::TYPES), AssetExport::TYPE];

        if (! in_array($type, $types, true) || ! ctype_digit($cursor)) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => __('core-web::sync.unknown_type', ['types' => implode(', ', $types)])], 422);
        }

        $handle = self::filter($request->query('handle'));
        $size = max(1, (int) config('core-web.content_sync.page_size', 200));

        if ($type !== AssetExport::TYPE) {
            return new JsonResponse($export->page(User::current(), $type, $handle, self::filter($request->query('site')), (int) $cursor, $size));
        }

        // A named container is answered for, so a pull can tell one it may not see from an empty one.
        $viewable = $handle === null ? true : $assets->viewable(User::current(), $handle);

        if ($viewable !== true) {
            return $viewable === null
                ? new JsonResponse(['error' => 'not_found', 'message' => __('core-web::sync.no_container', ['handle' => $handle])], 404)
                : new JsonResponse(['error' => 'forbidden', 'message' => __('core-web::sync.container_forbidden', ['handle' => $handle])], 403);
        }

        return new JsonResponse($assets->page(User::current(), $handle, (int) $cursor, $size));
    }

    /** The files of a push, checked as a whole and written only when every one passes (ContentPush), with what the site holds at each asset path they name. */
    public function push(Request $request, ContentPush $push, AssetPush $assets): JsonResponse
    {
        if ($refusal = self::pushRefusal()) {
            return $refusal;
        }

        $max = max(1, (int) config('core-web.content_sync.push_items', 200));
        $validator = Validator::make($request->all(), [
            'items' => ['required', 'array', 'list', "max:{$max}"],
            'items.*.path' => ['required', 'string', 'max:500', 'distinct'],
            'items.*.draft' => ['nullable', 'string', 'max:128'],
            'items.*.contents' => ['required', 'string', 'max:2000000'],
            'items.*.base' => ['nullable', 'string', 'max:128'],
            'items.*.parent_id' => ['nullable', 'string', 'max:128'],
            'items.*.assets' => ['nullable', 'array', 'max:200'],
            'items.*.assets.*' => ['string', 'max:600'],
            'assets' => ['nullable', 'array', 'list', 'max:500'],
            'assets.*.container' => ['required', 'string', 'max:100'],
            'assets.*.path' => ['required', 'string', 'max:500'],
            'assets.*.hash' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'assets.*.size' => ['nullable', 'integer', 'min:0'],
            'git_sha' => ['nullable', 'string', 'regex:/^[0-9a-f]{7,64}$/'],
            'publish' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => $validator->errors()->first(), 'errors' => $validator->errors()->toArray()], 422);
        }

        // Each file is capped, but a full batch of capped files would still be parsed whole in one request.
        if (array_sum(array_map(fn (array $item) => strlen((string) $item['contents']), (array) $request->input('items'))) > (int) config('core-web.content_sync.push_bytes', 10 * 1024 * 1024)) {
            return new JsonResponse(['error' => 'too_large', 'message' => __('core-web::sync.push_too_large')], 413);
        }

        if ($request->boolean('publish') && ! filter_var(config('core-web.content_sync.push_publish', false), FILTER_VALIDATE_BOOLEAN)) {
            return new JsonResponse(['error' => 'publish_off', 'message' => __('core-web::sync.push_publish_off')], 403);
        }

        // Going live over a change made on the site since the pull would publish over an editor's edit unseen.
        if ($request->boolean('publish') && $request->boolean('force')) {
            return new JsonResponse(['error' => 'publish_force', 'message' => __('core-web::sync.push_publish_force')], 422);
        }

        $user = User::current();
        $dryRun = $request->boolean('dry_run');
        $statuses = [];

        foreach ((array) $request->input('assets', []) as $asset) {
            $statuses[$asset['container'].'::'.$asset['path']] = ['container' => $asset['container'], 'path' => $asset['path'], ...$assets->check($user, $asset['container'], $asset['path'], $asset['hash'], isset($asset['size']) ? (int) $asset['size'] : null)];
        }

        $items = (array) $request->input('items');
        $refusedAssets = [];
        $arriving = [];

        foreach ($items as $i => $item) {
            foreach ((array) ($item['assets'] ?? []) as $key) {
                // A dry run is the push's check before it uploads what the site lacks, so those files count as there.
                if ($dryRun && ($statuses[$key]['status'] ?? null) === AssetPush::MISSING) {
                    $arriving[$i][] = $key;
                }

                $status = $statuses[$key]['status'] ?? null;

                if ($status === null || in_array($status, [AssetPush::CONFLICT, AssetPush::FORBIDDEN, AssetPush::INVALID], true) || (! $dryRun && $status === AssetPush::MISSING)) {
                    $refusedAssets[$i] = $statuses[$key]['message'] ?? __('core-web::sync.push_asset_missing', ['path' => $key]);

                    break;
                }
            }
        }

        $result = $push->push(self::author($request), array_map(fn (array $item, int $i) => [
            'path' => (string) $item['path'],
            'contents' => (string) $item['contents'],
            'base' => $item['base'] ?? null,
            'draft' => $item['draft'] ?? null,
            'parent_id' => $item['parent_id'] ?? null,
            'arriving_assets' => $arriving[$i] ?? [],
        ], $items, array_keys($items)), $request->boolean('partial'), $request->boolean('force'), $dryRun, $refusedAssets, $request->boolean('publish'));

        return new JsonResponse([...$result, 'assets' => array_values($statuses)]);
    }

    /** One asset file a push names, stored at its path when the site has none there (AssetPush). */
    public function pushAsset(Request $request, AssetPush $assets): JsonResponse
    {
        return $this->storeAsset($request, $assets, false);
    }

    /** Which of a checkout's files a container lacks, for media:push (MediaPush). */
    public function mediaCheck(Request $request, MediaPush $media): JsonResponse
    {
        return $this->mediaBatch($request, MediaPush::CHECK_FILES, [
            'files.*.path' => ['required', 'string', 'max:500'],
            'files.*.size' => ['required', 'integer', 'min:0'],
        ], fn (string $container, array $files) => $media->check(User::current(), $container, $files));
    }

    /** How media:push sends each file the container lacks: a signed upload straight to the bucket, or through media/file. */
    public function mediaUploads(Request $request, MediaPush $media): JsonResponse
    {
        return $this->mediaBatch($request, MediaPush::UPLOAD_FILES, [
            'files.*.path' => ['required', 'string', 'max:500'],
            'files.*.size' => ['required', 'integer', 'min:0'],
        ], fn (string $container, array $files) => $media->uploads(User::current(), $container, $files));
    }

    /** The assets for files media:push uploaded, saved once each is in the container. */
    public function mediaFinish(Request $request, MediaPush $media): JsonResponse
    {
        return $this->mediaBatch($request, MediaPush::FINISH_FILES, [
            'files.*.ticket' => ['required', 'string', 'max:4000'],
            'files.*.alt' => ['nullable', 'string', 'max:1000'],
            'files.*.focus' => ['nullable', 'string', 'max:100'],
        ], fn (string $container, array $files) => $media->finish(User::current(), $container, $files));
    }

    /** One file media:push sends through the site, stored as push/asset stores it but saved quietly. */
    public function mediaFile(Request $request, AssetPush $assets): JsonResponse
    {
        return $this->storeAsset($request, $assets, true);
    }

    private function storeAsset(Request $request, AssetPush $assets, bool $quietly): JsonResponse
    {
        if ($refusal = self::pushRefusal()) {
            return $refusal;
        }

        $container = $request->input('container');
        $path = $request->input('path');
        $file = $request->file('file');

        if (! is_string($container) || ! is_string($path) || ! $file instanceof UploadedFile || ! $file->isValid()) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => __('core-web::sync.push_asset_request')], 422);
        }

        $result = $assets->store(User::current(), $container, $path, $file, array_filter([
            'alt' => $request->input('alt'),
            'focus' => $request->input('focus'),
        ], fn (mixed $value) => is_string($value)), $quietly);

        $status = match ($result['status']) {
            AssetPush::CONFLICT => 409,
            AssetPush::FORBIDDEN => 403,
            AssetPush::INVALID => 422,
            default => 200,
        };

        return new JsonResponse(['container' => $container, 'path' => $path, ...$result], $status);
    }

    /**
     * @param  array<string, list<string>>  $rules  the rules for each file
     * @param  callable(string, list<array<string, mixed>>): list<array<string, mixed>>  $answer
     */
    private function mediaBatch(Request $request, int $max, array $rules, callable $answer): JsonResponse
    {
        if ($refusal = self::pushRefusal()) {
            return $refusal;
        }

        $validator = Validator::make($request->all(), [
            'container' => ['required', 'string', 'max:100'],
            'files' => ['required', 'array', 'list', 'max:'.$max],
            'files.*' => ['array'],
            ...$rules,
        ]);

        if ($validator->fails()) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => $validator->errors()->first(), 'errors' => $validator->errors()->toArray()], 422);
        }

        return new JsonResponse(['container' => $request->input('container'), 'files' => $answer((string) $request->input('container'), array_values((array) $request->input('files')))]);
    }

    private static function pushRefusal(): ?JsonResponse
    {
        if (! filter_var(config('core-web.content_sync.push', true), FILTER_VALIDATE_BOOLEAN)) {
            return new JsonResponse(['error' => 'push_off', 'message' => __('core-web::sync.push_off')], 404);
        }

        if (! AuthenticateSyncToken::mayPush(User::current())) {
            return new JsonResponse(['error' => 'forbidden', 'message' => __('core-web::sync.push_permission_needed')], 403);
        }

        return null;
    }

    /** The person who approved the machine, with the machine and commit the push came from on every draft and proposal it makes. */
    private static function author(Request $request): DraftAuthor
    {
        $user = User::current();
        $name = (string) ($user->name() ?: $user->email());
        $machine = AuthenticateSyncToken::token($request)?->machine ?? '';

        return new DraftAuthor($user, $name, DraftProvenance::make('content-push', [
            'by' => (string) $user->email(),
            'machine' => $machine,
            'git_sha' => $request->input('git_sha'),
        ]), __('core-web::sync.push_message', ['name' => $name, 'machine' => $machine]));
    }

    /** Only a snapshot this site registers, read as the site reads it: the request names nothing that reaches Core. */
    public function snapshot(Request $request, string $type, SnapshotRegistry $registry): JsonResponse
    {
        $cursor = (string) $request->query('cursor', '0');

        if (! ctype_digit($cursor)) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => __('core-web::sync.invalid_cursor')], 422);
        }

        if (! CoreWebServiceProvider::enabled('snapshots') || ! $registry->has($type)) {
            return new JsonResponse(['error' => 'unknown_snapshot', 'message' => __('core-web::sync.unknown_snapshot', ['type' => $type])], 404);
        }

        $page = app(SnapshotExport::class)->page($registry->get($type), (int) $cursor, max(1, (int) config('core-web.content_sync.page_size', 200)));

        if ($page === null) {
            return new JsonResponse(['error' => 'snapshot_unavailable', 'message' => __('core-web::sync.snapshot_unavailable', ['type' => $type])], 503, ['Retry-After' => '60']);
        }

        return new JsonResponse($page);
    }

    private static function filter(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
