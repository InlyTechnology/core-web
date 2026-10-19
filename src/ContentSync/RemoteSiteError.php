<?php

namespace Inly\CoreWeb\ContentSync;

use RuntimeException;

/** The remote site could not be reached or answered something a sync cannot use. */
class RemoteSiteError extends RuntimeException {}
