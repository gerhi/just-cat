<?php
/**
 * Minimal Markdown renderer for the help pages (the README files).
 * Supports: headings, paragraphs, lists, fenced code, tables, blockquotes,
 * horizontal rules, inline code, bold, italic and links. All text is escaped.
 */

declare(strict_types=1);

defined('CAT') or exit;

function md_slug(string $text): string
{
    $s = strtolower(trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', strip_tags($text)), '-'));
    return $s !== '' ? $s : 'section';
}

function md_inline(string $text): string
{
    // Split out code spans first so their content is not formatted.
    $parts = preg_split('/(`[^`]+`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    foreach ($parts as $part) {
        if ($part !== '' && $part[0] === '`' && substr($part, -1) === '`' && strlen($part) > 1) {
            $out .= '<code>' . htmlspecialchars(substr($part, 1, -1), ENT_QUOTES, 'UTF-8') . '</code>';
            continue;
        }
        $t = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
        $t = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
            $href = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            if (!preg_match('#^(https?://|\#|[a-z0-9_./-]+$)#i', $href)) {
                return $m[1];
            }
            $ext = preg_match('#^https?://#', $href) ? ' rel="noopener noreferrer" target="_blank"' : '';
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . $ext . '>' . $m[1] . '</a>';
        }, $t);
        $t = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $t);
        $t = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $t);
        $out .= $t;
    }
    return $out;
}

/** Render Markdown. Returns [html, toc] where toc lists the level-2 headings. */
function md_render(string $md): array
{
    $lines = preg_split('/\r\n|\n|\r/', $md);
    $html = '';
    $toc = [];
    $para = [];
    $list = null;      // 'ul' | 'ol'
    $listItems = [];
    $quote = [];
    $n = count($lines);

    $flushPara = function () use (&$para, &$html) {
        if ($para) {
            $html .= '<p>' . md_inline(implode(' ', $para)) . "</p>\n";
            $para = [];
        }
    };
    $flushList = function () use (&$list, &$listItems, &$html) {
        if ($list) {
            $html .= "<$list>" . implode('', array_map(fn($i) => '<li>' . md_inline($i) . '</li>', $listItems)) . "</$list>\n";
            $list = null;
            $listItems = [];
        }
    };
    $flushQuote = function () use (&$quote, &$html) {
        if ($quote) {
            $html .= '<blockquote><p>' . md_inline(implode(' ', $quote)) . "</p></blockquote>\n";
            $quote = [];
        }
    };
    $flushAll = function () use ($flushPara, $flushList, $flushQuote) {
        $flushPara();
        $flushList();
        $flushQuote();
    };

    for ($i = 0; $i < $n; $i++) {
        $line = $lines[$i];

        if (preg_match('/^```/', $line)) {
            $flushAll();
            $code = [];
            for ($i++; $i < $n && !preg_match('/^```/', $lines[$i]); $i++) {
                $code[] = $lines[$i];
            }
            $html .= '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8') . "</code></pre>\n";
            continue;
        }
        if (trim($line) === '') {
            $flushAll();
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.*)$/', $line, $m)) {
            $flushAll();
            $level = strlen($m[1]);
            $id = md_slug($m[2]);
            if ($level === 2) {
                $toc[] = [$id, $m[2]];
            }
            $html .= "<h$level id=\"" . htmlspecialchars($id) . "\">" . md_inline($m[2]) . "</h$level>\n";
            continue;
        }
        if (preg_match('/^(-{3,}|\*{3,})\s*$/', $line)) {
            $flushAll();
            $html .= "<hr>\n";
            continue;
        }
        if (preg_match('/^\|.*\|\s*$/', $line) && isset($lines[$i + 1]) && preg_match('/^\|[\s:|-]+\|\s*$/', $lines[$i + 1])) {
            $flushAll();
            $cells = fn($l) => array_map('trim', explode('|', trim(trim($l), '|')));
            $html .= '<div class="table-wrap"><table><thead><tr>'
                . implode('', array_map(fn($c) => '<th>' . md_inline($c) . '</th>', $cells($line))) . '</tr></thead><tbody>';
            for ($i += 2; $i < $n && preg_match('/^\|.*\|\s*$/', $lines[$i]); $i++) {
                $html .= '<tr>' . implode('', array_map(fn($c) => '<td>' . md_inline($c) . '</td>', $cells($lines[$i]))) . '</tr>';
            }
            $i--;
            $html .= "</tbody></table></div>\n";
            continue;
        }
        if (preg_match('/^>\s?(.*)$/', $line, $m)) {
            $flushPara();
            $flushList();
            $quote[] = $m[1];
            continue;
        }
        $isUl = (bool)preg_match('/^\s*[-*]\s+(.*)$/', $line, $mu);
        $isOl = !$isUl && preg_match('/^\s*\d+\.\s+(.*)$/', $line, $mo);
        if ($isUl || $isOl) {
            $flushPara();
            $flushQuote();
            $type = $isUl ? 'ul' : 'ol';
            if ($list && $list !== $type) {
                $flushList();
            }
            $list = $type;
            $listItems[] = $isUl ? $mu[1] : $mo[1];
            continue;
        }
        if ($list && preg_match('/^\s{2,}(\S.*)$/', $line, $m)) {
            $listItems[count($listItems) - 1] .= ' ' . $m[1]; // continuation line
            continue;
        }
        $flushList();
        $flushQuote();
        $para[] = trim($line);
    }
    $flushAll();
    return [$html, $toc];
}
