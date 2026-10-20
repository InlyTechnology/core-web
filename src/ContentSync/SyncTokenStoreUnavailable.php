<?php

namespace Inly\CoreWeb\ContentSync;

use RuntimeException;

/** The token store cannot be read at all (no table, no database), as against a query that failed on a working one. */
final class SyncTokenStoreUnavailable extends RuntimeException {}
