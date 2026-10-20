<?php

namespace Inly\CoreWeb\ContentSync;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Snapshots\SnapshotExport;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Statamic\Facades\User;

/** The machine side of content sync: the device login, logging out, and the exports a pull reads. */
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

    public function export(Request $request, ContentExport $export): JsonResponse
    {
        $type = (string) $request->query('type');
        $cursor = (string) $request->query('cursor', '0');

        if (! array_key_exists($type, ContentPaths::TYPES) || ! ctype_digit($cursor)) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => __('core-web::sync.unknown_type', ['types' => implode(', ', array_keys(ContentPaths::TYPES))])], 422);
        }

        return new JsonResponse($export->page(
            User::current(),
            $type,
            self::filter($request->query('handle')),
            self::filter($request->query('site')),
            (int) $cursor,
            max(1, (int) config('core-web.content_sync.page_size', 200)),
        ));
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
