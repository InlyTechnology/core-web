<?php

namespace Inly\CoreWeb\Checks;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\DraftProvenance;
use Inly\CoreWeb\Drafts\DraftRefused;
use Statamic\Facades\User;
use Throwable;

/** Runs the site's content checks and keeps their findings for the CP widget and for sources to read. */
class ContentCheckCommand extends Command
{
    protected $signature = 'content:check {check?* : The checks to run, by key (every one when none)} {--json : Print the findings as JSON} {--fix : Fix what can be fixed, as drafts and proposals an editor publishes} {--as= : The email of the CP user the fixes are made as}';

    protected $description = 'Find links that will rot, references to deleted content and pages search engines read badly';

    public function handle(ContentChecks $checks): int
    {
        $all = $checks->all();
        $keys = $this->argument('check') ?: array_keys($all);
        $unknown = array_diff($keys, array_keys($all));

        if ($unknown !== []) {
            $this->error('No such check: '.implode(', ', $unknown).'. The checks are: '.implode(', ', array_keys($all)).'.');

            return self::FAILURE;
        }

        $author = null;

        if ($this->option('fix')) {
            $user = filled($this->option('as')) ? User::findByEmail((string) $this->option('as')) : null;

            if ($user === null) {
                $this->error('--fix makes drafts and proposals as a CP user: name one with --as=<email>.');

                return self::FAILURE;
            }

            if ($this->option('json')) {
                $this->error('--fix prints what it made, not findings: leave out --json.');

                return self::FAILURE;
            }

            $author = new DraftAuthor($user, (string) ($user->name() ?: $user->email()), DraftProvenance::make('content-check'), 'Fixed by content:check');
        }

        $results = array_map(fn (string $key) => $checks->run($all[$key]), $keys);

        if ($author !== null) {
            return $this->fix($checks, $results, $author);
        }

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        foreach ($results as $result) {
            if (isset($result['error'])) {
                $this->error("{$result['check']}: failed: {$result['error']}");

                continue;
            }

            $this->info("{$result['check']}: ".count($result['findings']).' found');

            foreach ($result['findings'] as $finding) {
                $this->line("  {$finding['label']} [{$finding['path']}] {$finding['message']}".($finding['fixable'] ? ' (fixable)' : ''));
            }
        }

        return collect($results)->contains(fn (array $result) => isset($result['error'])) ? self::FAILURE : self::SUCCESS;
    }

    /** @param  list<array<string, mixed>>  $results */
    private function fix(ContentChecks $checks, array $results, DraftAuthor $author): int
    {
        $failed = false;
        $fixed = [];

        foreach ($results as $result) {
            // A check fixes everything a subject holds at once (a page's links, a menu), so one finding per subject is enough.
            foreach (collect($result['findings'])->where('fixable', true)->unique('subject') as $finding) {
                // A second check's draft or proposal for the same page or menu would replace the first one's, built without its fix.
                if (isset($fixed[$finding['subject']])) {
                    $this->warn("{$result['check']}: {$finding['label']}: skipped, {$fixed[$finding['subject']]} fixed it in this run; run again once that is applied.");

                    continue;
                }

                try {
                    $made = $checks->fix($result['check'], $finding['id'], $author);
                    $fixed[$finding['subject']] = $result['check'];
                    $this->info("{$result['check']}: {$finding['label']}: ".($made['cp_url'] ?? 'fixed'));
                } catch (DraftRefused|ValidationException $refused) {
                    $failed = true;
                    $this->error("{$result['check']}: {$finding['label']}: {$refused->getMessage()}");
                } catch (Throwable $e) {
                    report($e);
                    $failed = true;
                    $this->error("{$result['check']}: {$finding['label']}: failed: {$e->getMessage()}");
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
