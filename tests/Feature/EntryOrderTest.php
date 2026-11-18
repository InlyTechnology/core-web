<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Eloquent\Entries\UuidEntryModel;
use Statamic\Eloquent\ServiceProvider as EloquentDriverServiceProvider;
use Statamic\Events\EntrySaved;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class EntryOrderTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['cloud'];

    protected function getPackageProviders($app)
    {
        return [...parent::getPackageProviders($app), EloquentDriverServiceProvider::class];
    }

    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('statamic.eloquent-driver', require __DIR__.'/../../vendor/statamic/eloquent-driver/config/eloquent-driver.php');
        $app['config']->set('statamic.eloquent-driver.entries.driver', 'eloquent');
        $app['config']->set('statamic.eloquent-driver.entries.model', UuidEntryModel::class);
        $app['config']->set('statamic.eloquent-driver.collection_trees.driver', 'eloquent');
    }

    protected function defineDatabaseMigrations(): void
    {
        foreach (['create_entries_table_with_string_ids', 'create_navigation_trees_table'] as $name) {
            (require glob(__DIR__."/../../database/cloud-migrations/*_{$name}.php")[0])->up();
        }
    }

    public function test_entry_order_writes_what_the_tree_decides_without_saving_entries_the_full_way(): void
    {
        Facade::clearResolvedInstances();
        $collection = Collection::make('pages')->routes('{parent_uri}/{slug}')->sites(['default', 'other'])->structureContents(['max_depth' => 3]);
        $collection->save();
        $collection->structure()->makeTree('default')->save();
        $collection->structure()->makeTree('other')->save();
        Blink::flush();

        foreach (['about' => 'om', 'team' => 'laget', 'contact' => 'kontakt'] as $slug => $localized) {
            Entry::make()->collection($collection)->locale('default')->id($slug)->slug($slug)->data(['title' => $slug])->saveQuietly();
            Entry::make()->collection($collection)->locale('other')->id("{$slug}-other")->origin($slug)->slug($localized)->saveQuietly();
        }

        // Without events, as the trees import writes them: a tree save's listener would set each entry's order and parent itself.
        $collection->structure()->in('default')->tree([['entry' => 'about', 'children' => [['entry' => 'team']]], ['entry' => 'contact']])->saveQuietly();
        $collection->structure()->in('other')->tree([['entry' => 'about-other', 'children' => [['entry' => 'team-other']]], ['entry' => 'contact-other']])->saveQuietly();
        // As the entries import leaves the rows, written before there was a tree.
        DB::table('entries')->update(['order' => null, 'uri' => null]);
        Blink::flush();

        Queue::fake();
        $saved = 0;
        Event::listen(EntrySaved::class, function () use (&$saved): void {
            $saved++;
        });

        $this->artisan('core-web:entry-order', ['collection' => 'pages'])->assertSuccessful();

        $rows = DB::table('entries')->get()->keyBy('id');
        $this->assertSame(0, $saved);
        Queue::assertNothingPushed();
        $this->assertSame([1, 2, 3], [(int) $rows['about']->order, (int) $rows['team']->order, (int) $rows['contact']->order]);
        $this->assertSame('/about/team', $rows['team']->uri);
        $this->assertSame(2, (int) $rows['team-other']->order);
        $this->assertSame('about', json_decode($rows['team']->data, true)['parent'] ?? null);
        $this->assertSame('about-other', json_decode($rows['team-other']->data, true)['parent'] ?? null);
    }
}
