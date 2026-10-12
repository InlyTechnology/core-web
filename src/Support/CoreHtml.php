<?php

namespace Inly\CoreWeb\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Core's rich text (a page's `*_html` fields, from the backoffice's editor)
 * narrowed to the markup a prose block prints: paragraphs, line breaks,
 * emphasis, lists, subheadings and links to http(s), mailto and tel. Every
 * other element is unwrapped to its text and every attribute but a link's
 * href is dropped, so nothing from elsewhere can style, script or embed.
 * Text libxml keeps unescaped (the raw-text content of script, xmp and
 * the like, which it parses as CDATA) is re-created as plain text before
 * it can leave its element, so markup inside it prints as text.
 */
final class CoreHtml
{
    private const TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'a', 'blockquote'];

    /** Dropped with their content: code, embeds, and the raw-text elements whose content is not prose. */
    private const DROPPED = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'xmp', 'plaintext', 'noembed', 'noframes', 'noscript', 'title', 'textarea'];

    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementsByTagName('div')->item(0);

        if ($root === null) {
            return null;
        }

        self::cleanChildren($root);

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        $out = trim($out);

        return $out === '' ? null : $out;
    }

    private static function cleanChildren(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_CDATA_SECTION_NODE) {
                $node->replaceChild($node->ownerDocument->createTextNode($child->textContent), $child);

                continue;
            }

            if ($child instanceof DOMElement) {
                self::cleanChildren($child);
                $tag = strtolower($child->tagName);

                if (in_array($tag, self::DROPPED, true)) {
                    $node->removeChild($child);

                    continue;
                }

                if (! in_array($tag, self::TAGS, true)) {
                    while ($child->firstChild !== null) {
                        $grandchild = $child->firstChild;
                        // A text node carries its old parent's escaping rules with it; a new one escapes.
                        $moved = $grandchild->nodeType === XML_TEXT_NODE
                            ? $node->ownerDocument->createTextNode($grandchild->textContent)
                            : $grandchild;
                        $node->insertBefore($moved, $child);

                        if ($moved !== $grandchild) {
                            $child->removeChild($grandchild);
                        }
                    }
                    $node->removeChild($child);

                    continue;
                }

                $href = $tag === 'a' ? trim($child->getAttribute('href')) : '';

                foreach (iterator_to_array($child->attributes) as $attribute) {
                    $child->removeAttribute($attribute->nodeName);
                }

                if ($tag === 'a' && preg_match('~^(https?://|mailto:|tel:|/(?![/\\\\]))~i', $href)) {
                    $child->setAttribute('href', $href);

                    if (preg_match('~^https?://~i', $href)) {
                        $child->setAttribute('rel', 'noopener');
                    }
                }
            } elseif ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);
            }
        }
    }
}
