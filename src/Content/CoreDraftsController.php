<?php

namespace Inly\CoreWeb\Content;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inly\CoreWeb\Drafts\ContentSchema;
use Inly\CoreWeb\Drafts\DraftRefused;
use Inly\CoreWeb\Drafts\DraftWriter;

/**
 * Core AI's drafts of the site's entries (Statamic connector API v1.1): a new
 * or updated draft written as the person's own CP user, the current draft of
 * an entry for Core to update, and the person's draft published when they ask
 * for it. A write is sealed for drafting, a publish for publishing.
 */
class CoreDraftsController
{
    public function store(Request $request, DraftWriter $writer, ContentSchema $schema): JsonResponse
    {
        $request->validate([
            'collection' => ['required', 'string', 'max:64'],
            'entry_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'site' => ['nullable', 'string', 'max:64'],
            'parent_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'blueprint' => ['nullable', 'string', 'max:64'],
            'dry_run' => ['sometimes', 'boolean'],
            'fields' => ['present', 'array'],
            'catalog_hash' => ['nullable', 'string', 'max:128'],
            'base_hash' => ['nullable', 'string', 'max:128'],
            ...CoreAiAuthor::rules(),
        ]);

        $this->assertSchema($schema, $request->input('catalog_hash'));

        $result = $writer->write(fn () => CoreAiAuthor::from($request), $request->only(['collection', 'entry_id', 'site', 'parent_id', 'blueprint', 'dry_run', 'fields', 'base_hash']));

        return response()->json(['data' => $result], filled($request->input('entry_id')) || $request->boolean('dry_run') ? 200 : 201);
    }

    public function show(string $id, DraftWriter $writer): JsonResponse
    {
        $draft = $writer->read($id);

        if ($draft === null) {
            return response()->json(['error' => 'not_found', 'message' => __('core-web::drafts.no_draft')], 404);
        }

        return response()->json(['data' => $draft]);
    }

    public function publish(string $id, Request $request, DraftWriter $writer): JsonResponse
    {
        $request->validate(CoreAiAuthor::rules());

        return response()->json(['data' => $writer->publish(fn () => CoreAiAuthor::from($request, 'publish'), $id)]);
    }

    private function assertSchema(ContentSchema $schema, mixed $hash): void
    {
        if (blank($hash)) {
            return;
        }

        $current = $schema->hash();

        if (! hash_equals($current, (string) $hash)) {
            throw DraftRefused::catalogChanged($current);
        }
    }
}
