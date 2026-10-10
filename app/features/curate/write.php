<?php
declare(strict_types=1);

/** Writes of the Curate area (slice 9): the ownership transfer. SQL only; the controller judges and logs. */

/** Move the documents of one member to another (dc_ownership_transfer, db/007), all or one library's. Returns how many moved. The new owner must be admitted here — the database says so. */
function ownership_transfer(PDO $pdo, int $from, int $to, ?int $libraryId, int $by): int
{
    $st = $pdo->prepare('SELECT dc_ownership_transfer(:f, :t, :l)');
    $st->bindValue('f', $from, PDO::PARAM_INT);
    $st->bindValue('t', $to, PDO::PARAM_INT);
    $st->bindValue('l', $libraryId, $libraryId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->execute();
    return (int) $st->fetchColumn();
}

/** A CSV field safe to open in a spreadsheet: a leading = + - @ or tab is defused with a quote, quotes doubled. */
function csv_field(mixed $v): string
{
    $s = (string) ($v ?? '');
    if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        $s = "'" . $s;
    }
    return '"' . str_replace('"', '""', $s) . '"';
}

/** Rows as CSV text (CRLF, a header row first). */
function csv_text(array $header, array $rows): string
{
    $out = implode(',', array_map('csv_field', $header)) . "\r\n";
    foreach ($rows as $r) {
        $out .= implode(',', array_map('csv_field', $r)) . "\r\n";
    }
    return $out;
}
