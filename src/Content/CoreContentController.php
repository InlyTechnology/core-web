<?php

namespace Inly\CoreWeb\Content;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inly\CoreWeb\Content\Feed\ContentFeed;
use Inly\CoreWeb\Content\Feed\ContentProjection;

/**
 * Core's reads of the site's pages (Statamic connector API `content`), sealed for reading: the paged feed its full
 * and incremental syncs walk, and one page after a ping. A `not_found` tells Core to drop its copy.
 */
class CoreContentController
{
    public function index(Request $request, ContentFeed $feed): JsonResponse
    {
        $request->validate([
            'updated_since' => ['nullable', 'date'],
            'cursor' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! ContentFeed::isCursor((string) $value)) {
                    $fail(__('core-web::drafts.bad_cursor'));
                }
            }],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.ContentFeed::MAX_LIMIT],
        ]);

        return response()->json($feed->page(
            $request->filled('updated_since') ? Carbon::parse((string) $request->query('updated_since')) : null,
            $request->filled('cursor') ? (string) $request->query('cursor') : null,
            (int) ($request->query('limit') ?: ContentFeed::DEFAULT_LIMIT),
        ));
    }

    public function show(string $id, ContentFeed $feed, ContentProjection $projection): JsonResponse
    {
        $entry = $feed->find($id);

        if ($entry === null) {
            return response()->json(['error' => 'not_found', 'message' => __('core-web::drafts.no_page')], 404);
        }

        return response()->json(['data' => $projection->project($entry)]);
    }
}
