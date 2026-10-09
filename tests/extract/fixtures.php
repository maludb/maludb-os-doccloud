<?php
declare(strict_types=1);

/**
 * Synthetic fixtures for the extraction proof (tests/extract/run.php), generated at run time — no third-party text is ever committed.
 * The prose is made from a fixed word list by a seeded generator, so every run builds the same bytes.
 */

const FX_WORDS = ['policy', 'leave', 'carry', 'over', 'request', 'manager', 'approve', 'record', 'within', 'days', 'staff',
    'member', 'annual', 'balance', 'notice', 'office', 'review', 'quarter', 'budget', 'travel', 'claim', 'receipt', 'shall',
    'may', 'should', 'each', 'the', 'a', 'of', 'to', 'and', 'for', 'with', 'by', 'on', 'is', 'are', 'any', 'all', 'remote',
    'equipment', 'training', 'safety', 'report', 'incident', 'schedule', 'holiday', 'payroll', 'benefit', 'contract'];

/** Deterministic sentences: $n words from a seed, capitalised, ending in a period. */
function fx_sentence(int $seed, int $n = 12): string
{
    $w = [];
    $x = $seed * 2654435761 % 4294967296;
    for ($i = 0; $i < $n; $i++) {
        $x = ($x * 1103515245 + 12345) % 2147483648;
        $w[] = FX_WORDS[$x % count(FX_WORDS)];
    }
    return ucfirst(implode(' ', $w)) . '.';
}

/** A paragraph of roughly $chars characters. */
function fx_para(int $seed, int $chars): string
{
    $out = '';
    $i = 0;
    while (strlen($out) < $chars) {
        $out .= ($out === '' ? '' : ' ') . fx_sentence($seed * 100 + $i++);
    }
    return $out;
}

function fx_dir(): string
{
    static $d = null;
    if ($d === null) {
        $d = sys_get_temp_dir() . '/dc-extract-' . getmypid();
        @mkdir($d, 0700, true);
    }
    return $d;
}

function fx_write(string $name, string $bytes): string
{
    $p = fx_dir() . '/' . $name;
    file_put_contents($p, $bytes);
    return $p;
}

function fx_cleanup(): void
{
    foreach (glob(fx_dir() . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir(fx_dir());
}

function fx_zip(string $name, array $parts): string
{
    $p = fx_dir() . '/' . $name;
    @unlink($p);
    $z = new ZipArchive();
    $z->open($p, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($parts as $k => $v) {
        $z->addFromString($k, $v);
    }
    $z->close();
    return $p;
}

function fx_x(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// ---- DOCX: [Content_Types], package rels, document + styles (Normal, Title, Heading1–3), a table ----------------------

/** $body: list of ['h1'|'h2'|'h3'|'title'|'p', text] or ['table', [[cells], …]]. */
function fx_docx(string $name, array $body, bool $withStyles = true): string
{
    $W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . ($withStyles ? '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>' : '')
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '</Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '</Relationships>';
    $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>SMOKE synthetic handbook</dc:title><dc:creator>sectioning proof</dc:creator></cp:coreProperties>';
    $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . ($withStyles ? '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' : '')
        . '</Relationships>';
    $style = static function (string $id, string $name, ?int $outline) use ($W): string {
        return '<w:style w:type="paragraph" w:styleId="' . $id . '"><w:name w:val="' . $name . '"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
            . '<w:pPr><w:keepNext/>' . ($outline === null ? '' : '<w:outlineLvl w:val="' . $outline . '"/>') . '</w:pPr><w:rPr><w:b/><w:sz w:val="' . (40 - 4 * ($outline ?? -1)) . '"/></w:rPr></w:style>';
    };
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:styles xmlns:w="' . $W . '">'
        . '<w:docDefaults><w:rPrDefault><w:rPr><w:lang w:val="en-GB"/></w:rPr></w:rPrDefault><w:pPrDefault/></w:docDefaults>'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
        . $style('Title', 'Title', null) . $style('Heading1', 'heading 1', 0) . $style('Heading2', 'heading 2', 1) . $style('Heading3', 'heading 3', 2)
        . '<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/></w:style>'
        . '</w:styles>';
    $map = ['title' => 'Title', 'h1' => 'Heading1', 'h2' => 'Heading2', 'h3' => 'Heading3'];
    $xml = '';
    foreach ($body as [$t, $v]) {
        if ($t === 'table') {
            $xml .= '<w:tbl><w:tblPr><w:tblStyle w:val="TableNormal"/><w:tblW w:w="0" w:type="auto"/></w:tblPr><w:tblGrid>'
                . str_repeat('<w:gridCol w:w="3000"/>', count($v[0])) . '</w:tblGrid>';
            foreach ($v as $row) {
                $xml .= '<w:tr>';
                foreach ($row as $cell) {
                    $xml .= '<w:tc><w:tcPr><w:tcW w:w="3000" w:type="dxa"/></w:tcPr><w:p><w:r><w:t xml:space="preserve">' . fx_x($cell) . '</w:t></w:r></w:p></w:tc>';
                }
                $xml .= '</w:tr>';
            }
            $xml .= '</w:tbl>';
            continue;
        }
        $ppr = isset($map[$t]) ? '<w:pPr><w:pStyle w:val="' . $map[$t] . '"/></w:pPr>' : '';
        $xml .= '<w:p>' . $ppr . '<w:r><w:t xml:space="preserve">' . fx_x($v) . '</w:t></w:r></w:p>';
    }
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="' . $W . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>' . $xml
        . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/></w:sectPr>'
        . '</w:body></w:document>';
    $parts = ['[Content_Types].xml' => $ct, '_rels/.rels' => $rels, 'docProps/core.xml' => $core, 'word/_rels/document.xml.rels' => $docRels, 'word/document.xml' => $doc];
    if ($withStyles) {
        $parts['word/styles.xml'] = $styles;
    }
    return fx_zip($name, $parts);
}

// ---- PPTX: presentation, one master, one layout, a theme, slides with title + body placeholders, notes -------------

/** $slides: list of ['title' => ?string, 'body' => [lines], 'notes' => ?string]. */
function fx_pptx(string $name, array $slides): string
{
    $P = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';
    $hdr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $R = static fn(array $rels): string => $hdr . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . implode('', array_map(static fn($r) => '<Relationship Id="' . $r[0] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/' . $r[1] . '" Target="' . $r[2] . '"/>', $rels)) . '</Relationships>';
    $grp = '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>';
    $sp = static function (int $id, string $nm, string $ph, array $paras): string {
        $tx = '';
        foreach ($paras as $p) {
            $tx .= '<a:p><a:r><a:rPr lang="en-US" dirty="0"/><a:t>' . fx_x($p) . '</a:t></a:r></a:p>';
        }
        if ($tx === '') {
            $tx = '<a:p><a:endParaRPr lang="en-US"/></a:p>';
        }
        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="' . $nm . '"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr>' . $ph . '</p:nvPr></p:nvSpPr><p:spPr/>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/>' . $tx . '</p:txBody></p:sp>';
    };
    $theme = $hdr . '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Office Theme"><a:themeElements>'
        . '<a:clrScheme name="Office"><a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1><a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1>'
        . '<a:dk2><a:srgbClr val="44546A"/></a:dk2><a:lt2><a:srgbClr val="E7E6E6"/></a:lt2><a:accent1><a:srgbClr val="4472C4"/></a:accent1><a:accent2><a:srgbClr val="ED7D31"/></a:accent2>'
        . '<a:accent3><a:srgbClr val="A5A5A5"/></a:accent3><a:accent4><a:srgbClr val="FFC000"/></a:accent4><a:accent5><a:srgbClr val="5B9BD5"/></a:accent5><a:accent6><a:srgbClr val="70AD47"/></a:accent6>'
        . '<a:hlink><a:srgbClr val="0563C1"/></a:hlink><a:folHlink><a:srgbClr val="954F72"/></a:folHlink></a:clrScheme>'
        . '<a:fontScheme name="Office"><a:majorFont><a:latin typeface="Calibri Light"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont><a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont></a:fontScheme>'
        . '<a:fmtScheme name="Office"><a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst>'
        . '<a:lnStyleLst><a:ln w="6350"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln><a:ln w="12700"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln><a:ln w="19050"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln></a:lnStyleLst>'
        . '<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'
        . '<a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:bgFillStyleLst></a:fmtScheme>'
        . '</a:themeElements></a:theme>';
    $master = $hdr . '<p:sldMaster ' . $P . '><p:cSld><p:spTree>' . $grp . '</p:spTree></p:cSld>'
        . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
        . '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst></p:sldMaster>';
    $layout = $hdr . '<p:sldLayout ' . $P . ' type="obj" preserve="1"><p:cSld name="Title and Content"><p:spTree>' . $grp . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>';
    $notesMaster = $hdr . '<p:notesMaster ' . $P . '><p:cSld><p:spTree>' . $grp . '</p:spTree></p:cSld>'
        . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/></p:notesMaster>';
    $parts = [
        'ppt/theme/theme1.xml' => $theme,
        'ppt/slideMasters/slideMaster1.xml' => $master,
        'ppt/slideMasters/_rels/slideMaster1.xml.rels' => $R([['rId1', 'slideLayout', '../slideLayouts/slideLayout1.xml'], ['rId2', 'theme', '../theme/theme1.xml']]),
        'ppt/slideLayouts/slideLayout1.xml' => $layout,
        'ppt/slideLayouts/_rels/slideLayout1.xml.rels' => $R([['rId1', 'slideMaster', '../slideMasters/slideMaster1.xml']]),
        'ppt/notesMasters/notesMaster1.xml' => $notesMaster,
        'ppt/notesMasters/_rels/notesMaster1.xml.rels' => $R([['rId1', 'theme', '../theme/theme1.xml']]),
    ];
    $ctOver = '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
        . '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
        . '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
        . '<Override PartName="/ppt/notesMasters/notesMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.notesMaster+xml"/>'
        . '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>';
    $presRels = [['rId1', 'slideMaster', 'slideMasters/slideMaster1.xml'], ['rId2', 'theme', 'theme/theme1.xml'], ['rId3', 'notesMaster', 'notesMasters/notesMaster1.xml']];
    $sldIds = '';
    foreach ($slides as $i => $s) {
        $n = $i + 1;
        $tree = $grp;
        if ($s['title'] !== null) {
            $tree .= $sp(2, 'Title ' . $n, '<p:ph type="title"/>', [$s['title']]);
        }
        $tree .= $sp(3, 'Content ' . $n, '<p:ph idx="1"/>', $s['body']);
        $tree .= $sp(4, 'Slide Number ' . $n, '<p:ph type="sldNum" sz="quarter" idx="12"/>', [(string) $n]);
        $parts['ppt/slides/slide' . $n . '.xml'] = $hdr . '<p:sld ' . $P . '><p:cSld><p:spTree>' . $tree . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';
        $srels = [['rId1', 'slideLayout', '../slideLayouts/slideLayout1.xml']];
        $ctOver .= '<Override PartName="/ppt/slides/slide' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
        if (($s['notes'] ?? null) !== null) {
            $srels[] = ['rId2', 'notesSlide', '../notesSlides/notesSlide' . $n . '.xml'];
            $parts['ppt/notesSlides/notesSlide' . $n . '.xml'] = $hdr . '<p:notes ' . $P . '><p:cSld><p:spTree>' . $grp
                . $sp(2, 'Slide Image ' . $n, '<p:ph type="sldImg"/>', [])
                . $sp(3, 'Notes ' . $n, '<p:ph type="body" idx="1"/>', [$s['notes']])
                . $sp(4, 'Slide Number ' . $n, '<p:ph type="sldNum" sz="quarter" idx="5"/>', [(string) $n])
                . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:notes>';
            $parts['ppt/notesSlides/_rels/notesSlide' . $n . '.xml.rels'] = $R([['rId1', 'notesMaster', '../notesMasters/notesMaster1.xml'], ['rId2', 'slide', '../slides/slide' . $n . '.xml']]);
            $ctOver .= '<Override PartName="/ppt/notesSlides/notesSlide' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.notesSlide+xml"/>';
        }
        $parts['ppt/slides/_rels/slide' . $n . '.xml.rels'] = $R($srels);
        $presRels[] = ['rId' . (10 + $n), 'slide', 'slides/slide' . $n . '.xml'];
        $sldIds .= '<p:sldId id="' . (255 + $n) . '" r:id="rId' . (10 + $n) . '"/>';
    }
    $parts['ppt/presentation.xml'] = $hdr . '<p:presentation ' . $P . ' saveSubsetFonts="1">'
        . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
        . '<p:notesMasterIdLst><p:notesMasterId r:id="rId3"/></p:notesMasterIdLst>'
        . '<p:sldIdLst>' . $sldIds . '</p:sldIdLst><p:sldSz cx="12192000" cy="6858000"/><p:notesSz cx="6858000" cy="9144000"/></p:presentation>';
    $parts['ppt/_rels/presentation.xml.rels'] = $R($presRels);
    $parts['[Content_Types].xml'] = $hdr . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>' . $ctOver . '</Types>';
    $parts['_rels/.rels'] = $R([['rId1', 'officeDocument', 'ppt/presentation.xml']]);
    return fx_zip($name, $parts);
}

// ---- XLSX: workbook, sheets, shared strings, one inline string, numbers and a boolean ------------------------------

/** $sheets: name => list of rows (list of scalars; a string goes to shared strings, '=inline:x' as an inline string). */
function fx_xlsx(string $name, array $sheets): string
{
    $hdr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $S = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $shared = [];
    $sid = static function (string $s) use (&$shared): int {
        if (!isset($shared[$s])) {
            $shared[$s] = count($shared);
        }
        return $shared[$s];
    };
    $parts = [];
    $ctOver = '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
    $wbSheets = '';
    $wbRels = '';
    $i = 0;
    foreach ($sheets as $sname => $rows) {
        $i++;
        $data = '';
        foreach ($rows as $r => $row) {
            $data .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($row) as $c => $v) {
                $ref = chr(65 + $c) . ($r + 1);
                if ($v === null) {
                    continue;
                }
                if (is_bool($v)) {
                    $data .= '<c r="' . $ref . '" t="b"><v>' . ($v ? 1 : 0) . '</v></c>';
                } elseif (is_int($v) || is_float($v)) {
                    $data .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
                } elseif (str_starts_with($v, '=inline:')) {
                    $data .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . fx_x(substr($v, 8)) . '</t></is></c>';
                } else {
                    $data .= '<c r="' . $ref . '" t="s"><v>' . $sid($v) . '</v></c>';
                }
            }
            $data .= '</row>';
        }
        $parts['xl/worksheets/sheet' . $i . '.xml'] = $hdr . '<worksheet xmlns="' . $S . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetData>' . $data . '</sheetData></worksheet>';
        $ctOver .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wbSheets .= '<sheet name="' . fx_x((string) $sname) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $wbRels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
    }
    $sst = '';
    foreach (array_keys($shared) as $s) {
        $sst .= '<si><t xml:space="preserve">' . fx_x((string) $s) . '</t></si>';
    }
    $parts['xl/sharedStrings.xml'] = $hdr . '<sst xmlns="' . $S . '" count="' . count($shared) . '" uniqueCount="' . count($shared) . '">' . $sst . '</sst>';
    $parts['xl/styles.xml'] = $hdr . '<styleSheet xmlns="' . $S . '"><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $n = $i;
    $parts['xl/workbook.xml'] = $hdr . '<workbook xmlns="' . $S . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbSheets . '</sheets></workbook>';
    $parts['xl/_rels/workbook.xml.rels'] = $hdr . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels
        . '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="rId' . ($n + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>';
    $parts['[Content_Types].xml'] = $hdr . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>' . $ctOver . '</Types>';
    $parts['_rels/.rels'] = $hdr . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    return fx_zip($name, $parts);
}

// ---- PDF written by hand: Helvetica text pages, or image-only pages; xref offsets computed ----------------------------

function fx_pdf_str(string $s): string
{
    return '(' . strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
}

/** $pages: list of list of lines ('' leaves a blank line). $imageOnly: every page is one grey image and no text. */
function fx_pdf(array $pages, bool $imageOnly = false): string
{
    $objs = [];
    $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $img = str_repeat("\x80\x40\xC0\x20", 16);
    $objs[4] = "<< /Type /XObject /Subtype /Image /Width 8 /Height 8 /ColorSpace /DeviceGray /BitsPerComponent 8 /Length " . strlen($img) . " >>\nstream\n" . $img . "\nendstream";
    $kids = [];
    $next = 5;
    foreach ($pages as $lines) {
        if ($imageOnly) {
            $content = "q 200 0 0 200 72 500 cm /Im1 Do Q";
        } else {
            $content = "BT /F1 11 Tf 15 TL 72 760 Td\n";
            foreach ($lines as $l) {
                $content .= ($l === '' ? '' : fx_pdf_str($l) . " Tj ") . "T*\n";
            }
            $content .= 'ET';
        }
        $cid = $next++;
        $pid = $next++;
        $objs[$cid] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        $objs[$pid] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> /XObject << /Im1 4 0 R >> >> /Contents ' . $cid . ' 0 R >>';
        $kids[] = $pid . ' 0 R';
    }
    $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($objs);
    $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objs as $id => $body) {
        $offsets[$id] = strlen($out);
        $out .= $id . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($out);
    $max = max(array_keys($objs));
    $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= $max; $i++) {
        $out .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
    }
    $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    return $out;
}

/** Wrap prose into lines of at most $w characters (for PDF pages). */
function fx_wrap(string $s, int $w = 85): array
{
    return explode("\n", wordwrap($s, $w, "\n", true));
}
