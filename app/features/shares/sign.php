<?php
declare(strict_types=1);

/**
 * The signed download URL of the K7 share `document_link` (slice 7, docs/build-specs/api-keys-k7.md divergence 1). Bytes never cross K7's JSON: a sibling is handed a URL
 * good for ten minutes, for ONE version of ONE document, bound to the consumer, signed with a key DERIVED from the tenant's ACTION_TOKEN_KEY (nothing new to install; rotating
 * the kernel's key kills every live URL, which is right; the label keeps a URL from ever being a token the kernel would accept).
 *   /x/{document}/{version}/{expires}/{consumer}/{sig}     sig = hmac_sha256(dc_link_signing_key(), "document|version|expires|consumer")
 */

/** The derived signing key. */
function dc_link_signing_key(): string
{
    return hash_hmac('sha256', 'doccloud-link-signing-v1', action_token_key());
}

function signed_download_sig(int $document, int $version, int $expires, string $consumer): string
{
    return hash_hmac('sha256', $document . '|' . $version . '|' . $expires . '|' . $consumer, dc_link_signing_key());
}

/** The URL for $doc (a documents row: id) at $version (a document_versions row: id) for $consumer, valid $ttl seconds. */
function signed_download_url(array $doc, array $version, string $consumer, int $ttl = 600): string
{
    $d = (int) ($doc['id'] ?? $doc['document_id']);
    $v = (int) ($version['id'] ?? $version['version_id']);
    $expires = time() + $ttl;
    return app_url('/x/' . $d . '/' . $v . '/' . $expires . '/' . $consumer . '/' . signed_download_sig($d, $v, $expires, $consumer));
}

/** The facts of a signed path ([document, version, expires, consumer]) when its signature is good and it has not expired; null otherwise (the same nothing for every failure). */
function signed_download_verify(string $path): ?array
{
    if (!preg_match('#^/x/([0-9]{1,18})/([0-9]{1,18})/([0-9]{9,12})/([a-z][a-z0-9_]{0,39})/([a-f0-9]{64})$#', $path, $m)) {
        return null;
    }
    try {
        $expect = signed_download_sig((int) $m[1], (int) $m[2], (int) $m[3], $m[4]);
    } catch (Throwable) {
        return null;                                   // no key configured: nothing verifies
    }
    if (!hash_equals($expect, $m[5]) || (int) $m[3] <= time()) {
        return null;
    }
    return ['document_id' => (int) $m[1], 'version_id' => (int) $m[2], 'expires' => (int) $m[3], 'consumer' => $m[4]];
}
