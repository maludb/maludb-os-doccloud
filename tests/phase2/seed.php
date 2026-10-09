<?php
/** Seeds the proofs' SMOKE store (idempotent) and prints its ids as JSON — the browser proof calls this between its empty and its listed home. */
require __DIR__ . '/lib.php';
echo json_encode(seed_store()), "\n";
