<?php
/**
 * Proof: the store and the graph are WIRED, not used (sso-shell.md "Proof" 4): health answers storage: ok and graph: unavailable; with an
 * unwritable storage directory health answers storage: unwritable and the home still renders; store_judge_type() judges an executable renamed
 * .pdf, an SVG named .txt, a PDF, a PNG, a Markdown file and an empty file as the design says; the relative blob path agrees with the database's.
 * Run through tests/phase2/run.sh.
 */
require __DIR__ . '/lib.php';
$root = dirname(__DIR__, 2);
echo "1. Health\n";
$h = json_decode(req('GET', '/api/v1/health')['body'], true) ?: [];
ok(($h['application'] ?? '') === 'doccloud' && ($h['storage'] ?? '') === 'ok' && ($h['graph'] ?? '') === 'unavailable' && ($h['database'] ?? '') === 'ok', 'health: application doccloud, storage ok, graph unavailable (no token), database ok');
echo "2. The judgement of a file, through store.php\n";
$probe = tempnam(sys_get_temp_dir(), 'dcst') . '.php';
file_put_contents($probe, '<?php require ' . var_export($root . '/app/bootstrap.php', true) . ';
$d = sys_get_temp_dir() . "/dc-store-" . getmypid(); @mkdir($d);
$cases = ["exe_pdf" => ["invoice.pdf", "MZ\x90\x00\x03" . str_repeat("\0", 300)], "svg_txt" => ["notes.txt", "<svg xmlns=\"http://www.w3.org/2000/svg\" onload=\"alert(1)\"></svg>"],
          "pdf" => ["rule.pdf", "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"], "png" => ["a.png", base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==")],
          "md" => ["readme.md", "# Hello\n\nSome words.\n"], "empty" => ["empty.txt", ""], "html_pdf" => ["page.pdf", "<!DOCTYPE html><html><script>1</script></html>"], "sh" => ["run.sh", "#!/bin/sh\necho hi\n"]];
foreach ($cases as $k => [$name, $bytes]) { $f = "$d/$k"; file_put_contents($f, $bytes); $j = store_judge_type($f, $name); echo $k, "\t", $j["mime"], "\t", $j["kind"], "\t", $j["inline_ok"] ? "inline" : "attachment", "\t", $j["refused_reason"] ?? "-", "\t", $j["empty"] ? "empty" : "bytes", "\n"; }
echo "rel\t", dc_blob_relative(str_repeat("ab", 32)), "\n";
echo "safe\t", dc_safe_filename("..\\\\evil/../\x01name  .pdf"), "\n";
try { dc_safe_filename(".."); echo "safe2\tno\n"; } catch (DomainException $e) { echo "safe2\trefused\n"; }
try { dc_storage_path("../etc/passwd"); echo "climb\tno\n"; } catch (DomainException $e) { echo "climb\trefused\n"; }
array_map("unlink", glob("$d/*")); rmdir($d);
');
$out = (string) shell_exec('php ' . escapeshellarg($probe) . ' 2>&1');
unlink($probe);
$rows = [];
foreach (explode("\n", trim($out)) as $line) { $c = explode("\t", $line); $rows[$c[0]] = array_slice($c, 1); }
ok(($rows['exe_pdf'][2] ?? '') === 'attachment' && ($rows['exe_pdf'][3] ?? '') !== '-', 'an executable renamed .pdf: stored, never inline (judged from the bytes): ' . implode(' ', $rows['exe_pdf'] ?? []));
ok(($rows['svg_txt'][2] ?? '') === 'attachment', 'an SVG named .txt: never inline: ' . implode(' ', $rows['svg_txt'] ?? []));
ok(($rows['html_pdf'][2] ?? '') === 'attachment', 'HTML named .pdf: never inline: ' . implode(' ', $rows['html_pdf'] ?? []));
ok(($rows['sh'][2] ?? '') === 'attachment', 'a shell script: never inline');
ok(($rows['pdf'][0] ?? '') === 'application/pdf' && ($rows['pdf'][1] ?? '') === 'pdf' && ($rows['pdf'][2] ?? '') === 'inline', 'a PDF: application/pdf, kind pdf, inline');
ok(($rows['png'][0] ?? '') === 'image/png' && ($rows['png'][1] ?? '') === 'image' && ($rows['png'][2] ?? '') === 'inline', 'a PNG: image/png, kind image, inline');
ok(($rows['md'][0] ?? '') === 'text/markdown' && ($rows['md'][1] ?? '') === 'text' && ($rows['md'][2] ?? '') === 'inline', 'a Markdown file: text/markdown by its extension among text kinds, inline');
ok(($rows['empty'][4] ?? '') === 'empty', 'an empty file is marked empty (the one thing refused for storage)');
ok(($rows['rel'][0] ?? '') === 'blobs/ab/ab/' . str_repeat('ab', 32), 'the relative blob path is blobs/aa/bb/<sha256>');
ok(($rows['rel'][0] ?? '') === one("SELECT storage_path FROM dc_blob_claim(:s, 1)", ['s' => str_repeat('ab', 32)]), '...and agrees with the database\'s dc_blob_claim');
ok(($rows['safe'][0] ?? '') === 'name  .pdf' && ($rows['safe2'][0] ?? '') === 'refused' && ($rows['climb'][0] ?? '') === 'refused', 'dc_safe_filename takes the basename and drops control characters; ".." and a climbing store path are refused');
echo "3. An unwritable store\n";
$ro = sys_get_temp_dir() . '/dc-ro-' . getmypid();
mkdir($ro, 0500);
$probe2 = tempnam(sys_get_temp_dir(), 'dcst') . '.php';
file_put_contents($probe2, '<?php putenv("DC_STORAGE_DIR=' . $ro . '"); require ' . var_export($root . '/app/bootstrap.php', true) . '; echo dc_storage_state();');
$state = trim((string) shell_exec('php ' . escapeshellarg($probe2) . ' 2>&1'));
unlink($probe2);
@chmod($ro, 0700); @rmdir($ro);
ok($state === 'unwritable' || posix_getuid() === 0, 'with a read-only storage directory the store says unwritable (' . $state . ')' . (posix_getuid() === 0 ? ' — skipped as root' : ''));
[$j, ] = sign_on(26);
ok(page($j, '/')['code'] === 200, 'the home still renders whatever the store says');
finish();
