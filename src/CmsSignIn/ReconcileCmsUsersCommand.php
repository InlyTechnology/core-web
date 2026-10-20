<?php

namespace Inly\CoreWeb\CmsSignIn;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;
use RuntimeException;
use Statamic\Contracts\Auth\User as UserContract;

/**
 * The sweep behind the offboarding notice: asks Core about every CP account it
 * provisioned and drops what Core no longer backs: deleted when the person is
 * gone from Core (as the notice does), stripped of Core's grant when they are
 * still there without CMS access (as a refused hand-off does), and stripped of
 * super when they keep access but are no longer an admin. An ask, never a
 * list: Core only answers for addresses sent to it. Unstamped accounts are
 * never asked about or touched (they cannot sign in), and every verdict must
 * be complete before anything changes.
 */
class ReconcileCmsUsersCommand extends Command
{
    protected $signature = 'core:reconcile-cms-users {--dry-run : Report what would change without changing it}';

    protected $description = 'Remove or strip the CP accounts Core provisioned that Core no longer backs';

    public function handle(CmsHandoff $handoff, CmsUsers $users): int
    {
        if (! $handoff->canReconcile()) {
            $this->warn('Core or its client is not configured; nothing reconciled.');

            return self::SUCCESS;
        }

        // Grouped, not keyed: two accounts may differ only in the case of their email.
        $byEmail = $users->provisioned()->groupBy(fn (UserContract $user): string => mb_strtolower(trim((string) $user->email())));

        if ($byEmail->isEmpty()) {
            $this->info('No Core-provisioned CP accounts to reconcile.');

            return self::SUCCESS;
        }

        $malformed = $byEmail->keys()->reject(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false);

        if ($malformed->isNotEmpty()) {
            $this->error('Core cannot be asked about these accounts (no usable email), aborting: '.$malformed->map(fn ($e) => $e === '' ? '(blank)' : $e)->implode(', '));

            return self::FAILURE;
        }

        try {
            $verdicts = $this->askCore($handoff, $byEmail->keys());
        } catch (ReconcileUnavailable $e) {
            $this->warn($e->getMessage().' Nothing reconciled.');

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $deleted = 0;
        $revoked = 0;
        $demoted = 0;

        foreach ($byEmail as $email => $accounts) {
            $verdict = $verdicts[$email];

            foreach ($accounts as $user) {
                if (! $verdict['exists']) {
                    $deleted++;
                    $this->line("DELETE  {$email} — no longer in Core");

                    if (! $dryRun) {
                        $user->delete();
                    }

                    continue;
                }

                if (! $verdict['cms_access']) {
                    if (! $users->holdsCoreGrant($user)) {
                        continue;
                    }

                    $revoked++;
                    $this->line("REVOKE  {$email} — in Core, without CMS access");

                    if (! $dryRun) {
                        $users->revokeAccount($user);
                    }

                    continue;
                }

                if ($verdict['is_admin'] || ! $user->isSuper()) {
                    continue;
                }

                $demoted++;
                $this->line("DEMOTE  {$email} — in Core, no longer an admin");

                if (! $dryRun) {
                    $users->demote($user);
                }
            }
        }

        $checked = $byEmail->flatten()->count();
        $this->info("Checked {$checked} Core-provisioned CP accounts: {$deleted} deleted, {$revoked} revoked, {$demoted} demoted.".($dryRun ? ' (dry run — nothing changed)' : ''));

        return self::SUCCESS;
    }

    /**
     * Core allows one reconcile a minute, so batches past the first wait for
     * the window to reopen.
     *
     * @param  Collection<int, string>  $emails
     * @return array<string, array{exists: bool, cms_access: bool, is_admin: bool}>
     */
    private function askCore(CmsHandoff $handoff, Collection $emails): array
    {
        $verdicts = [];

        foreach ($emails->chunk(CmsHandoff::RECONCILE_BATCH)->values() as $i => $batch) {
            if ($i > 0) {
                Sleep::for(61)->seconds();
            }

            $verdicts += $handoff->reconcile($batch->values()->all());
        }

        return $verdicts;
    }
}
