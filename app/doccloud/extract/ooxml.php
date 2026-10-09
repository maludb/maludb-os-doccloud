<?php
declare(strict_types=1);

/**
 * Office Open XML (DOCX, PPTX, XLSX) read as the XML inside the zip: ZipArchive + DOMDocument, never a converter.
 * The zip is checked before a byte is inflated (entries, declared sizes, the compression ratio) and every part is read
 * through a capped stream, so a zip bomb that lies in its directory still stops at the cap. A part carrying a DOCTYPE
 * is refused (OOXML never has one; entity expansion is the other bomb).
 */

const DC_NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
const DC_NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
const DC_NS_P = 'http://schemas.openxmlformats.org/presentationml/2006/main';
const DC_NS_S = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const DC_NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
const DC_NS_PR = 'http://schemas.openxmlformats.org/package/2006/relationships';

/**
 * Open a zip after the guard: at most DC_ZIP_MAX_ENTRIES entries (5,000), DC_ZIP_MAX_UNCOMPRESSED bytes in all
 * (200 MB) and an overall inflation ratio of DC_ZIP_MAX_RATIO (100). Returns [ZipArchive|null, error|null].
 */
function dc_zip_open(string $path): array
{
    $z = new ZipArchive();
    if ($z->open($path, ZipArchive::RDONLY) !== true) {
        return [null, 'unreadable'];
    }
    $maxEntries = dc_env_int('DC_ZIP_MAX_ENTRIES', 5000);
    $maxTotal = dc_env_int('DC_ZIP_MAX_UNCOMPRESSED', 209715200);
    $maxRatio = dc_env_int('DC_ZIP_MAX_RATIO', 100);
    if ($z->numFiles > $maxEntries) {
        $z->close();
        return [null, 'zip_bomb'];
    }
    $total = 0;
    $packed = 0;
    for ($i = 0; $i < $z->numFiles; $i++) {
        $st = $z->statIndex($i);
        if ($st === false) {
            $z->close();
            return [null, 'unreadable'];
        }
        $total += (int) $st['size'];
        $packed += (int) $st['comp_size'];
        if ((int) $st['size'] > 1048576 && (int) $st['size'] > $maxRatio * max(1, (int) $st['comp_size'])) {
            $z->close();
            return [null, 'zip_bomb'];
        }
    }
    if ($total > $maxTotal || ($total > 1048576 && $total > $maxRatio * max(1, $packed, (int) filesize($path)))) {
        $z->close();
        return [null, 'zip_bomb'];
    }
    return [$z, null];
}

/** One part's bytes through a capped stream (the directory may lie); null when absent; throws on the cap. */
function dc_zip_part(ZipArchive $z, string $name): ?string
{
    if ($z->locateName($name) === false) {
        return null;
    }
    $cap = dc_env_int('DC_ZIP_MAX_PART', 104857600);
    $fh = $z->getStream($name);
    if ($fh === false) {
        return null;
    }
    $out = '';
    while (!feof($fh)) {
        $chunk = fread($fh, 1048576);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $out .= $chunk;
        if (strlen($out) > $cap) {
            fclose($fh);
            throw new RuntimeException('zip_bomb');
        }
    }
    fclose($fh);
    return $out;
}

/** A part as a DOM (no network, no DOCTYPE, no entity substitution); null when absent or malformed. */
function dc_zip_xml(ZipArchive $z, string $name): ?DOMDocument
{
    $xml = dc_zip_part($z, $name);
    if ($xml === null || $xml === '') {
        return null;
    }
    if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
        throw new RuntimeException('unsafe_xml');
    }
    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $ok ? $dom : null;
}

/** A part's relationships: Id => absolute part name (Target resolved against the part's folder). */
function dc_zip_rels(ZipArchive $z, string $part): array
{
    $dir = dirname($part);
    $relsName = ($dir === '.' ? '' : $dir . '/') . '_rels/' . basename($part) . '.rels';
    $dom = dc_zip_xml($z, $relsName);
    $out = [];
    if ($dom === null) {
        return $out;
    }
    foreach ($dom->getElementsByTagNameNS(DC_NS_PR, 'Relationship') as $r) {
        if (strtolower($r->getAttribute('TargetMode')) === 'external') {
            continue;
        }
        $t = $r->getAttribute('Target');
        $abs = str_starts_with($t, '/') ? ltrim($t, '/') : dc_path_join($dir === '.' ? '' : $dir, $t);
        $out[$r->getAttribute('Id')] = ['target' => $abs, 'type' => $r->getAttribute('Type')];
    }
    return $out;
}

function dc_path_join(string $dir, string $rel): string
{
    $parts = $dir === '' ? [] : explode('/', $dir);
    foreach (explode('/', $rel) as $p) {
        if ($p === '..') {
            array_pop($parts);
        } elseif ($p !== '.' && $p !== '') {
            $parts[] = $p;
        }
    }
    return implode('/', $parts);
}

// ---- DOCX --------------------------------------------------------------------------------------------------------

function dc_read_docx(ZipArchive $z): array
{
    $doc = dc_zip_xml($z, 'word/document.xml');
    if ($doc === null) {
        throw new RuntimeException('unreadable');
    }
    $levels = []; // styleId => heading level
    $lang = null;
    $styles = dc_zip_xml($z, 'word/styles.xml');
    if ($styles !== null) {
        $xp = new DOMXPath($styles);
        $xp->registerNamespace('w', DC_NS_W);
        foreach ($xp->query('//w:style[@w:type="paragraph"]') ?: [] as $s) {
            $id = $s->getAttributeNS(DC_NS_W, 'styleId');
            $name = strtolower((string) ($xp->query('w:name/@w:val', $s)->item(0)?->nodeValue ?? ''));
            $outline = $xp->query('w:pPr/w:outlineLvl/@w:val', $s)->item(0)?->nodeValue;
            if (preg_match('/^heading ?([1-9])$/', $name, $m) || preg_match('/^Heading([1-9])$/', $id, $m)) {
                $levels[$id] = (int) $m[1];
            } elseif ($name === 'title' || $id === 'Title') {
                $levels[$id] = 1;
            } elseif ($outline !== null && ctype_digit($outline) && (int) $outline < 9) {
                $levels[$id] = (int) $outline + 1;
            }
        }
        $l = $xp->query('//w:docDefaults//w:lang/@w:val')->item(0)?->nodeValue;
        $lang = $l === null ? null : dc_language_tag($l);
    } else {
        foreach (range(1, 9) as $n) {
            $levels['Heading' . $n] = $n;
        }
        $levels['Title'] = 1;
    }
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('w', DC_NS_W);
    $body = $xp->query('/w:document/w:body')->item(0);
    $tb = dc_tb_new();
    if ($body !== null) {
        dc_docx_walk($body, $xp, $levels, $tb);
    }
    return dc_tb_finish($tb) + ['language' => $lang];
}

function dc_docx_walk(DOMNode $node, DOMXPath $xp, array $levels, array &$tb): void
{
    foreach ($node->childNodes as $el) {
        if (!$el instanceof DOMElement || $el->namespaceURI !== DC_NS_W) {
            continue;
        }
        if ($el->localName === 'p') {
            $text = dc_docx_para_text($el);
            $style = $xp->query('w:pPr/w:pStyle/@w:val', $el)->item(0)?->nodeValue;
            $outline = $xp->query('w:pPr/w:outlineLvl/@w:val', $el)->item(0)?->nodeValue;
            $level = $style !== null && isset($levels[$style]) ? $levels[$style] : (($outline !== null && ctype_digit($outline) && (int) $outline < 9) ? (int) $outline + 1 : null);
            if ($level !== null && trim($text) !== '') {
                dc_tb_heading($tb, $text, $level);
            } else {
                dc_tb_para($tb, $text);
            }
        } elseif ($el->localName === 'tbl') {
            $rows = [];
            foreach ($xp->query('w:tr', $el) ?: [] as $tr) {
                $cells = [];
                foreach ($xp->query('w:tc', $tr) ?: [] as $tc) {
                    $parts = [];
                    foreach ($xp->query('.//w:p', $tc) ?: [] as $p) {
                        $parts[] = dc_docx_para_text($p);
                    }
                    $c = dc_one_line(implode(' ', $parts));
                    if ($c !== '') {
                        $cells[] = $c;
                    }
                }
                if ($cells !== []) {
                    $rows[] = implode(' | ', $cells);
                }
            }
            dc_tb_lines($tb, $rows);
        } elseif (in_array($el->localName, ['sdt', 'sdtContent', 'customXml', 'smartTag', 'ins'], true)) {
            dc_docx_walk($el, $xp, $levels, $tb);
        }
    }
}

/** A paragraph's text: runs, tabs and breaks, inserted text kept, deleted text dropped. */
function dc_docx_para_text(DOMElement $p): string
{
    $out = '';
    $walk = static function (DOMNode $n) use (&$walk, &$out): void {
        foreach ($n->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            if ($c->namespaceURI === DC_NS_W) {
                switch ($c->localName) {
                    case 't':
                        $out .= $c->textContent;
                        continue 2;
                    case 'tab':
                        $out .= "\t";
                        continue 2;
                    case 'br':
                    case 'cr':
                        $out .= "\n";
                        continue 2;
                    case 'del':
                    case 'delText':
                    case 'instrText':
                    case 'pPr':
                    case 'rPr':
                        continue 2;
                }
            }
            $walk($c);
        }
    };
    $walk($p);
    return $out;
}

// ---- PPTX --------------------------------------------------------------------------------------------------------

function dc_read_pptx(ZipArchive $z): array
{
    $pres = dc_zip_xml($z, 'ppt/presentation.xml');
    if ($pres === null) {
        throw new RuntimeException('unreadable');
    }
    $rels = dc_zip_rels($z, 'ppt/presentation.xml');
    $xp = new DOMXPath($pres);
    $xp->registerNamespace('p', DC_NS_P);
    $xp->registerNamespace('r', DC_NS_R);
    $tb = dc_tb_new();
    $n = 0;
    foreach ($xp->query('/p:presentation/p:sldIdLst/p:sldId') ?: [] as $sid) {
        $rid = $sid->getAttributeNS(DC_NS_R, 'id');
        if (!isset($rels[$rid])) {
            continue;
        }
        $n++;
        $slidePath = $rels[$rid]['target'];
        $slide = dc_zip_xml($z, $slidePath);
        dc_tb_page_start($tb, $n);
        $title = null;
        $paras = [];
        if ($slide !== null) {
            [$title, $paras] = dc_pptx_shapes($slide, false);
        }
        dc_tb_heading($tb, $title ?? ('Slide ' . $n), 1, 'slide');
        foreach ($paras as $p) {
            dc_tb_para($tb, $p);
        }
        foreach (dc_zip_rels($z, $slidePath) as $r) {
            if (str_ends_with($r['type'], '/notesSlide')) {
                $notes = dc_zip_xml($z, $r['target']);
                if ($notes !== null) {
                    [, $np] = dc_pptx_shapes($notes, true);
                    if ($np !== []) {
                        dc_tb_para($tb, "Notes:\n" . implode("\n", $np));
                    }
                }
            }
        }
        dc_tb_page_end($tb);
    }
    return dc_tb_finish($tb);
}

/** [title, paragraphs] of a slide (or, for notes, the body placeholders' paragraphs only). */
function dc_pptx_shapes(DOMDocument $d, bool $notes): array
{
    $xp = new DOMXPath($d);
    $xp->registerNamespace('p', DC_NS_P);
    $xp->registerNamespace('a', DC_NS_A);
    $title = null;
    $paras = [];
    foreach ($xp->query('//p:sp') ?: [] as $sp) {
        $ph = $xp->query('p:nvSpPr/p:nvPr/p:ph', $sp)->item(0);
        $type = $ph instanceof DOMElement ? ($ph->getAttribute('type') ?: 'body') : null;
        if ($notes && $type !== 'body') {
            continue;
        }
        $lines = [];
        foreach ($xp->query('p:txBody/a:p', $sp) ?: [] as $ap) {
            $t = '';
            foreach ($ap->childNodes as $c) {
                if ($c instanceof DOMElement && $c->localName === 'r') {
                    $t .= $xp->query('a:t', $c)->item(0)?->textContent ?? '';
                } elseif ($c instanceof DOMElement && $c->localName === 'br') {
                    $t .= "\n";
                } elseif ($c instanceof DOMElement && $c->localName === 'fld') {
                    $t .= $xp->query('a:t', $c)->item(0)?->textContent ?? '';
                }
            }
            if (trim($t) !== '') {
                $lines[] = $t;
            }
        }
        if ($lines === []) {
            continue;
        }
        if (!$notes && $title === null && in_array($type, ['title', 'ctrTitle'], true)) {
            $title = dc_one_line(implode(' ', $lines));
            continue;
        }
        if (in_array($type, ['sldNum', 'dt', 'ftr', 'hdr'], true)) {
            continue;
        }
        $paras[] = implode("\n", $lines);
    }
    foreach ($xp->query('//a:tbl') ?: [] as $tbl) {
        $rows = [];
        foreach ($xp->query('a:tr', $tbl) ?: [] as $tr) {
            $cells = [];
            foreach ($xp->query('a:tc', $tr) ?: [] as $tc) {
                $c = dc_one_line($tc->textContent);
                if ($c !== '') {
                    $cells[] = $c;
                }
            }
            if ($cells !== []) {
                $rows[] = implode(' | ', $cells);
            }
        }
        if ($rows !== []) {
            $paras[] = implode("\n", $rows);
        }
    }
    return [$title, $paras];
}

// ---- XLSX --------------------------------------------------------------------------------------------------------

function dc_read_xlsx(ZipArchive $z): array
{
    $wb = dc_zip_xml($z, 'xl/workbook.xml');
    if ($wb === null) {
        throw new RuntimeException('unreadable');
    }
    $rels = dc_zip_rels($z, 'xl/workbook.xml');
    $shared = [];
    $ss = dc_zip_xml($z, 'xl/sharedStrings.xml');
    if ($ss !== null) {
        $xs = new DOMXPath($ss);
        $xs->registerNamespace('s', DC_NS_S);
        foreach ($xs->query('/s:sst/s:si') ?: [] as $si) {
            $t = '';
            foreach ($xs->query('.//s:t[not(ancestor::s:rPh)]', $si) ?: [] as $tn) {
                $t .= $tn->textContent;
            }
            $shared[] = $t;
        }
    }
    $xp = new DOMXPath($wb);
    $xp->registerNamespace('s', DC_NS_S);
    $xp->registerNamespace('r', DC_NS_R);
    $tb = dc_tb_new();
    $n = 0;
    foreach ($xp->query('/s:workbook/s:sheets/s:sheet') ?: [] as $sheet) {
        $rid = $sheet->getAttributeNS(DC_NS_R, 'id');
        if (!isset($rels[$rid]) || strtolower($sheet->getAttribute('state')) === 'veryhidden') {
            continue;
        }
        $n++;
        $name = $sheet->getAttribute('name') ?: ('Sheet ' . $n);
        dc_tb_page_start($tb, $n);
        dc_tb_heading($tb, $name, 1, 'sheet');
        $ws = dc_zip_xml($z, $rels[$rid]['target']);
        if ($ws !== null) {
            dc_tb_lines($tb, dc_xlsx_rows($ws, $shared));
        }
        dc_tb_page_end($tb);
    }
    return dc_tb_finish($tb);
}

/** A worksheet's rows as "header: value | …" lines; the first non-empty row is the header. */
function dc_xlsx_rows(DOMDocument $ws, array $shared): array
{
    $xp = new DOMXPath($ws);
    $xp->registerNamespace('s', DC_NS_S);
    $header = null;
    $lines = [];
    foreach ($xp->query('/s:worksheet/s:sheetData/s:row') ?: [] as $row) {
        $cells = [];
        $auto = 0;
        foreach ($xp->query('s:c', $row) ?: [] as $c) {
            $ref = $c->getAttribute('r');
            $col = preg_match('/^([A-Z]+)/', $ref, $m) ? dc_xlsx_col($m[1]) : $auto;
            $auto = $col + 1;
            $type = $c->getAttribute('t');
            $v = $xp->query('s:v', $c)->item(0)?->textContent;
            $val = match ($type) {
                's' => $v !== null && ctype_digit(trim($v)) ? ($shared[(int) $v] ?? '') : '',
                'inlineStr' => (string) ($xp->query('s:is', $c)->item(0)?->textContent ?? ''),
                'b' => $v === '1' ? 'TRUE' : 'FALSE',
                default => (string) ($v ?? ''),
            };
            $val = dc_one_line($val);
            if ($val !== '') {
                $cells[$col] = $val;
            }
        }
        if ($cells === []) {
            continue;
        }
        if ($header === null) {
            $header = $cells;
            continue;
        }
        ksort($cells);
        $pairs = [];
        foreach ($cells as $col => $val) {
            $pairs[] = ($header[$col] ?? dc_xlsx_col_name($col)) . ': ' . $val;
        }
        $lines[] = implode(' | ', $pairs);
    }
    if ($lines === [] && $header !== null) {
        ksort($header);
        $lines[] = implode(' | ', $header);
    }
    return $lines;
}

function dc_xlsx_col(string $letters): int
{
    $n = 0;
    foreach (str_split($letters) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

function dc_xlsx_col_name(int $col): string
{
    $s = '';
    for ($n = $col + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $s = chr(65 + ($n - 1) % 26) . $s;
    }
    return 'Column ' . $s;
}
