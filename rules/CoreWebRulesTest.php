<?php

namespace Tests\Central;

use Illuminate\Support\Facades\File;
use Inly\CoreWeb\Media\MediaDisks;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Rules about how a site uses core-web, run by site-ci's gate beside its central
 * suites. They ship with core-web so they always match the version the site runs.
 *
 * A failure says what to change in the site. Fix the site; never weaken a rule to get green.
 */
class CoreWebRulesTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function rules(): array
    {
        return [
            'media disks are core-web\'s own' => ['siteDefinedMediaDisks'],
            'pictures say which sizes they need' => ['coreImagesWithoutWidths'],
        ];
    }

    #[DataProvider('rules')]
    public function test_the_site_follows_the_rule(string $rule): void
    {
        [$hits, $why] = $this->{$rule}();

        $this->assertEmpty($hits, $why."\n  ".implode("\n  ", $hits));
    }

    /**
     * core-web defines a disk in `media.disks` only when the app has none by that
     * name, so a site that keeps the skeleton's `assets` disk silently serves its
     * images from the local folder instead of the bucket and the CDN.
     *
     * @return array{list<string>, string}
     */
    protected function siteDefinedMediaDisks(): array
    {
        if (! config('core-web.features.media')) {
            return [[], ''];
        }

        $own = array_filter(
            array_map('strval', array_keys((array) config('core-web.media.disks'))),
            fn (string $disk) => ! MediaDisks::defines($disk),
        );

        return [
            array_values(array_map(fn (string $disk) => "disk '{$disk}' (config/filesystems.php or a service provider)", $own)),
            "With core-web's media feature on, remove these disks from the site's own config: core-web defines them (inly/core-web docs/media.md, \"The disks\").",
        ];
    }

    /**
     * Without `widths` an <x-core-img> has no srcset, so `sizes` does nothing and
     * every screen gets the picture at its uploaded size. A tag says which files
     * it needs: `widths`, or one fixed `width`. A tag that forwards its
     * component's `$attributes` is a site's wrapper, whose callers pass them.
     *
     * @return array{list<string>, string}
     */
    protected function coreImagesWithoutWidths(): array
    {
        $views = base_path('resources/views');

        if (! config('core-web.features.media') || ! is_dir($views)) {
            return [[], ''];
        }

        $hits = [];

        foreach (File::allFiles($views) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            // Comments become blank lines, so a commented-out tag is skipped and line numbers stay true.
            $code = (string) preg_replace_callback('/\{\{--.*?--\}\}/s', fn (array $m) => str_repeat("\n", substr_count($m[0], "\n")), (string) file_get_contents($file->getPathname()));

            preg_match_all('/<x-core-(?:img|media)\b((?:[^"\'>]|"[^"]*"|\'[^\']*\')*)>/s', $code, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($tags as [[$tag, $offset], [$attributes]]) {
                if (preg_match('/(?<![\w-]):?widths?\s*=/', $attributes) || str_contains($attributes, '$attributes')) {
                    continue;
                }

                $hits[] = ltrim(substr($file->getPathname(), strlen(base_path())), '/').':'.(substr_count($code, "\n", 0, $offset) + 1)
                    .'  '.mb_strimwidth((string) preg_replace('/\s+/', ' ', $tag), 0, 100, '…');
            }
        }

        return [
            $hits,
            'Give each <x-core-img> / <x-core-media> the sizes it needs: `:widths` for a srcset that fits the layout, or one fixed `width` (inly/core-web docs/media.md, "The components"). `:widths="[]"` says on purpose that a picture needs none.',
        ];
    }
}
