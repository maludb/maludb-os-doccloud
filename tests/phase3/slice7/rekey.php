<?php
/** Slice 7 proof 6 — ACTION_TOKEN_KEY rewritten (the servers restarted on it): every live document_link URL answers 404; every other share still works; a link made under the new key serves; the old key restored, the old URL serves again (the signing key is DERIVED from the kernel's, nothing else is installed). */
require __DIR__ . '/lib.php';
$s = seed_texts();
$contracts = $s['contracts'];
share_folder_with($contracts, 'knowledge');
$kn = app_caller('knowledge', 70);
$real = real_doc(27, $contracts, 'rekey-bytes.bin', 'rk', 2000);
$bytes = file_get_contents(blob_of($real)[0]);
$envf = need('DC_DEV_ENV'); $orig = file_get_contents($envf);
$restart = static function (): void { shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && DC_DEV_ENV=' . escapeshellarg(getenv('DC_DEV_ENV')) . ' DC_DEV_STATE=' . escapeshellarg(getenv('DC_DEV_STATE')) . ' tests/phase2/servers.sh start >/dev/null 2>&1'); };

echo "1. Before\n";
$old = door('share_link', $kn, ['document' => $real])['json']['url']; $oldPath = parse_url($old, PHP_URL_PATH);
ok(req('GET', $oldPath)['body'] === $bytes, 'a live URL serves the bytes');

echo "2. ACTION_TOKEN_KEY rewritten\n";
$newKey = bin2hex(random_bytes(32));
file_put_contents($envf, preg_replace('/^ACTION_TOKEN_KEY=.*$/m', 'ACTION_TOKEN_KEY=' . $newKey, $orig));
$restart();
$r = req('GET', $oldPath);
ok($r['code'] === 404 && $r['body'] === 'Not found.', 'the URL made under the old key: 404');
ok(door('share_folders', $kn)['code'] === 200 && count(door('share_folders', $kn)['json']['folders']) >= 1, 'folders_index still answers');
ok(door('share_documents', $kn, ['folder' => $contracts])['code'] === 200 && door('share_text', $kn, ['document' => $s['board_doc']])['code'] === 200, 'documents_index and document_text still answer');
$fresh = door('share_link', $kn, ['document' => $real]);
$np = parse_url($fresh['json']['url'] ?? '', PHP_URL_PATH);
ok($fresh['code'] === 200 && $np !== $oldPath && req('GET', $np)['body'] === $bytes, 'a link made under the new key serves the bytes');
$sig = hash_hmac('sha256', "{$real}|" . $fresh['json']['version_id'] . '|' . explode('/', $np)[4] . '|knowledge', hash_hmac('sha256', 'doccloud-link-signing-v1', $newKey));
ok(str_ends_with($np, '/' . $sig), 'its signature is exactly hmac(derived key, "document|version|expires|consumer") with the derived key hmac("doccloud-link-signing-v1", ACTION_TOKEN_KEY)');
ok(strlen((string) env_value($envf, 'ACTION_TOKEN_KEY')) === 64 && !preg_match('/LINK_SIGN|SIGNING_KEY/i', $orig), 'nothing new is in the environment: there is no signing key to install');

echo "3. The old key restored\n";
file_put_contents($envf, $orig);
$restart();
ok(req('GET', $oldPath)['body'] === $bytes, 'the old URL serves again (it was only ever the derived key that was missing)');
ok(req('GET', $np)['code'] === 404, '...and the new key\'s URL no longer does');
function env_value(string $f, string $k): ?string { return preg_match('/^' . $k . '=(.*)$/m', (string) file_get_contents($f), $m) ? $m[1] : null; }
finish();
