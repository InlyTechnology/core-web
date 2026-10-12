<?php

namespace Inly\CoreWeb\Tests\Feature;

use Inly\CoreWeb\Sitemaps\SnapshotSitemaps;
use Inly\CoreWeb\Sitemaps\SnapshotSitemapsTag;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\Tests\TestCase;
use RuntimeException;
use Statamic\Facades\Site;
use Statamic\Tags\Tags;

/**
 * /sitemap-core-{type}.xml: the pages a snapshot's documents have on the site
 * of the host, from that site's partition, and the {{ core_sitemaps }} tag
 * that lists the ones with URLs.
 */
class SnapshotSitemapTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $documents = [
        'a' => ['id' => 'a', 'site' => 'default', 'updated_at' => '2026-09-28T09:59:01Z', 'image' => 'https://cdn.test/a.webp'],
        'b' => ['id' => 'b', 'site' => 'other', 'updated_at' => null],
        'c' => ['id' => 'c', 'site' => 'other', 'updated_at' => 'not a date', 'hidden' => true],
    ];

    private function register(Snapshot $snapshot): Snapshot
    {
        app(SnapshotRegistry::class)->register($snapshot);

        return $snapshot;
    }

    private function things(): Snapshot
    {
        return Snapshot::make('things')
            ->pull(fn () => $this->documents)
            ->partitionBy(fn (array $doc) => $doc['site'], ['default', 'other'])
            ->sitemap(fn (array $doc) => ($doc['hidden'] ?? false) ? [] : [[
                'loc' => '/things/'.$doc['id'],
                'lastmod' => $doc['updated_at'],
                'images' => array_filter([$doc['image'] ?? null, '']),
            ]], index: fn (string $site, array $documents) => ['loc' => '/things', 'lastmod' => null]);
    }

    public function test_each_host_lists_its_own_partition_with_lastmod_and_images(): void
    {
        $this->register($this->things());

        $default = $this->get('http://default.test/sitemap-core-things.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->getContent();

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $default);
        $this->assertNotFalse(simplexml_load_string($default));
        $this->assertStringContainsString('<loc>http://default.test/things</loc>', $default);
        $this->assertStringContainsString('<loc>http://default.test/things/a</loc>', $default);
        $this->assertStringContainsString('<lastmod>2026-09-28T09:59:01+00:00</lastmod>', $default);
        $this->assertStringContainsString('xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"', $default);
        $this->assertStringContainsString('<image:loc>https://cdn.test/a.webp</image:loc>', $default);
        $this->assertStringNotContainsString('/things/b', $default);

        $other = $this->get('http://other.test/sitemap-core-things.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<loc>http://other.test/things/b</loc>', $other);
        $this->assertStringNotContainsString('/things/c', $other, 'the closure decides what is live');
        $this->assertStringNotContainsString('<lastmod>', $other);
        $this->assertStringNotContainsString('xmlns:image', $other);
    }

    public function test_a_site_under_a_path_of_a_shared_host_has_its_sitemap_under_that_path(): void
    {
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => 'http://default.test/', 'locale' => 'sv_SE'],
            'other' => ['name' => 'Other', 'url' => 'http://default.test/other/', 'locale' => 'sv_SE'],
        ]);
        $this->register($this->things());

        $other = $this->get('http://default.test/other/sitemap-core-things.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<loc>http://default.test/other/things/b</loc>', $other);
        $this->assertStringNotContainsString('/things/a', $other);

        $default = $this->get('http://default.test/sitemap-core-things.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<loc>http://default.test/things/a</loc>', $default);
        $this->assertStringNotContainsString('/things/b', $default);

        // Only at a site's own path.
        $this->get('http://default.test/elsewhere/sitemap-core-things.xml')->assertNotFound();
        $this->get('http://default.test/other/deeper/sitemap-core-things.xml')->assertNotFound();

        $this->assertSame([['type' => 'things', 'url' => 'http://default.test/other/sitemap-core-things.xml']], self::tag(['site' => 'other'])->index());
    }

    public function test_an_absolute_loc_is_kept(): void
    {
        $this->register(Snapshot::make('links')->pull(fn () => ['x' => ['id' => 'x']])->sitemap(fn () => [['loc' => 'https://elsewhere.test/x']]));

        $this->get('http://default.test/sitemap-core-links.xml')->assertOk()->assertSee('<loc>https://elsewhere.test/x</loc>', false);
    }

    public function test_a_type_without_a_sitemap_or_urls_on_the_site_is_a_404(): void
    {
        $this->register(Snapshot::make('plain')->pull(fn () => ['a' => ['id' => 'a']]));
        $this->register(Snapshot::make('car-only')->pull(fn () => ['a' => ['id' => 'a']])->sitemap(fn () => [['loc' => '/a']], sites: ['default']));
        $this->register(Snapshot::make('empty')->pull(fn () => ['a' => ['id' => 'a']])->sitemap(fn () => []));

        $this->get('http://default.test/sitemap-core-nope.xml')->assertNotFound();
        $this->get('http://default.test/sitemap-core-plain.xml')->assertNotFound();
        $this->get('http://other.test/sitemap-core-car-only.xml')->assertNotFound();
        $this->get('http://default.test/sitemap-core-empty.xml')->assertNotFound();
        $this->get('http://default.test/sitemap-core-car-only.xml')->assertOk();
    }

    public function test_a_sitemap_is_503_while_nothing_can_be_read(): void
    {
        $this->register(Snapshot::make('down')->pull(fn () => throw new RuntimeException('down'))->sitemap(fn () => [['loc' => '/a']]));

        $this->get('http://default.test/sitemap-core-down.xml')->assertStatus(503);
    }

    public function test_a_warm_sitemap_pulls_nothing(): void
    {
        $pulls = 0;
        $snapshot = $this->register(Snapshot::make('warm')->pull(function () use (&$pulls) {
            $pulls++;

            return ['a' => ['id' => 'a']];
        })->sitemap(fn () => [['loc' => '/a']]));
        app(SnapshotStore::class)->refresh($snapshot);
        $this->app->forgetScopedInstances();

        $this->get('http://default.test/sitemap-core-warm.xml')->assertOk();

        $this->assertSame(1, $pulls);
    }

    public function test_the_tag_lists_the_types_with_urls_on_the_site_stopping_at_the_first(): void
    {
        $calls = 0;
        $this->register($this->things());
        $this->register(Snapshot::make('widgets')
            ->pull(fn () => ['w1' => ['id' => 'w1'], 'w2' => ['id' => 'w2']])
            ->sitemap(function (array $doc) use (&$calls) {
                $calls++;

                return [['loc' => '/widgets/'.$doc['id']]];
            }, sites: ['other']));

        $this->assertSame(['things'], app(SnapshotSitemaps::class)->types('default'));
        $this->assertSame(['things', 'widgets'], app(SnapshotSitemaps::class)->types('other'));
        $this->assertSame(1, $calls, 'robots.txt never builds a whole list');

        $this->assertSame([
            ['type' => 'things', 'url' => 'http://other.test/sitemap-core-things.xml'],
            ['type' => 'widgets', 'url' => 'http://other.test/sitemap-core-widgets.xml'],
        ], self::tag(['site' => 'other'])->index());
        $this->assertSame(['things'], array_column(self::tag()->index(), 'type'), 'the current site without a site param');
        $this->assertSame(SnapshotSitemapsTag::class, app('statamic.extensions')[Tags::class]['core_sitemaps']);
    }

    public function test_the_route_is_named(): void
    {
        $this->assertSame(url('sitemap-core-things.xml'), route('core.sitemap', 'things'));
    }

    /** @param  array<string, string>  $params */
    private static function tag(array $params = []): SnapshotSitemapsTag
    {
        $tag = new SnapshotSitemapsTag;
        $tag->setProperties(['parser' => null, 'content' => '', 'context' => [], 'params' => $params, 'tag' => 'core_sitemaps', 'tag_method' => 'index']);

        return $tag;
    }
}
