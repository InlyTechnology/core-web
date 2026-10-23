<?php

namespace Inly\CoreWeb\Drafts;

/**
 * What a site tells Core a draft may be built from (a block catalog), when
 * it has one: a site binds it, and a draft written against an older hash is
 * refused so the AI never builds on blocks that changed under it.
 */
interface DraftCatalog
{
    public function hash(): string;
}
