<?php
declare(strict_types=1);

/**
 * A SAFE Markdown renderer for the document page's preview (docs/build-specs/uploads-versions.md "document"): everything is escaped first, then
 * headings (#…######), paragraphs, ordered and unordered lists, blockquotes, fenced and inline code, bold, italic and links (http, https and
 * mailto only — never javascript:). No raw HTML survives: a <script> in the source is text on the page. Enough for notes and READMEs; a
 * document that needs more is downloaded.
 */
function dc_markdown(string $md, int $maxChars = 200000): string
{
    $md = str_replace(["\r\n", "\r"], "\n", mb_substr($md, 0, $maxChars));
    $lines = explode("\n", $md);
    $out = [];
    $para = [];
    $list = null;        // 'ul' | 'ol'
    $quote = false;
    $code = false;
    $flushPara = static function () use (&$para, &$out): void {
        if ($para !== []) {
            $out[] = '<p>' . dc_md_inline(implode(' ', $para)) . '</p>';
            $para = [];
        }
    };
    $closeList = static function () use (&$list, &$out): void {
        if ($list !== null) {
            $out[] = '</' . $list . '>';
            $list = null;
        }
    };
    $closeQuote = static function () use (&$quote, &$out): void {
        if ($quote) {
            $out[] = '</blockquote>';
            $quote = false;
        }
    };
    foreach ($lines as $line) {
        if ($code) {
            if (preg_match('/^\s*```/', $line)) {
                $out[] = '</code></pre>';
                $code = false;
            } else {
                $out[] = e($line);
            }
            continue;
        }
        if (preg_match('/^\s*```/', $line)) {
            $flushPara(); $closeList(); $closeQuote();
            $out[] = '<pre class="bg-light p-2 rounded fs-12"><code>';
            $code = true;
            continue;
        }
        if (trim($line) === '') {
            $flushPara(); $closeList(); $closeQuote();
            continue;
        }
        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
            $flushPara(); $closeList(); $closeQuote();
            $n = min(6, strlen($m[1]) + 1);
            $out[] = '<h' . $n . '>' . dc_md_inline($m[2]) . '</h' . $n . '>';
            continue;
        }
        if (preg_match('/^\s*>\s?(.*)$/', $line, $m)) {
            $flushPara(); $closeList();
            if (!$quote) {
                $out[] = '<blockquote class="border-start ps-3 text-muted">';
                $quote = true;
            }
            $out[] = '<p>' . dc_md_inline($m[1]) . '</p>';
            continue;
        }
        if (preg_match('/^\s*([-*+]|\d+[.)])\s+(.*)$/', $line, $m)) {
            $flushPara(); $closeQuote();
            $kind = ctype_digit($m[1][0]) ? 'ol' : 'ul';
            if ($list !== $kind) {
                $closeList();
                $out[] = '<' . $kind . '>';
                $list = $kind;
            }
            $out[] = '<li>' . dc_md_inline($m[2]) . '</li>';
            continue;
        }
        if (preg_match('/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
            $flushPara(); $closeList(); $closeQuote();
            $out[] = '<hr>';
            continue;
        }
        $closeList(); $closeQuote();
        $para[] = trim($line);
    }
    $flushPara(); $closeList(); $closeQuote();
    if ($code) {
        $out[] = '</code></pre>';
    }
    return implode("\n", $out);
}

/** Inline Markdown on an ESCAPED line: code spans, bold, italic, links with safe schemes. */
function dc_md_inline(string $text): string
{
    $t = e($text);
    $t = preg_replace('/`([^`]+)`/', '<code>$1</code>', $t) ?? $t;
    $t = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $t) ?? $t;
    $t = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $t) ?? $t;
    $t = preg_replace('/(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/s', '<em>$1</em>', $t) ?? $t;
    $t = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
        $href = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        if (!preg_match('#^(https?://|mailto:)#i', $href)) {
            return $m[1];
        }
        return '<a href="' . e($href) . '" rel="noopener nofollow" target="_blank">' . $m[1] . '</a>';
    }, $t) ?? $t;
    return $t;
}
