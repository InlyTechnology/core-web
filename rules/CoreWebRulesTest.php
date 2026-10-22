<?php

namespace Tests\Central;

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
            "With core-web's media feature on, remove these disks from the site's own config: core-web defines them (docs/media.md, \"The disks\").",
        ];
    }
}
