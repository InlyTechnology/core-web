<?php

namespace Inly\CoreWeb\Tests\Feature;

use Inly\CoreWeb\Support\Assets;
use Inly\CoreWeb\Support\Links;
use Inly\CoreWeb\Support\Values;
use Inly\CoreWeb\Tests\TestCase;
use Mockery;
use RuntimeException;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Fields\ArrayableString;
use Statamic\Fields\Value;
use Statamic\Fieldtypes\Link\ArrayableLink;
use Statamic\Query\OrderedQueryBuilder;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/** The shapes one Statamic field reads back as, normalised the same way on every site. */
class SupportHelpersTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    /** @return list<\Statamic\Contracts\Entries\Entry> */
    private function entries(string ...$ids): array
    {
        Collection::make('pages')->routes('/{slug}')->save();

        return array_map(function (string $id) {
            $entry = Entry::make()->id($id)->collection('pages')->slug($id)->data(['title' => $id]);
            $entry->save();

            return $entry;
        }, $ids);
    }

    public function test_unwrap_reaches_what_nested_values_hold(): void
    {
        $this->assertSame('x', Values::unwrap(new Value(new Value(new ArrayableString('x')))));
        $this->assertSame(['a' => 1], Values::unwrap(new Value(['a' => 1])));
        $this->assertNull(Values::unwrap(new Value(null)));
        $this->assertSame(3, Values::unwrap(3));
    }

    public function test_every_relation_shape_collects_to_a_flat_list(): void
    {
        [$a, $b] = $this->entries('a', 'b');

        $this->assertSame([$a, $b], Values::collect(new Value(collect([$a, null, $b])))->all());
        $this->assertSame([$a, $b], Values::collect([$a, new Value($b), ''])->all());
        $this->assertSame([$a], Values::collect($a)->all());
        $this->assertSame([], Values::collect(new Value(null))->all());
        $this->assertSame([], Values::collect([])->all());
        $this->assertSame([$a, $b], Values::collect([0 => $a, 2 => $b])->all(), 'a list with an invalid pick dropped is still a list');
        $this->assertSame(['id' => 'x'], Values::first(['id' => 'x']), 'one record as an array is one item, not a list');
        $this->assertSame('https://example.com/a.jpg', Values::first('https://example.com/a.jpg'));
        $this->assertNull(Values::first(false));
        $this->assertSame('https://example.com/b.jpg', Values::first(['', 'https://example.com/b.jpg']), 'an empty slot is skipped');
    }

    public function test_first_keeps_the_editors_order_of_an_ordered_query(): void
    {
        [$a, $b, $c] = $this->entries('a', 'b', 'c');

        $query = new OrderedQueryBuilder(Entry::query()->whereIn('id', ['a', 'b', 'c']), ['c', 'a', 'b']);

        $this->assertSame('c', Values::first(new Value($query))->id());
        $this->assertSame(['c', 'a', 'b'], Values::collect($query)->map->id()->all());
    }

    public function test_links_resolve_every_shape_and_carry_a_suffix(): void
    {
        [$a] = $this->entries('a');

        $this->assertSame('/a', Links::url(new ArrayableLink($a)));
        $this->assertSame('/a?filter=x', Links::url(new Value(new ArrayableLink($a)), 'filter=x'));
        $this->assertSame('/a#contact', Links::url($a, '#contact'));
        $this->assertSame('https://example.com/?q=1&page=2#top', Links::url('https://example.com/?q=1#top', '&page=2'));
        $this->assertSame('mailto:a@example.com', Links::url(new ArrayableLink('mailto:a@example.com')));
        $this->assertNull(Links::url(null));
        $this->assertNull(Links::url(new ArrayableLink(null)));
        $this->assertSame('/a', Links::withSuffix('/a', new Value('  ')));
        $this->assertSame('/a', Links::withSuffix('/a', '?'));
        $this->assertSame('/a#x', Links::withSuffix('/a#top', '#x'));
        $this->assertSame('/a?filter=x#s', Links::withSuffix('/a#top', 'filter=x#s'));
        $this->assertStringStartsWith('http', Links::url(new Value(new ArrayableLink($a, ['select_across_sites' => true]))), 'a cross-site link stays absolute');
    }

    public function test_asset_meta_never_takes_the_page_down(): void
    {
        $healthy = Mockery::mock(Asset::class);
        $healthy->allows('get')->with('alt')->andReturn('A dog');
        $healthy->allows('mimeType')->andReturn('image/jpeg');
        $healthy->allows('size')->andReturn('2048');

        $this->assertSame('A dog', Assets::meta(new Value(collect([$healthy])), 'alt'));
        $this->assertSame('image/jpeg', Assets::mimeType($healthy));
        $this->assertSame(2048, Assets::size($healthy));

        $outage = Mockery::mock(Asset::class);
        $outage->allows('get')->andThrow(new RuntimeException('bucket 503'));
        $outage->allows('mimeType')->andThrow(new RuntimeException('bucket 503'));
        $outage->allows('size')->andThrow(new RuntimeException('bucket 503'));

        $this->assertSame('', Assets::meta($outage, 'alt', ''));
        $this->assertNull(Assets::mimeType($outage));
        $this->assertNull(Assets::size($outage));
        $this->assertSame('fallback', Assets::meta('https://example.com/a.jpg', 'alt', 'fallback'), 'a URL has no meta');
    }
}
