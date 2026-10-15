<?php

namespace Inly\CoreWeb\Content;

use RuntimeException;

/** A draft or publish call that opened but came from a Core in another environment than the site's, or one that names none. */
class WrongEnvironment extends RuntimeException
{
    public function __construct(public readonly ?string $claimed, public readonly string $expected)
    {
        parent::__construct($claimed === null
            ? "This site runs in {$expected} and takes drafts only from a Core that names its environment."
            : "This site runs in {$expected} and takes no drafts from a Core in {$claimed}.");
    }
}
