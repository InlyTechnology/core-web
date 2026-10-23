<?php

namespace Inly\CoreWeb\Drafts;

/**
 * What a source may build a draft from: every draftable collection's blocks
 * and entry fields, each with how its value is stored, served to Core at
 * `blocks` (core-web.drafts.schema). A draft written against an older `hash()`
 * is refused, so an AI never builds on blocks that changed under it.
 * StatamicContentSchema reads it from the blueprints; a site binds its own
 * subclass to change what it says.
 */
interface ContentSchema
{
    /**
     * Every draftable collection's blocks in brief and its blueprints' entry fields in full, or one collection's.
     *
     * @return array{catalog_hash: string, guide_index: array{path: string, anchor: ?string}|null, article_blocks: list<string>, page_blocks: list<string>, collections: list<array<string, mixed>>}
     */
    public function describe(?string $collection = null): array;

    /**
     * One block of a collection in full; null for a block the collection has no builder for.
     *
     * @return array{catalog_hash: string, data: array<string, mixed>}|null
     */
    public function block(string $collection, string $handle): ?array;

    /** Changes when what a draft may be built from does, and not when content does. */
    public function hash(): string;
}
