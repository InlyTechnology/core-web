<?php

namespace Inly\CoreWeb\Translation;

use RuntimeException;

/** A field the editor asked to translate that cannot be, with the reason to show them. */
final class FieldNotTranslatable extends RuntimeException {}
