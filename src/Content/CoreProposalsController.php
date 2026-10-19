<?php

namespace Inly\CoreWeb\Content;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inly\CoreWeb\Proposals\Proposals;
use Inly\CoreWeb\Proposals\ProposalTargets;
use InvalidArgumentException;

/**
 * Proposals as Core reads and makes them (Statamic connector API v1.2): a
 * target's value and base to build on, a proposal for an editor to apply, and what became
 * of it.
 */
class CoreProposalsController
{
    public function target(Request $request, Proposals $proposals): JsonResponse
    {
        $request->validate(['key' => ['required', 'string', 'max:300']]);

        try {
            $target = ProposalTargets::resolve((string) $request->input('key'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'not_found', 'message' => $e->getMessage()], 404);
        }

        $open = collect($proposals->open())->first(fn ($proposal) => $proposal->target === $target->key());

        return response()->json(['data' => [
            'key' => $target->key(),
            'label' => $target->label(),
            'value' => $target->current(),
            'base' => Proposals::base($target),
            'open_proposal' => $open ? $proposals->describe($open) : null,
            'cp_url' => $target->cpUrl(),
        ]]);
    }

    public function store(Request $request, Proposals $proposals): JsonResponse
    {
        $request->validate([
            'target' => ['required', 'string', 'max:300'],
            'value' => ['present', 'array'],
            'base' => ['required', 'string', 'max:128'],
            'dry_run' => ['sometimes', 'boolean'],
            ...CoreAiAuthor::rules(),
        ]);

        try {
            $target = ProposalTargets::resolve((string) $request->input('target'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'not_found', 'message' => $e->getMessage()], 404);
        }

        $result = $proposals->propose(CoreAiAuthor::from($request, 'proposal'), $target, (array) $request->input('value'), $request->input('base'), $request->boolean('dry_run'));

        return response()->json(['data' => $result], $request->boolean('dry_run') ? 200 : 201);
    }

    public function show(string $id, Proposals $proposals): JsonResponse
    {
        $proposal = $proposals->find($id);

        if ($proposal === null) {
            return response()->json(['error' => 'not_found', 'message' => __('core-web::drafts.no_proposal')], 404);
        }

        $target = $proposals->target($proposal);

        return response()->json(['data' => [
            ...$proposals->describe($proposal),
            'value' => $proposal->value,
            'changed' => $target && $proposal->open() ? $proposals->changes($target, $target->current(), $proposal->value) : null,
        ]]);
    }
}
