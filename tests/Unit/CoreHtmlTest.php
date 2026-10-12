<?php

namespace Inly\CoreWeb\Tests\Unit;

use Inly\CoreWeb\Support\CoreHtml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Core's rich text printed on the site: prose markup stays, anything that could script or style does not. */
class CoreHtmlTest extends TestCase
{
    public function test_prose_markup_and_safe_links_stay(): void
    {
        $this->assertSame(
            '<p>En <strong>stark</strong> och <em>tyst</em> bil.<br>Se <a href="https://www.example.com/q4" rel="noopener">Exempel</a>, <a href="/kampanjer">kampanjer</a>, <a href="mailto:info@example.com">mejl</a> eller <a href="tel:+46123456">ring</a>.</p><ul><li>Ett</li></ul><h3>Rubrik</h3>',
            CoreHtml::clean('<p class="x" style="color:red">En <strong>stark</strong> och <em>tyst</em> bil.<br>Se <a href="https://www.example.com/q4" target="_blank">Exempel</a>, <a href="/kampanjer">kampanjer</a>, <a href="mailto:info@example.com">mejl</a> eller <a href="tel:+46123456">ring</a>.</p><ul><li>Ett</li></ul><h3>Rubrik</h3>'),
        );
        $this->assertSame('<p>Från ingenting till text.</p>', CoreHtml::clean('<p>Från <span>ingenting</span> till <font>text</font>.</p>'));
        $this->assertNull(CoreHtml::clean('  '));
    }

    /** @return array<string, array{string}> */
    public static function scripting(): array
    {
        return [
            'xmp' => ['<xmp><img src=x onerror=alert(1)></xmp>'],
            'plaintext' => ['<plaintext><img src=x onerror=alert(1)>'],
            'noembed' => ['<noembed><img src=x onerror=alert(1)></noembed>'],
            'noframes' => ['<noframes><img src=x onerror=alert(1)></noframes>'],
            'noscript' => ['<noscript><img src=x onerror=alert(1)></noscript>'],
            'title' => ['<title><img src=x onerror=alert(1)></title>'],
            'textarea' => ['<textarea><img src=x onerror=alert(1)></textarea>'],
            'script' => ['<script>alert(1)</script>'],
            'style' => ['<style>body{display:none}</style>'],
            'an unknown wrapper around raw text' => ['<section><xmp><img src=x onerror=alert(1)></xmp></section>'],
            'an attribute' => ['<p onclick="alert(1)">Hej</p>'],
            'a javascript link' => ['<a href="javascript:alert(1)">Hej</a>'],
            'svg' => ['<svg><script>alert(1)</script></svg>'],
        ];
    }

    #[DataProvider('scripting')]
    public function test_nothing_can_script(string $html): void
    {
        $clean = (string) CoreHtml::clean($html);

        $this->assertStringNotContainsStringIgnoringCase('<img', $clean);
        $this->assertStringNotContainsStringIgnoringCase('<script', $clean);
        $this->assertStringNotContainsStringIgnoringCase('<style', $clean);
        $this->assertDoesNotMatchRegularExpression('~<[^>]+\son\w+=~i', $clean);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $clean);
    }

    public function test_raw_text_moved_out_of_an_unknown_element_is_escaped(): void
    {
        // A raw-text element libxml does not know is unwrapped, its text escaped on the way out.
        $this->assertSame('<p>&lt;img src=x&gt;</p>', CoreHtml::clean('<p><span>&lt;img src=x&gt;</span></p>'));
    }

    /** @return array<string, array{string}> */
    public static function offSiteRelativeLinks(): array
    {
        return [
            'protocol-relative' => ['//evil.com'],
            'backslash' => ['/\\evil.com'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
        ];
    }

    #[DataProvider('offSiteRelativeLinks')]
    public function test_a_link_that_leaves_the_site_unannounced_loses_its_href(string $href): void
    {
        $this->assertSame('<a>Hej</a>', CoreHtml::clean('<a href="'.htmlspecialchars($href).'">Hej</a>'));
    }
}
