<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Sanitizes admin-authored HTML for safe storage and public rendering.
 */
final class HtmlContentService
{
    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'span',
        'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'a',
    ];

    /** @var list<string> */
    private const ALLOWED_STYLE_PROPS = [
        'color',
        'background-color',
        'font-size',
        'font-weight',
        'font-style',
        'text-decoration',
        'text-align',
    ];

    public function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $wrapped = '<div id="hc-root">' . $html . '</div>';
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $wrapped,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $root = $dom->getElementById('hc-root');
        if ($root === null) {
            return '';
        }

        $this->sanitizeNode($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * Sanitize and keep legacy plain-text newlines readable.
     */
    public function toSafeHtml(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        if (!$this->looksLikeHtml($content)) {
            return nl2br(htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        }

        return $this->sanitize($content);
    }

    public function looksLikeHtml(string $content): bool
    {
        return preg_match('/<\s*[a-z][^>]*>/i', $content) === 1;
    }

    private function sanitizeNode(\DOMNode $node): void
    {
        if (!$node->hasChildNodes()) {
            return;
        }

        /** @var list<\DOMNode> $children */
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                continue;
            }

            if ($child->nodeType !== XML_ELEMENT_NODE) {
                $node->removeChild($child);
                continue;
            }

            /** @var \DOMElement $child */
            $tag = strtolower($child->tagName);

            if ($tag === 'script' || $tag === 'style' || $tag === 'iframe' || $tag === 'object') {
                $node->removeChild($child);
                continue;
            }

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->unwrapElement($child);
                continue;
            }

            $this->sanitizeAttributes($child, $tag);
            $this->sanitizeNode($child);
        }
    }

    private function unwrapElement(\DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
    }

    private function sanitizeAttributes(\DOMElement $element, string $tag): void
    {
        /** @var list<string> $names */
        $names = [];
        if ($element->hasAttributes()) {
            foreach ($element->attributes as $attr) {
                $names[] = $attr->name;
            }
        }

        foreach ($names as $name) {
            $lower = strtolower($name);
            $value = $element->getAttribute($name);

            if (str_starts_with($lower, 'on') || $lower === 'srcset') {
                $element->removeAttribute($name);
                continue;
            }

            if ($lower === 'href' && $tag === 'a') {
                $href = trim($value);
                $isPlaceholder = preg_match('/^\{[a-z0-9_]+\}$/i', $href) === 1;
                if ($href === '' || (
                    !$isPlaceholder
                    && preg_match('{^(https?:|mailto:|/|#)}i', $href) !== 1
                )) {
                    $element->removeAttribute($name);
                    continue;
                }
                $element->setAttribute('href', $href);
                $element->setAttribute('rel', 'noopener noreferrer');
                if (preg_match('#^https?:#i', $href) === 1) {
                    $element->setAttribute('target', '_blank');
                }
                continue;
            }

            if ($lower === 'style') {
                $clean = $this->sanitizeStyle($value);
                if ($clean === '') {
                    $element->removeAttribute($name);
                } else {
                    $element->setAttribute('style', $clean);
                }
                continue;
            }

            if ($lower === 'class' && preg_match('/^[a-z0-9 _-]+$/i', $value) === 1) {
                continue;
            }

            $element->removeAttribute($name);
        }

        if ($tag === 'a' && !$element->hasAttribute('href')) {
            $this->unwrapElement($element);
        }
    }

    private function sanitizeStyle(string $style): string
    {
        $parts = [];
        foreach (explode(';', $style) as $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '' || !str_contains($declaration, ':')) {
                continue;
            }
            [$prop, $val] = array_map('trim', explode(':', $declaration, 2));
            $prop = strtolower($prop);
            if (!in_array($prop, self::ALLOWED_STYLE_PROPS, true)) {
                continue;
            }
            if (preg_match('/expression|url\s*\(|javascript:/i', $val) === 1) {
                continue;
            }
            if ($prop === 'color' || $prop === 'background-color') {
                if (preg_match('/^(#[0-9a-f]{3,8}|rgb\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*\)|rgba\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*,\s*[\d.]+\s*\)|[a-z]+)$/i', $val) !== 1) {
                    continue;
                }
            }
            if ($prop === 'font-size' && preg_match('/^\d+(\.\d+)?(px|pt|em|rem|%)$/', $val) !== 1) {
                continue;
            }
            $parts[] = $prop . ': ' . $val;
        }
        return implode('; ', $parts);
    }
}
