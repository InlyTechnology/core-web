<?php

namespace Inly\CoreWeb\Ping;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inly\CoreWeb\Content\CoreEnvelope;
use Inly\CoreWeb\Content\EnvelopeRefused;
use Inly\CoreWeb\Content\WrongEnvironment;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;

/**
 * Receives Core's signed on-change ping (`{ type, id, public_id?, action }`)
 * and queues the job that reads the object back from Core; the data itself
 * never travels in the ping, and `public_id` is the document a deletion
 * removes. Each snapshot the ping type refreshes gets its own job: for the
 * one object named, or whole for a bulk ping (`id` = `all`, or `0`) or a
 * ping type the snapshot reads whole. An idle type is accepted and left
 * alone, so Core does not retry it; any other type is a 422.
 *
 * A ping sealed with Core's own key (`X-Core-*` headers, purpose
 * `website.objects`) carries its data as the JSON body the envelope binds;
 * one without those headers is the shared-secret `{ payload, signature }`.
 */
class PingController
{
    public function __invoke(Request $request, PingSignature $signature, SnapshotRegistry $snapshots): JsonResponse
    {
        $data = Validator::make(self::ping($request, $signature), [
            'type' => ['required', 'string', Rule::in([...$snapshots->pingTypes(), ...$snapshots->idleTypes()])],
            'id' => ['required'],
            'public_id' => ['nullable', 'string'],
            'action' => ['required', 'string', 'in:saved,deleted'],
        ])->validate();

        $id = self::id($data['id']);

        foreach ($snapshots->forPing($data['type']) as $snapshot) {
            dispatch($snapshots->job(
                $snapshot,
                $snapshots->refreshesWhole($data['type'], $snapshot) ? 'all' : $id,
                $data['action'],
                $data['public_id'] ?? null,
            ));
        }

        return response()->json(['status' => 'accepted'], 202);
    }

    /**
     * The ping's data, or a 403 when neither Core's key nor the shared secret opens it.
     *
     * @return array<string, mixed>
     */
    private static function ping(Request $request, PingSignature $signature): array
    {
        if (! $request->headers->has(CoreEnvelope::PAYLOAD_HEADER)) {
            $request->validate(['payload' => ['required', 'string'], 'signature' => ['required', 'string']]);

            return $signature->verify((string) $request->input('payload'), (string) $request->input('signature'));
        }

        try {
            CoreEnvelope::fromConfig()->openRequest($request, CoreEnvelope::OBJECTS);
        } catch (EnvelopeRefused|WrongEnvironment) {
            abort(403);
        }

        $data = json_decode((string) $request->getContent(), true);
        abort_unless(is_array($data), 403);

        return $data;
    }

    /** A bulk ping names `all`; a sender without that word sends id 0. */
    private static function id(mixed $id): string
    {
        $id = is_scalar($id) ? (string) $id : '';

        return $id === '0' ? 'all' : $id;
    }
}
