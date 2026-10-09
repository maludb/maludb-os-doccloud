<?php
declare(strict_types=1);

/**
 * The outbox's text door: a text to a MEMBER through the kernel's K6 (`POST {OS_INTERNAL_URL}/api/v1/notify/sms.php`, the application's token — app/api/kernel.php
 * kernel_send_text()). The kernel decides everything about the person (their verified phone, a grant, an opt-out, 30 a day): DocCloud holds no Twilio key.
 * Answers the sender's shape: status sent | skipped | retry, the detail (the kernel's code), the provider reference.
 */
function outbox_send_text(array $row): array
{
    [$outcome, $code, $ref] = kernel_send_text((int) $row['member_id'], (string) ($row['subject'] !== null && $row['subject'] !== '' ? $row['subject'] . ': ' : '') . $row['body'], (string) $row['kind'] . ':' . ($row['record_id'] ?? $row['id']));
    return match ($outcome) {
        'sent' => ['status' => 'sent', 'detail' => null, 'ref' => $ref],
        'skipped' => ['status' => 'skipped', 'detail' => $code, 'ref' => null],        // no_sender, opted_out, rate_limited, not_held, no_verified_phone …
        'unconfigured' => ['status' => 'skipped', 'detail' => 'unconfigured', 'ref' => null],
        default => ['status' => 'retry', 'detail' => $code ?? 'unreachable', 'ref' => null],
    };
}
