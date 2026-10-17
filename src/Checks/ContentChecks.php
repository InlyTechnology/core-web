<?php

namespace Inly\CoreWeb\Checks;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\DraftRefused;
use InvalidArgumentException;
use Throwable;

/**
 * The site's checks (core-web.checks.checks): run on demand or nightly, the
 * latest findings of each kept on a filesystem disk for the CP widget and
 * for sources to read without running a check themselves.
 */
class ContentChecks
{
    public function __construct(private Filesystem $disk, private string $path) {}

    public static function fromConfig(): self
    {
        return new self(
            Storage::disk(config('core-web.checks.disk') ?: null),
            trim((string) config('core-web.checks.path', 'core-web/checks'), '/'),
        );
    }

    /** @return array<string, Check> by key */
    public function all(): array
    {
        $checks = [];

        foreach ((array) config('core-web.checks.checks', []) as $class) {
            $check = app($class);

            if (! $check instanceof Check) {
                throw new InvalidArgumentException("core-web.checks.checks: [{$class}] is not a ".Check::class.'.');
            }

            $checks[$check->key()] = $check;
        }

        return $checks;
    }

    public function get(string $key): ?Check
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Runs a check and keeps its findings as the latest.
     *
     * @return array{check: string, ran_at: string, findings: list<array<string, mixed>>, error?: string}
     */
    public function run(Check $check): array
    {
        try {
            $findings = array_map(fn (Finding $finding) => $finding->toArray(), [...$check->detect()]);
            $result = ['check' => $check->key(), 'ran_at' => Carbon::now()->utc()->toIso8601String(), 'findings' => $findings];
        } catch (Throwable $e) {
            report($e);
            $result = ['check' => $check->key(), 'ran_at' => Carbon::now()->utc()->toIso8601String(), 'findings' => [], 'error' => $e->getMessage()];
        }

        $this->disk->put($this->file($check->key()), json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $result;
    }

    /** @return array{check: string, ran_at: string, findings: list<array<string, mixed>>, error?: string}|null */
    public function latest(string $key): ?array
    {
        if (! $this->disk->exists($file = $this->file($key))) {
            return null;
        }

        return json_decode((string) $this->disk->get($file), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Fixes one finding of the latest run, found afresh first so a fix never
     * works from a stale finding.
     *
     * @return array<string, mixed>
     *
     * @throws DraftRefused
     */
    public function fix(string $key, string $findingId, DraftAuthor $author): array
    {
        // A fix is a draft or a proposal, both of which belong to the drafts feature.
        if (! CoreWebServiceProvider::enabled('drafts')) {
            throw new DraftRefused('not_fixable', __('core-web::drafts.fixes_need_drafts'), status: 422);
        }

        $check = $this->get($key) ?? throw new DraftRefused('not_found', __('core-web::drafts.no_check', ['check' => $key]), status: 404);
        $known = collect($this->latest($key)['findings'] ?? [])->firstWhere('id', $findingId);

        // The last run already knows the finding: refuse what cannot be fixed without reading anything, and re-read only its subject.
        if (is_array($known) && ! ($known['fixable'] ?? false)) {
            throw new DraftRefused('not_fixable', __('core-web::drafts.not_fixable', ['check' => $key]), status: 422);
        }

        $detect = fn () => collect([...$check->detect()])->first(fn (Finding $finding) => $finding->id() === $findingId);
        $finding = is_array($known) && is_string($known['subject'] ?? null) ? ContentWalk::scoped($known['subject'], $detect) : $detect();

        if ($finding === null) {
            throw new DraftRefused('finding_gone', __('core-web::drafts.finding_gone'), status: 404);
        }

        if (! $finding->fixable) {
            throw new DraftRefused('not_fixable', __('core-web::drafts.not_fixable', ['check' => $key]), status: 422);
        }

        return $check->fix($finding, $author);
    }

    private function file(string $key): string
    {
        return "{$this->path}/{$key}.json";
    }
}
