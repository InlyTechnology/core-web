<?php

namespace Inly\CoreWeb\Content;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inly\CoreWeb\Drafts\ContentSchema;
use Inly\CoreWeb\Drafts\DraftableCollections;

/**
 * Core's reads of the content schema (Statamic connector API v1.1 `blocks`),
 * sealed for reading: every draftable collection's blocks and entry fields,
 * or one block in full. `catalog_hash` tells Core whether its copy is current.
 */
class CoreBlocksController
{
    public function index(Request $request, ContentSchema $schema): JsonResponse
    {
        $request->validate([
            'collection' => ['nullable', 'string', Rule::in(DraftableCollections::handles())],
        ]);

        return response()->json($schema->describe($request->filled('collection') ? (string) $request->query('collection') : null));
    }

    public function show(string $collection, string $handle, ContentSchema $schema): JsonResponse
    {
        $block = $schema->block($collection, $handle);

        if ($block === null) {
            return response()->json(['error' => 'not_found', 'message' => __('core-web::drafts.no_block')], 404);
        }

        return response()->json($block);
    }
}
