<?php
declare(strict_types=1);

/**
 * The `full` policy's extraction prompt — the namespace's prompt_template MaluDB fills with each chunk ({{chunk}}) and sends to the
 * model (design §6.3, D8; docs/build-specs/document-graph.md §5). It names REGISTERED subject types only: an extraction naming an
 * unknown type fails AFTER the model was paid (tests/phase0/engine/FINDINGS.md). The admin may edit it on the model screen; the
 * type list is validated on save (dc_graph_prompt_types_ok). It ends with "Text:" and the chunk, as the engine's own prompt does.
 */
const DC_GRAPH_SUBJECT_TYPES = ['project', 'person', 'ai_agent', 'equipment', 'software', 'network', 'event', 'process', 'workflow', 'time_period', 'other', 'stakeholder', 'concept'];
const DC_GRAPH_PROMPT_TYPES = ['person', 'stakeholder', 'project', 'equipment', 'software', 'event', 'time_period', 'process', 'workflow', 'concept', 'other'];

function dc_graph_prompt_template(): string
{
    return "From the text below, extract the people, organisations, places, products, amounts, dates and topics it names as SUBJECTS, and what the text says about each as STATEMENTS.\n"
        . "Each subject has a type from THIS LIST ONLY: " . implode(', ', DC_GRAPH_PROMPT_TYPES) . ".\n"
        . "An organisation or a company is a stakeholder; a date, a month or a period is a time_period; a product, a contract term, an amount or a topic is a concept; a place is other.\n"
        . "Each statement carries a verbatim source_span copied from the text, never paraphrased. If a subject fits none of the types, use:\n"
        . "SUBJECT TYPE: concept\n"
        . "Answer JSON only, in this shape: {\"candidate_edges\": [{\"subject_text\": \"…\", \"subject_type\": \"…\", \"verb_text\": \"…\", \"predicate\": [{\"attr_name\": \"detail\", \"value_text\": \"…\"}], \"source_span\": \"…\", \"confidence\": 0.9}]}\n"
        . "Text:\n{{chunk}}";
}

/** Every type the prompt names after "type" or "SUBJECT TYPE:" must be registered; returns the unknown ones (empty = fine). */
function dc_graph_prompt_unknown_types(string $template): array
{
    $unknown = [];
    if (preg_match_all('/SUBJECT TYPE:\s*([a-z_]+)/i', $template, $m)) {
        foreach ($m[1] as $t) {
            if (!in_array(strtolower($t), DC_GRAPH_SUBJECT_TYPES, true)) {
                $unknown[] = strtolower($t);
            }
        }
    }
    if (preg_match('/THIS LIST ONLY:\s*([a-z_,\s]+)\./i', $template, $m)) {
        foreach (preg_split('/[,\s]+/', strtolower($m[1])) ?: [] as $t) {
            if ($t !== '' && !in_array($t, DC_GRAPH_SUBJECT_TYPES, true)) {
                $unknown[] = $t;
            }
        }
    }
    return array_values(array_unique($unknown));
}

function dc_graph_prompt_types_ok(string $template): bool
{
    return str_contains($template, '{{chunk}}') && dc_graph_prompt_unknown_types($template) === [];
}
