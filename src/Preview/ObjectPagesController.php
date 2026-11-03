<?php

namespace Inly\CoreWeb\Preview;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Core's reads of the records the site serves a page for (Statamic connector API v1.5), sealed for reading. A
 * `not_found` tells Core to drop the record's addresses.
 */
class ObjectPagesController
{
    public function index(Request $request, ObjectPages $pages): JsonResponse
    {
        $request->validate([
            'cursor' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! ObjectPages::isCursor((string) $value)) {
                    $fail(__('core-web::drafts.bad_cursor'));
                }
            }],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.ObjectPages::MAX_LIMIT],
        ]);

        return response()->json($pages->page(
            $request->filled('cursor') ? (string) $request->query('cursor') : null,
            (int) ($request->query('limit') ?: ObjectPages::DEFAULT_LIMIT),
        ));
    }

    public function show(Request $request, ObjectPages $pages): JsonResponse
    {
        $input = $request->validate([
            'type' => ['required', 'string', 'max:128'],
            'id' => ['required', 'string', 'max:128'],
        ]);

        $record = $pages->find($input['type'], $input['id']);

        if ($record === null) {
            return response()->json(['error' => 'not_found', 'message' => __('core-web::drafts.no_page')], 404);
        }

        return response()->json(['data' => $record]);
    }
}
