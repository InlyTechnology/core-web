<?php

namespace Inly\CoreWeb\CmsSignIn;

use RuntimeException;

/** Core cannot be asked to reconcile yet: nothing is known, so nothing changes, and it is no failure. */
class ReconcileUnavailable extends RuntimeException {}
