<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Seo\NotFoundPage;
use Inly\CoreWeb\Seo\Redirects;
use Inly\CoreWeb\Seo\Seo;
use Inly\CoreWeb\Seo\Sitemaps;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Exceptions\NotFoundHttpException;
use Statamic\Facades\Cascade;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\GlobalSet;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class SeoTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['seo'];

    protected function setUp(): void
    {
        parent::setUp();

        Collection::make('pages')->routes('/{slug}')->sites(['default'])->save();
        Collection::make('articles')->routes('/news/{slug}')->sites(['default'])->save();
        Entry::make()->id('about')->collection('pages')->locale('default')->slug('about')->data(['title' => 'About us', 'seo_description' => 'Who we are.'])->save();
        Entry::make()->id('hidden')->collection('pages')->locale('default')->slug('hidden')->data(['title' => 'Hidden', 'seo_noindex' => 'noindex'])->save();
        Entry::make()->id('news-1')->collection('articles')->locale('default')->slug('first')->data(['title' => 'First news'])->save();

        $this->seo([]);
    }

    /** @param  array<string, mixed>  $data */
    private function seo(array $data): void
    {
        $set = GlobalSet::findByHandle('seo') ?? GlobalSet::make('seo')->title('SEO');
        $set->save();
        $set->in('default')->data(array_replace([
            'site_name' => 'Acme',
            'title_separator' => '–',
            'sitemap_collections' => ['pages', 'articles'],
            'noindex_production' => false,
        ], $data))->save();
    }

    private function production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
    }

    private function renderHead(string $id): string
    {
        return Blade::render('<x-core-web::seo.head :page="$page" />', ['page' => Entry::find($id)]);
    }

    public function test_the_head_titles_the_page_with_the_site_name_and_its_own_description(): void
    {
        $this->production();
        $html = $this->renderHead('about');

        $this->assertStringContainsString('<title>About us – Acme</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Who we are.">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="http://default.test/about">', $html);
        $this->assertStringContainsString('content="index, follow', $html);
        $this->assertStringContainsString('<meta property="og:title" content="About us">', $html);
        $this->assertStringContainsString('<meta property="og:site_name" content="Acme">', $html);
    }

    public function test_an_entry_s_own_seo_title_replaces_the_whole_title(): void
    {
        Entry::find('about')->set('seo_title', 'Own title')->save();

        $this->assertStringContainsString('<title>Own title</title>', $this->renderHead('about'));
    }

    public function test_a_collection_rule_adds_the_collection_title(): void
    {
        $this->seo(['title_separator' => '|', 'change_page_title' => [['collection' => ['articles'], 'manipulate_title' => 'collection_title']]]);

        $this->assertStringContainsString('<title>First news | Articles | Acme</title>', $this->renderHead('news-1'));
    }

    public function test_an_environment_not_marked_indexable_is_noindexed_without_a_canonical(): void
    {
        $html = $this->renderHead('about');

        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
    }

    public function test_a_noindexed_entry_and_a_collection_default_noindex_the_page(): void
    {
        $this->production();
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $this->renderHead('hidden'));

        $this->seo(['collection_indexing_defaults' => [['collection' => ['articles'], 'noindex' => 'noindex']]]);
        $this->assertTrue(Seo::noindex(Entry::find('news-1')));

        Entry::find('news-1')->set('seo_noindex', 'index')->save();
        $this->assertFalse(Seo::noindex(Entry::find('news-1')));
    }

    public function test_a_page_outside_the_cascade_passes_its_own_title_and_description(): void
    {
        $html = Blade::render('<x-core-web::seo.head title="A vehicle" description="Red and fast." />');

        $this->assertStringContainsString('<title>A vehicle – Acme</title>', $html);
        $this->assertStringContainsString('content="Red and fast."', $html);
    }

    public function test_trackers_render_only_where_the_environment_switch_is_on(): void
    {
        $this->production();
        $this->seo(['tracker_type' => 'gtag', 'google_analytics' => 'G-TEST123']);
        $this->assertStringNotContainsString('G-TEST123', $this->renderHead('about'));

        $this->seo(['tracker_type' => 'gtag', 'google_analytics' => 'G-TEST123', 'trackers_production' => true]);
        $html = $this->renderHead('about');

        $this->assertStringContainsString('gtag/js?id=G-TEST123', $html);
        $this->assertStringContainsString("gtag('config', 'G-TEST123', { anonymize_ip: true })", $html);
    }

    public function test_with_consent_on_the_google_tags_are_held_until_statistics_is_granted(): void
    {
        config(['core-web.features.consent' => true]);
        $this->production();
        $this->seo(['tracker_type' => 'gtag', 'google_analytics' => 'G-TEST123', 'trackers_production' => true]);

        $html = $this->renderHead('about');

        $this->assertMatchesRegularExpression('/<script type="text\/plain" data-consent="statistics" data-src="https:\/\/www.googletagmanager.com\/gtag\/js\?id=G-TEST123"/', $html);
        $this->assertStringNotContainsString(' src="https://www.googletagmanager.com', $html);
    }

    public function test_json_ld_from_the_entry_cannot_close_the_script(): void
    {
        Entry::find('about')->set('schema_jsonld', '{"name": "</script><script>alert(1)"}')->save();

        $html = $this->renderHead('about');

        $this->assertStringContainsString('<\/script><script>alert(1)', $html);
        $this->assertSame(1, substr_count($html, '<script type="application/ld+json">'));
    }

    public function test_robots_txt_blocks_ai_training_and_lists_the_sitemap(): void
    {
        $this->seo(['robots' => "User-agent: *\nDisallow: /private"]);

        $body = $this->get('http://default.test/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();

        $this->assertStringContainsString('Disallow: /private', $body);
        $this->assertStringContainsString("User-agent: GPTBot\nDisallow: /", $body);
        $this->assertStringNotContainsString('User-agent: OAI-SearchBot', $body);
        $this->assertStringContainsString('Content-Signal: ai-train=no, search=yes, ai-input=yes', $body);
        $this->assertStringContainsString('Sitemap: http://default.test/sitemap.xml', $body);
        $this->assertStringNotContainsString('other.test', $body);

        config(['core-web.seo.robots.ai_search' => false]);
        $body = $this->get('http://default.test/robots.txt')->getContent();
        $this->assertStringContainsString("User-agent: OAI-SearchBot\nDisallow: /", $body);
        $this->assertStringContainsString('ai-input=no', $body);
    }

    public function test_the_sitemap_lists_published_indexable_entries_of_its_collections(): void
    {
        Entry::make()->id('draft')->collection('pages')->locale('default')->slug('draft')->published(false)->data(['title' => 'Draft'])->save();

        $body = $this->get('http://default.test/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();

        $this->assertStringContainsString('<loc>http://default.test/about</loc>', $body);
        $this->assertStringContainsString('<loc>http://default.test/news/first</loc>', $body);
        $this->assertStringNotContainsString('/hidden', $body);
        $this->assertStringNotContainsString('/draft', $body);

        $index = $this->get('http://default.test/sitemaps.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<loc>http://default.test/sitemap.xml</loc>', $index);
    }

    public function test_the_sitemap_is_cached_until_content_is_saved(): void
    {
        $this->get('http://default.test/sitemap.xml');
        $this->assertTrue(Cache::has('core-web.seo.sitemap.default'));

        Entry::make()->id('new')->collection('pages')->locale('default')->slug('new')->data(['title' => 'New'])->save();

        $this->assertFalse(Cache::has('core-web.seo.sitemap.default'));
        $this->assertStringContainsString('/new</loc>', $this->get('http://default.test/sitemap.xml')->getContent());
    }

    public function test_a_site_that_turned_its_sitemap_off_has_none(): void
    {
        $this->seo(['use_sitemap' => false]);
        Sitemaps::flush();

        $this->get('http://default.test/sitemap.xml')->assertNotFound();
    }

    public function test_redirects_match_exact_encoded_and_pattern_paths(): void
    {
        $set = GlobalSet::make('redirects')->title('Redirects');
        $set->save();
        $set->in('default')->data(['redirects' => [
            ['url_old' => '/old-about', 'internal_url' => ['about'], 'response' => '301'],
            ['url_old' => '/tj%C3%A4nster', 'external_url' => 'https://example.com/', 'response' => '302'],
            ['url_old' => '#^/blog/(.*)$#', 'internal_url' => ['news-1']],
        ]])->save();

        $this->assertSame(['to' => 'http://default.test/about', 'status' => 301], Redirects::for('/old-about'));
        $this->assertSame(['to' => 'https://example.com/', 'status' => 302], Redirects::for('/tjänster'));
        $this->assertSame(['to' => 'http://default.test/news/first', 'status' => 301], Redirects::for('/blog/anything'));
        $this->assertNull(Redirects::for('/nowhere'));
    }

    public function test_a_front_end_404_follows_the_redirect(): void
    {
        $set = GlobalSet::make('redirects')->title('Redirects');
        $set->save();
        $set->in('default')->data(['redirects' => [
            ['url_old' => '/old-about', 'internal_url' => ['about'], 'response' => '301'],
            ['url_old' => '/old-product', 'internal_url' => ['about'], 'response' => '302'],
        ]])->save();

        $this->get('http://default.test/old-about?ref=mail')->assertStatus(301)->assertRedirect('http://default.test/about?ref=mail');

        Route::get('/old-product', fn () => throw new NotFoundHttpException);
        $this->get('http://default.test/old-product')->assertStatus(302)->assertRedirect('http://default.test/about');

        $this->post('http://default.test/old-about')->assertNotFound();
    }

    public function test_an_error_page_is_noindexed_without_a_canonical(): void
    {
        $this->production();
        Cascade::instance()->set('response_code', 404);
        $html = $this->renderHead('about');

        $this->assertStringContainsString('noindex', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
    }

    public function test_the_sitemaps_index_lists_every_site_on_the_host(): void
    {
        $index = $this->get('http://default.test/sitemaps.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();

        $this->assertStringContainsString('<sitemapindex', $index);
        $this->assertStringContainsString('<loc>http://default.test/sitemap.xml</loc>', $index);
    }

    public function test_the_page_not_found_entry_fills_the_404_view(): void
    {
        Entry::make()->id('missing')->collection('pages')->locale('default')->slug('not-found')->data(['title' => 'Lost?'])->save();
        $set = GlobalSet::make('configuration')->title('Configuration');
        $set->save();
        $set->in('default')->data(['error_404_entry' => ['missing']])->save();

        $this->assertSame('missing', NotFoundPage::entry()?->id());

        View::addLocation(__DIR__.'/../fixtures/seo-views');
        $this->assertSame('Lost?', trim(view('errors/404', ['response_code' => 404])->render()));
        $this->assertSame('', trim(view('errors/404', ['response_code' => 200])->render()));
    }

    public function test_a_collection_s_own_fields_make_the_social_card(): void
    {
        config(['core-web.seo.social.collections' => ['articles' => ['image' => 'image', 'description' => 'excerpt', 'type' => 'article']]]);
        Entry::find('news-1')->merge(['excerpt' => '<p>What happened first.</p>', 'image' => 'https://images.example.com/first.jpg'])->save();

        $html = $this->renderHead('news-1');

        $this->assertStringContainsString('<meta property="og:type" content="article">', $html);
        $this->assertStringContainsString('<meta property="og:description" content="What happened first.">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="https://images.example.com/first.jpg">', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
    }

    public function test_a_site_naming_one_hero_key_keeps_the_others(): void
    {
        config(['core-web.seo' => ['social' => ['hero' => ['image_field' => 'background_media']]]]);
        (function (): void {
            $this->mergeGroupDefaults('seo', []);
            $this->mergeSocialDefaults();
        })->call($this->app->getProvider(CoreWebServiceProvider::class));

        $this->assertSame(['field' => 'page_builder', 'set' => 'hero', 'image_field' => 'background_media'], config('core-web.seo.social.hero'));
        $this->assertSame(1200, config('core-web.seo.social.image.width'));
    }

    public function test_the_fieldsets_keep_peak_seo_s_handles(): void
    {
        $this->assertContains('google_tag_manager', Fieldset::find('core-web::seo_trackers')->fields()->all()->keys()->all());
        $this->assertContains('seo_canonical_type', Fieldset::find('core-web::seo_entry')->fields()->all()->keys()->all());
        $this->assertContains('sitemap_collections', Fieldset::find('core-web::seo_sitemap')->fields()->all()->keys()->all());
    }
}
