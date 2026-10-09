<?php
/**
 * Slice 6's helpers on top of slice 4's (seed_texts(): the Board with acme-msa.pdf (Marco), minutes-jan.docx, Priya's my-notes.md, Marco's for-ann.pdf shared with the guest Ann, the handbook in a
 * shared library everyone views, Engineering's design.pdf): documents with REAL bytes on disk (the parts upload), the clock moved by SQL (a trash, a lock, an upload, a version aged), the
 * worker's step and its report, the fake MaluMail's and the fake kernel's logs, and a folder tree for the trash.
 */
require dirname(__DIR__) . '/slice4/lib.php';
/** A document with real bytes on disk, uploaded by member $m into $folder; returns its id. */
function real_doc(int $m, int $folder, string $name, string $seed, int $bytes = 4000): int
{
    $u = upload_file($m, $folder, tmpfile_of($bytes, $seed, $name));
    return (int) ($u['finish']['json']['record_id'] ?? 0);
}
/** The blob file of a document's current version: [path on disk, blob id]. */
function blob_of(int $doc): array
{
    $r = q('SELECT b.id, b.storage_path FROM documents d JOIN document_versions v ON v.id = d.current_version_id JOIN blobs b ON b.id = v.blob_id WHERE d.id = :d', ['d' => $doc])[0];
    return [store() . '/' . $r['storage_path'], (int) $r['id']];
}
function age_trash(int $doc, int $days): void { pdo()->prepare("UPDATE documents SET trashed_at = now() - make_interval(days => :d) WHERE id = :id")->execute(['d' => $days, 'id' => $doc]); }
function age_folder_trash(int $folder, int $days): void { pdo()->prepare("UPDATE folders SET trashed_at = now() - make_interval(days => :d) WHERE trashed_at IS NOT NULL AND path LIKE (SELECT path || '%' FROM folders WHERE id = :id)")->execute(['d' => $days, 'id' => $folder]); }
function age_blobs(): void { pdo()->exec("UPDATE blobs SET created_at = now() - interval '2 hours'"); }
function exists_doc(int $id): bool { return (bool) one('SELECT count(*) FROM documents WHERE id = :id', ['id' => $id]); }
function folder_in(int $parent, string $name, int $by = 27): int
{
    pdo()->exec("SELECT set_config('app.member_id', '$by', false)");
    pdo()->prepare("INSERT INTO folders (library_id, parent_id, name, owner_member_id, created_by) VALUES ((SELECT library_id FROM folders WHERE id = :p), :p, :n, :by, :by) ON CONFLICT DO NOTHING")->execute(['p' => $parent, 'n' => $name, 'by' => $by]);
    return (int) one('SELECT id FROM folders WHERE parent_id = :p AND lower(name) = lower(:n) AND trashed_at IS NULL', ['p' => $parent, 'n' => $name]);
}
/** The fake MaluMail's log (one JSON per send) and the fake kernel's text log. */
function mail_log(): array { $f = (string) getenv('FAKE_MALUMAIL_LOG'); return array_values(array_filter(array_map(static fn ($l) => json_decode($l, true), file($f, FILE_IGNORE_NEW_LINES) ?: []))); }
function sms_log(): array { $f = need('FAKE_KERNEL_STATE') . '.sms'; return is_file($f) ? array_values(array_filter(array_map(static fn ($l) => json_decode($l, true), file($f, FILE_IGNORE_NEW_LINES) ?: []))) : []; }
/** One outbox row made by dc_notify for a member (the person's preferences decide the channels); returns the outbox ids. */
function notify(int $m, string $kind, string $title, string $dedupe): array
{
    pdo()->prepare('SELECT dc_notify(:m, :k, :t, :b, NULL, NULL, NULL, NULL, NULL, :d)')->execute(['m' => $m, 'k' => $kind, 't' => $title, 'b' => 'Body of ' . $title, 'd' => $dedupe]);
    return array_map('intval', array_column(q('SELECT id FROM notification_outbox WHERE dedupe_key LIKE :d ORDER BY id', ['d' => $dedupe . '%']), 'id'));
}
function outbox_row(int $id): array { return q('SELECT * FROM notification_outbox WHERE id = :id', ['id' => $id])[0]; }
/** Make an outbox row of a channel directly (to a given address). */
function queue_mail(int $m, string $to, string $subject): int
{
    return (int) one("INSERT INTO notification_outbox (channel, member_id, to_email, kind, subject, body) VALUES ('email', :m, :to, 'share_received', :s, 'a body') RETURNING id", ['m' => $m, 'to' => $to, 's' => $subject]);
}
function queue_text(int $m, string $body): int
{
    return (int) one("INSERT INTO notification_outbox (channel, member_id, kind, subject, body) VALUES ('text', :m, 'request_received', 'Request', :b) RETURNING id", ['m' => $m, 'b' => $body]);
}
function settings_set(string $col, $v): void { pdo()->prepare("UPDATE dc_settings SET $col = :v WHERE id = 1")->execute(['v' => $v]); }
/** is_file() without PHP's stat cache (the worker is another process). */
function file_here(string $p): bool { clearstatcache(true, $p); return is_file($p); }
