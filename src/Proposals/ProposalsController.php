<?php

namespace Inly\CoreWeb\Proposals;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Inly\CoreWeb\Drafts\DraftRefused;
use Statamic\Facades\User;

/** The CP's Proposals: the open ones, each with what it changes, applied or discarded by an editor who may edit its target, and the applied ones that can still be undone. */
class ProposalsController
{
    public function index(Proposals $proposals): View
    {
        $user = User::current();
        $rows = collect($proposals->open())
            ->filter(fn (Proposal $proposal) => $proposals->target($proposal)?->mayEdit($user))
            ->map(fn (Proposal $proposal) => $proposals->describe($proposal))
            ->values()
            ->all();

        $applied = collect($proposals->undoableApplied())
            ->filter(fn (Proposal $proposal) => $proposals->target($proposal)?->mayEdit($user))
            ->map(fn (Proposal $proposal) => $proposals->describe($proposal, undoable: true))
            ->values()
            ->all();

        return view('core-web::proposals.index', ['proposals' => $rows, 'applied' => $applied, 'title' => __('Proposals')]);
    }

    public function show(string $id, Proposals $proposals): View
    {
        $proposal = $proposals->find($id);
        $target = $proposal ? $proposals->target($proposal) : null;

        abort_if($proposal === null || $target === null || ! $target->mayEdit(User::current()), 404);

        return view('core-web::proposals.show', [
            'proposal' => $proposals->describe($proposal),
            'changes' => $proposals->changes($target, $proposal->open() ? $target->current() : ($proposal->replaced ?? []), $proposal->value),
            'before' => $proposal->open() || $proposal->replaced !== null,
            'targetUrl' => $target->cpUrl(),
            'proposedAt' => $proposal->createdAt->setTimezone(config('app.timezone'))->format('Y-m-d H:i'),
            'title' => __('Proposal'),
        ]);
    }

    public function apply(string $id, Proposals $proposals): RedirectResponse
    {
        try {
            $proposals->apply($id, User::current());
        } catch (DraftRefused $refused) {
            return back()->with('error', $refused->getMessage());
        }

        return redirect(cp_route('core-web.proposals.index'))->with('success', __('Proposal applied'));
    }

    public function undo(string $id, Proposals $proposals): RedirectResponse
    {
        try {
            $proposals->undo($id, User::current());
        } catch (DraftRefused $refused) {
            return back()->with('error', $refused->getMessage());
        }

        return redirect(cp_route('core-web.proposals.index'))->with('success', __('Proposal undone'));
    }

    public function discard(string $id, Proposals $proposals): RedirectResponse
    {
        try {
            $proposals->discard($id, User::current());
        } catch (DraftRefused $refused) {
            return back()->with('error', $refused->getMessage());
        }

        return redirect(cp_route('core-web.proposals.index'))->with('success', __('Proposal discarded'));
    }
}
