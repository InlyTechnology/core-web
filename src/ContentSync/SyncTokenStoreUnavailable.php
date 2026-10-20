<?php

namespace Inly\CoreWeb\ContentSync;

use RuntimeException;
use Throwable;

/** The token store cannot be read at all (no table, no database), as against a query that failed on a working one. */
final class SyncTokenStoreUnavailable extends RuntimeException
{
    public const TABLE_MISSING = 'table_missing';

    public const DATABASE_UNREACHABLE = 'database_unreachable';

    public function __construct(string $message, public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
