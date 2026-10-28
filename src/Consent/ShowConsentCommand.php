<?php

namespace Inly\CoreWeb\Consent;

use Illuminate\Console\Command;

class ShowConsentCommand extends Command
{
    protected $signature = 'consent:show {id? : The visitor\'s consent id, from the banner\'s consent details} {--json : Print the evidence as JSON}';

    protected $description = 'Show a visitor\'s recorded consent with the banner they chose on, or the totals per banner without an id';

    public function handle(): int
    {
        $id = $this->argument('id');

        if (! is_string($id) || $id === '') {
            return $this->totals();
        }

        $evidence = ConsentEvidence::forId($id);

        if ($this->option('json')) {
            $this->line(json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $evidence['choices'] === [] ? self::FAILURE : self::SUCCESS;
        }

        if ($evidence['choices'] === []) {
            $this->error("No consent is recorded under {$evidence['consent_id']}.");

            return self::FAILURE;
        }

        $this->table(['Chosen at', 'Site', 'Locale', 'Action', 'Categories', 'Version', 'Banner'], array_map(fn (array $choice): array => [
            $choice['chosen_at'],
            $choice['site'],
            $choice['locale'] ?? '',
            $choice['action'],
            implode(', ', ['necessary', ...$choice['categories']]),
            $choice['policy_version'],
            $choice['fingerprint'] ? substr($choice['fingerprint'], 0, 12) : 'not recorded',
        ], $evidence['choices']));

        foreach ($evidence['banners'] as $fingerprint => $banner) {
            $this->newLine();
            $this->info('Banner '.substr($fingerprint, 0, 12));

            if ($banner === null) {
                $this->warn('No snapshot is kept for this banner: nobody chose on it while it was current.');

                continue;
            }

            $this->line("{$banner['texts']['title']}\n{$banner['texts']['text']}");

            foreach ($banner['categories'] as $category => $vendors) {
                $this->line("  {$banner['texts']['categories'][$category]['title']}: ".implode(', ', array_map(
                    fn (array $vendor): string => $vendor['name'].' ('.implode(', ', array_column($vendor['cookies'], 'name')).')',
                    $vendors,
                )));
            }
        }

        $this->newLine();
        $this->line('The whole banner, every text and cookie included: --json.');

        return self::SUCCESS;
    }

    private function totals(): int
    {
        $this->table(['Site', 'Version', 'Banner', 'Accepted all', 'Rejected all', 'Chose', 'First', 'Last'], array_map(fn (array $row): array => [
            $row['site'],
            $row['policy_version'],
            $row['fingerprint'] ? substr($row['fingerprint'], 0, 12) : 'not recorded',
            $row['accept_all'],
            $row['reject_all'],
            $row['custom'],
            $row['first'],
            $row['last'],
        ], ConsentEvidence::totals()));

        return self::SUCCESS;
    }
}
