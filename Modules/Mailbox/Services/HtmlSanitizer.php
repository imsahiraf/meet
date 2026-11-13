<?php

namespace Modules\Mailbox\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

/** Allow-list HTML cleaner for mail written or quoted in Meet (DOM based: tags and attributes not listed are removed). */
class HtmlSanitizer
{
    private const TAGS = [
        'p' => [], 'br' => [], 'div' => [], 'span' => [], 'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'h1' => [], 'h2' => [], 'h3' => [], 'hr' => [], 'pre' => [], 'code' => [],
        'a' => ['href', 'title'], 'img' => ['src', 'alt', 'width', 'height'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'td' => ['colspan', 'rowspan'], 'th' => ['colspan', 'rowspan'],
    ];

    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'textarea', 'select', 'button', 'svg', 'math', 'template', 'noscript', 'head', 'title', 'meta', 'link', 'base'];

    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="zx-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('zx-root') ?? $doc->documentElement;
        if (!$root) {
            return '';
        }
        self::walk($root);
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                    $node->removeChild($child);
                    continue;
                }
                if (!array_key_exists($tag, self::TAGS)) { // unknown tag: keep its text, drop the tag
                    self::walk($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->name);
                    $ok = in_array($name, self::TAGS[$tag], true);
                    if ($ok && in_array($name, ['href', 'src'], true)) {
                        $v = trim(preg_replace('/[\x00-\x20]+/', '', html_entity_decode($attr->value)));
                        $ok = (bool) preg_match($name === 'href' ? '#^(https?:|mailto:|\#)#i' : '#^(https?:|cid:)#i', $v);
                    }
                    if (!$ok) {
                        $child->removeAttribute($attr->name);
                    }
                }
                if ($tag === 'a') {
                    $child->setAttribute('rel', 'noopener noreferrer');
                    $child->setAttribute('target', '_blank');
                }
                self::walk($child);
            } elseif ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);
            }
        }
    }
}
