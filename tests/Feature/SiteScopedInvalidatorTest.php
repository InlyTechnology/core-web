<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Inly\CoreWeb\StaticCaching\SiteScopedInvalidator;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Console\Commands\StaticWarmJob;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\StaticCaching\Cachers\ApplicationCacher;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/**
 * A save on one site of a multisite install under the `all` rule: that
 * site's pages flushed, or with background_recache the saved page forgotten
 * at once and the site's other pages rendered again in the background,
 * never another site's.
 */
class SiteScopedInvalidatorTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    private ApplicationCacher $cacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacher = new ApplicationCacher(Cache::store('array'), []);
        Collection::make('pages')->sites(['default', 'other'])->routes('/{slug}')->save();
    }

    public function test_a_save_flushes_its_own_site_only(): void
    {
        $this->cacheBothSites();

        (new SiteScopedInvalidator($this->cacher, 'all'))->refresh($this->entry('a'));

        $this->assertSame([], $this->cached('http://default.test'));
        $this->assertSame(['/', '/a'], $this->cached('http://other.test'));
    }

    public function test_with_background_recache_the_saved_page_goes_at_once_and_its_site_is_refreshed(): void
    {
        config(['statamic.static_caching.background_recache' => true]);
        $this->cacheBothSites();
        $entry = $this->entry('a');
        Queue::fake();

        (new SiteScopedInvalidator($this->cacher, 'all'))->refresh($entry);

        $this->assertSame(['/', '/b'], $this->cached('http://default.test'), 'the other pages served as cached until rendered again');
        $this->assertSame(['/', '/a'], $this->cached('http://other.test'));
        $this->assertSame(['http://default.test/', 'http://default.test/b'], $this->warmed());
    }

    public function test_other_rules_keep_statamic_s_own_invalidation(): void
    {
        $this->cacheBothSites();

        (new SiteScopedInvalidator($this->cacher, ['collections' => ['pages' => ['urls' => ['/b']]]]))->refresh($this->entry('a'));

        $this->assertSame(['/'], $this->cached('http://default.test'));
        $this->assertSame(['/', '/a'], $this->cached('http://other.test'));
    }

    private function entry(string $slug): \Statamic\Contracts\Entries\Entry
    {
        $entry = Entry::make()->collection('pages')->locale('default')->slug($slug)->data(['title' => $slug]);
        $entry->save();

        return $entry;
    }

    private function cacheBothSites(): void
    {
        $this->cache('http://default.test', ['/', '/a', '/b']);
        $this->cache('http://other.test', ['/', '/a']);
    }

    /** @param  list<string>  $paths */
    private function cache(string $domain, array $paths): void
    {
        foreach ($paths as $path) {
            $this->cacher->cacheUrl(md5($domain.$path), $path, $domain);
        }
    }

    /** @return list<string> */
    private function cached(string $domain): array
    {
        return $this->cacher->getUrls($domain)->values()->all();
    }

    /** @return list<string> the addresses queued to be rendered again, without the recache token */
    private function warmed(): array
    {
        return Queue::pushed(StaticWarmJob::class)
            ->map(fn (StaticWarmJob $job) => preg_replace('/[?&]__recache=[^&]*$/', '', (string) $job->request->getUri()))
            ->values()
            ->all();
    }
}
