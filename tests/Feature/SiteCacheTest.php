<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotChange;
use Inly\CoreWeb\StaticCaching\SiteCache;
use Inly\CoreWeb\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Statamic\Facades\Site;
use Statamic\StaticCaching\Cachers\ApplicationCacher;

/**
 * Flushing one site's static cache: every page cached for it, on its host
 * and under its path, and none of another site's, whether the sites have
 * hosts of their own or share one, each under its own path; or only the
 * pages a snapshot change names, falling back to the whole site whenever
 * they are not known.
 */
class SiteCacheTest extends TestCase
{
    private ApplicationCacher $cacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacher = new ApplicationCacher(Cache::store('array'), []);
    }

    public function test_sites_on_their_own_hosts_flush_their_own_host(): void
    {
        $this->cache('http://default.test', ['/', '/a', '/other/b']);
        $this->cache('http://other.test', ['/', '/c']);

        (new SiteCache($this->cacher))->flush(Site::get('other'));

        $this->assertSame(['/', '/a', '/other/b'], $this->cached('http://default.test'));
        $this->assertSame([], $this->cached('http://other.test'));
    }

    public function test_sites_sharing_a_host_flush_their_own_path_only(): void
    {
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => 'http://shared.test/', 'locale' => 'sv_SE'],
            'other' => ['name' => 'Other', 'url' => 'http://shared.test/other/', 'locale' => 'sv_SE'],
            'third' => ['name' => 'Third', 'url' => 'http://shared.test/other-third', 'locale' => 'sv_SE'],
        ]);
        $this->cache('http://shared.test', ['/', '/a', '/otherwise', '/other', '/other/b?x=1', '/other-third/c']);

        (new SiteCache($this->cacher))->flush(Site::get('other'));
        $this->assertSame(['/', '/a', '/otherwise', '/other-third/c'], $this->cached('http://shared.test'));

        (new SiteCache($this->cacher))->flush(Site::get('default'));
        $this->assertSame(['/other-third/c'], $this->cached('http://shared.test'));
    }

    public function test_named_paths_flush_those_pages_with_their_query_strings_only(): void
    {
        $this->cache('http://default.test', ['/', '/campaigns', '/campaigns?brand=a', '/campaigns/a/one', '/campaigns/a/two', '/brands', '/brands/a', '/about']);

        $flushed = (new SiteCache($this->cacher))->flushPaths(Site::get('default'), ['/campaigns', 'brands/*', '/']);

        $this->assertSame(4, $flushed);
        $this->assertSame(['/campaigns/a/one', '/campaigns/a/two', '/brands', '/about'], $this->cached('http://default.test'));
    }

    public function test_named_paths_are_on_the_site_under_its_path_of_a_shared_host(): void
    {
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => 'http://shared.test/', 'locale' => 'sv_SE'],
            'other' => ['name' => 'Other', 'url' => 'http://shared.test/other/', 'locale' => 'sv_SE'],
        ]);
        $this->cache('http://shared.test', ['/', '/road', '/other', '/other/road', '/other/road/bike', '/other/sea']);

        (new SiteCache($this->cacher))->flushPaths(Site::get('other'), ['/', '/road/*']);

        $this->assertSame(['/', '/road', '/other/road', '/other/sea'], $this->cached('http://shared.test'));
    }

    public function test_a_change_flushes_the_pages_its_documents_name_and_the_snapshot_s_sitemap(): void
    {
        $this->app->instance(SiteCache::class, new SiteCache($this->cacher));
        $this->cache('http://default.test', ['/', '/campaigns', '/campaigns/one', '/campaigns/old-one', '/campaigns/two', '/sitemap-core-things.xml', '/sitemap-core-others.xml']);
        $snapshot = $this->things()->sitemap(fn (array $doc) => [['loc' => $doc['url']]]);

        $change = SnapshotChange::between($snapshot, 'default',
            ['one' => ['url' => '/campaigns/old-one'], 'two' => ['url' => '/campaigns/two']],
            ['one' => ['url' => '/campaigns/one'], 'two' => ['url' => '/campaigns/two']],
        );
        (SiteCache::flushOnChange())('default', [], $change);

        $this->assertSame(['/', '/campaigns/two', '/sitemap-core-others.xml'], $this->cached('http://default.test'), 'the old and the new page of the moved document, never the unchanged one');
    }

    /** @return iterable<string, array{\Closure(): ?SnapshotChange}> */
    public static function unknownChanges(): iterable
    {
        yield 'no change set (a first pull, a failed listener)' => [fn () => null];
        yield 'a snapshot that names no pages' => [fn () => SnapshotChange::between(Snapshot::make('plain'), 'default', [], ['a' => ['url' => '/a']])];
        yield 'a document whose pages are unknown' => [fn () => SnapshotChange::between(self::things(), 'default', [], ['a' => ['url' => '/a'], 'b' => ['url' => null]])];
        yield 'naming the pages fails' => [fn () => SnapshotChange::between(self::things()->dependents(fn () => throw new RuntimeException('no index')), 'default', [], ['a' => ['url' => '/a']])];
        yield 'more documents than a targeted flush takes' => [fn () => SnapshotChange::between(self::things(), 'default', [], array_map(fn (int $i) => ['url' => "/p{$i}"], array_combine(range(1, 21), range(1, 21))))];
    }

    /** @param  \Closure(): ?SnapshotChange  $change */
    #[DataProvider('unknownChanges')]
    public function test_a_change_whose_pages_are_not_known_flushes_the_whole_site(\Closure $change): void
    {
        $this->app->instance(SiteCache::class, new SiteCache($this->cacher));
        $this->cache('http://default.test', ['/', '/a', '/unrelated']);
        $this->cache('http://other.test', ['/']);

        (SiteCache::flushOnChange())('default', [], $change());

        $this->assertSame([], $this->cached('http://default.test'));
        $this->assertSame(['/'], $this->cached('http://other.test'));
    }

    public function test_targeted_flushing_can_be_switched_off(): void
    {
        config(['core-web.snapshots.targeted_flush.enabled' => false]);
        $this->app->instance(SiteCache::class, new SiteCache($this->cacher));
        $this->cache('http://default.test', ['/', '/a', '/unrelated']);

        (SiteCache::flushOnChange())('default', [], SnapshotChange::between(self::things(), 'default', [], ['a' => ['url' => '/a']]));

        $this->assertSame([], $this->cached('http://default.test'));
    }

    /** A snapshot whose document names its one page by `url`; a document without one names none it knows of. */
    private static function things(): Snapshot
    {
        return Snapshot::make('things')->dependents(fn (array $doc) => $doc['url'] === null ? null : ['/campaigns', $doc['url']]);
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
}
