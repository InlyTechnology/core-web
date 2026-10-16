<?php

namespace Inly\CoreWeb\Live;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The browser's way to a public() live read: the query string is the input, the answer is the LiveResult as JSON. */
class LiveController
{
    public function __invoke(Request $request, CoreLive $live, string $name): JsonResponse
    {
        // Answered here, not with abort(): a site's 404 page renders through its layout, which a JSON endpoint has no use for.
        $result = $live->isPublic($name) ? $live->read($name, $request->query()) : LiveResult::notFound();
        $cache = $result->successful() && $result->ttl > 0 ? "private, max-age={$result->ttl}" : 'no-store, private';

        return response()->json($result->toArray(), $result->httpStatus())->header('Cache-Control', $cache);
    }
}
