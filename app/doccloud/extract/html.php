<?php
declare(strict_types=1);

/**
 * The HTML reader pass: everything active or around the content is removed (scripts, styles, frames, forms, nav,
 * header and footer outside the article, asides, hidden elements), the main content (main → a single article → body)
 * is walked in document order into paragraphs, list items, table rows and h1–h6 heading hints.
 */

const DC_HTML_DROP = ['script', 'style', 'noscript', 'template', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
    'svg', 'math', 'canvas', 'form', 'nav', 'footer', 'aside', 'button', 'select', 'input', 'textarea', 'dialog', 'menu',
    'audio', 'video', 'picture', 'img', 'map', 'link', 'meta', 'base', 'head'];
const DC_HTML_BLOCK = ['p', 'div', 'section', 'article', 'main', 'header', 'blockquote', 'figure', 'figcaption', 'address',
    'dl', 'dt', 'dd', 'ul', 'ol', 'details', 'summary', 'hr', 'center', 'body', 'html'];

/** Parse HTML safely: no network, no entities from outside, errors swallowed; the charset honoured or guessed. */
function dc_html_dom(string $html): ?DOMDocument
{
    if (!mb_check_encoding($html, 'UTF-8')) {
        $cs = preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', $html, $m) ? strtoupper($m[1]) : 'WINDOWS-1252';
        $html = in_array($cs, array_map('strtoupper', mb_list_encodings()), true) ? (string) mb_convert_encoding($html, 'UTF-8', $cs) : (string) mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
    }
    $html = dc_text_strip_bom($html);
    $html = (string) preg_replace('/<meta[^>]+charset[^>]*>/i', '', $html);
    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $ok = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $ok ? $dom : null;
}

/** Read an HTML document: ['text', 'blocks', 'pages' (none), 'title', 'language']. */
function dc_read_html(string $html): array
{
    $dom = dc_html_dom($html);
    $tb = dc_tb_new();
    if ($dom === null) {
        return dc_tb_finish($tb) + ['title' => null, 'language' => null];
    }
    $xp = new DOMXPath($dom);
    $title = null;
    foreach ($xp->query('//title') ?: [] as $t) {
        $title = dc_one_line($t->textContent) ?: null;
        break;
    }
    $lang = null;
    foreach ($xp->query('//html[@lang]') ?: [] as $h) {
        $lang = dc_language_tag($h->getAttribute('lang'));
        break;
    }
    // remove everything that is not content
    $drop = [];
    foreach ($xp->query('//*') ?: [] as $el) {
        $tag = strtolower($el->nodeName);
        $role = strtolower($el->getAttribute('role'));
        $style = strtolower(str_replace(' ', '', $el->getAttribute('style')));
        if (in_array($tag, DC_HTML_DROP, true) || in_array($role, ['navigation', 'banner', 'contentinfo', 'search', 'complementary', 'menu', 'menubar', 'dialog'], true)
            || $el->hasAttribute('hidden') || strtolower($el->getAttribute('aria-hidden')) === 'true' || str_contains($style, 'display:none') || str_contains($style, 'visibility:hidden')
            || ($tag === 'header' && $xp->query('ancestor::article|ancestor::main', $el)->length === 0)) {
            $drop[] = $el;
        }
    }
    foreach ($drop as $el) {
        if ($el->parentNode !== null) {
            $el->parentNode->removeChild($el);
        }
    }
    $root = null;
    foreach (['//main', '//*[@role="main"]'] as $q) {
        $r = $xp->query($q);
        if ($r !== false && $r->length > 0) {
            $root = $r->item(0);
            break;
        }
    }
    if ($root === null) {
        $a = $xp->query('//article');
        $root = ($a !== false && $a->length === 1) ? $a->item(0) : ($xp->query('//body')->item(0) ?? $dom->documentElement);
    }
    $buf = '';
    if ($root !== null) {
        dc_html_walk($root, $tb, $buf);
    }
    dc_html_flush($tb, $buf);
    if ($title === null) {
        foreach ($tb['blocks'] as $b) {
            $title = $b['heading'];
            break;
        }
    }
    return dc_tb_finish($tb) + ['title' => $title, 'language' => $lang];
}

function dc_html_flush(array &$tb, string &$buf): void
{
    $lines = array_map(static fn($l) => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $l)), explode("\n", $buf));
    dc_tb_para($tb, implode("\n", array_filter($lines, static fn($l) => $l !== '')));
    $buf = '';
}

function dc_html_walk(DOMNode $node, array &$tb, string &$buf, int $depth = 0): void
{
    if ($depth > 400) { // a pathological nesting is read as its text
        $buf .= ' ' . $node->textContent;
        return;
    }
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMText) {
            $buf .= (string) preg_replace('/\s+/u', ' ', $child->data);
            continue;
        }
        if (!$child instanceof DOMElement) {
            continue;
        }
        $tag = strtolower($child->nodeName);
        if (preg_match('/^h([1-6])$/', $tag, $m)) {
            dc_html_flush($tb, $buf);
            dc_tb_heading($tb, $child->textContent, (int) $m[1]);
            continue;
        }
        if ($tag === 'br') {
            $buf .= "\n";
            continue;
        }
        if ($tag === 'pre') {
            dc_html_flush($tb, $buf);
            dc_tb_para($tb, $child->textContent);
            continue;
        }
        if ($tag === 'table') {
            dc_html_flush($tb, $buf);
            $rows = [];
            foreach ((new DOMXPath($child->ownerDocument))->query('.//tr', $child) ?: [] as $tr) {
                $cells = [];
                foreach ($tr->childNodes as $td) {
                    if ($td instanceof DOMElement && in_array(strtolower($td->nodeName), ['td', 'th'], true)) {
                        $cells[] = dc_one_line($td->textContent);
                    }
                }
                $cells = array_filter($cells, static fn($c) => $c !== '');
                if ($cells !== []) {
                    $rows[] = implode(' | ', $cells);
                }
            }
            dc_tb_lines($tb, $rows);
            continue;
        }
        if ($tag === 'li') {
            dc_html_flush($tb, $buf);
            $buf = '- ';
            dc_html_walk($child, $tb, $buf, $depth + 1);
            dc_html_flush($tb, $buf);
            continue;
        }
        if (in_array($tag, DC_HTML_BLOCK, true)) {
            dc_html_flush($tb, $buf);
            dc_html_walk($child, $tb, $buf, $depth + 1);
            dc_html_flush($tb, $buf);
            continue;
        }
        dc_html_walk($child, $tb, $buf, $depth + 1);
    }
}

/** A BCP 47-ish language tag, or null when it is not one. */
function dc_language_tag(string $v): ?string
{
    $v = trim($v);
    return preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/', $v) ? $v : null;
}
