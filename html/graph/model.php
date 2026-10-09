<?php
declare(strict_types=1);
/**
 * Action `graph_model_set` (provider_name, provider_kind, model_alias, model_identifier, base_url — the kernel's ledger proxy by default —, embedding_model,
 * prompt_template, token = the K24 credential pasted ONCE and stored by MaluDB only, rebuild_confirmed when the embedding model changes): dc_graph_config_set;
 * the prompt's types validated (registered ones only); graph.manage; pauses for an agent (other). Logged graph.model_set — never the token.
 */
require_once dirname(__DIR__, 2) . '/app/features/graph/handler.php';
dc_handler_begin();
require_right('graph.manage');
$pdo = db();
if (!graph_engine_up()) {
    emit_action_status(false, ['error' => graph_unavailable_sentence(), 'code' => 'graph_unavailable']);
    if (wants_json()) {
        json_response(['ok' => false, 'error' => ['code' => 'graph_unavailable', 'message' => graph_unavailable_sentence()]], 503);
    }
    refuse(503, graph_unavailable_sentence());
}
$errors = [];
$cfg = [];
foreach (['provider_name' => 'openai', 'provider_kind' => 'openai', 'model_alias' => '', 'model_identifier' => '', 'base_url' => 'http://127.0.0.1:8816/openai', 'embedding_model' => ''] as $k => $default) {
    $v = trim((string) (req_val($k) ?? ''));
    $cfg[$k] = $v === '' ? $default : $v;
    if ($cfg[$k] === '') {
        $errors[$k] = 'This is needed.';
    }
}
if (!in_array($cfg['provider_kind'], ['openai', 'anthropic', 'local_http'], true)) {
    $errors['provider_kind'] = 'The provider is openai, anthropic or local_http (the ledger proxy speaks the first two).';
}
$prompt = req_has('prompt_template') ? (string) req_val('prompt_template') : dc_graph_prompt_template();
if (trim($prompt) === '') {
    $prompt = dc_graph_prompt_template();
}
if (!str_contains($prompt, '{{chunk}}')) {
    $errors['prompt_template'] = 'The prompt must carry {{chunk}} where the text goes.';
} elseif (($unknown = dc_graph_prompt_unknown_types($prompt)) !== []) {
    $errors['prompt_template'] = 'The prompt names a subject type the engine does not know: ' . implode(', ', $unknown) . '. Registered: ' . implode(', ', DC_GRAPH_SUBJECT_TYPES) . '.';
}
$cfg['prompt_template'] = $prompt;
$token = (string) (req_val('token') ?? '');
if ($token !== '') {
    $cfg['token'] = $token;
    $cfg['secret_name'] = 'doccloud-graph';
}
$current = graph_model($pdo);
$prevEmbed = (string) ($current['config']['embedding_model'] ?? $current['settings']['embedding_model'] ?? '');
if ($prevEmbed !== '' && $prevEmbed !== $cfg['embedding_model'] && !dc_yes('rebuild_confirmed')) {
    $errors['rebuild_confirmed'] = 'Changing the embedding model means re-sending every document; say rebuild_confirmed=yes.';
}
if ($errors !== []) {
    dc_refuse_fields($errors);
}
$r = dc_guard($pdo, static function () use ($pdo, $cfg, $prevEmbed, $token): array {
    $r = graph_model_set($pdo, $cfg);
    if ($r['status'] !== 'ok') {
        throw new DomainException('The engine refused the model: ' . ($r['error'] ?? $r['status']));
    }
    log_activity($pdo, 'graph.model_set', 'settings', 1, ['after' => ['provider' => $cfg['provider_name'], 'kind' => $cfg['provider_kind'], 'model_alias' => $cfg['model_alias'], 'embedding_model' => $cfg['embedding_model'],
        'base_url' => $cfg['base_url'], 'credential' => $token !== '' ? 'set' : 'kept', 'embedding_changed' => $prevEmbed !== '' && $prevEmbed !== $cfg['embedding_model']]]);
    return $r;
});
unset($token, $cfg['token']);
dc_done('Set the graph model to ' . $cfg['model_alias'] . ' with embeddings by ' . $cfg['embedding_model'], 1, dc_land('/graph/admin', 'model', 'graph-model'), 'graphChanged', ['model_alias' => $cfg['model_alias'], 'embedding_model' => $cfg['embedding_model']]);
