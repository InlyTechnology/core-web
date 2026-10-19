<?php

namespace Inly\CoreWeb\Content;

use RuntimeException;

/** An envelope that did not open, and the reason the Statamic connector API names for it. */
class EnvelopeRefused extends RuntimeException
{
    public const MISSING = 'missing';

    public const SIGNATURE = 'signature';

    public const PAYLOAD = 'payload';

    public const AUDIENCE = 'audience';

    public const EXPIRED = 'expired';

    public const PURPOSE = 'purpose';

    public const METHOD = 'method';

    public const PATH = 'path';

    public const QUERY = 'query';

    public const BODY = 'body';

    public const REPLAYED = 'replayed';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("The Core envelope was refused: {$reason}.");
    }

    /** Whether the signature verified before the refusal, so the caller has proved it holds Core's key and may hear the reason. */
    public function signatureVerified(): bool
    {
        return ! in_array($this->reason, [self::MISSING, self::SIGNATURE, self::PAYLOAD], true);
    }
}
