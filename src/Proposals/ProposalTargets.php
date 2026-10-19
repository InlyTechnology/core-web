<?php

namespace Inly\CoreWeb\Proposals;

use Inly\CoreWeb\Proposals\Targets\AssetTarget;
use Inly\CoreWeb\Proposals\Targets\CollectionTreeTarget;
use Inly\CoreWeb\Proposals\Targets\GlobalTarget;
use Inly\CoreWeb\Proposals\Targets\NavTarget;
use Inly\CoreWeb\Proposals\Targets\TermTarget;
use InvalidArgumentException;

/**
 * Reads a target's key back: `nav:{handle}:{site}`, `tree:{collection}:{site}`,
 * `global:{handle}:{site}`, `term:{taxonomy}:{slug}:{site}` or
 * `asset:{container}::{path}`.
 */
final class ProposalTargets
{
    /** @throws InvalidArgumentException for a key naming nothing */
    public static function resolve(string $key): ProposalTarget
    {
        [$type, $rest] = array_pad(explode(':', $key, 2), 2, '');

        if ($type === 'asset') {
            return AssetTarget::make($rest);
        }

        $parts = explode(':', $rest);

        return match (true) {
            $type === 'nav' && count($parts) === 2 => NavTarget::make(...$parts),
            $type === 'tree' && count($parts) === 2 => CollectionTreeTarget::make(...$parts),
            $type === 'global' && count($parts) === 2 => GlobalTarget::make(...$parts),
            $type === 'term' && count($parts) === 3 => TermTarget::make(...$parts),
            default => throw new InvalidArgumentException("[{$key}] is not a proposal target; see ProposalTargets."),
        };
    }
}
