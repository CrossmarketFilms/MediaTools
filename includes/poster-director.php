<?php
if (!defined('ABSPATH')) { exit; }

if (!function_exists('validate_poster_director_manifest')) {
    function validate_poster_director_manifest($manifest, $actor_registry = []) {
        return CMSG_Poster_Director::validate_manifest($manifest, $actor_registry);
    }
}

if (function_exists('add_action')) {
    add_action('cmsg_poster_director_structured_async', ['CMSG_Poster_Director', 'run_structured_async'], 10, 2);
}

final class CMSG_Poster_Director {
    const MANIFEST_VERSION = 'phase1-diagnostic-v4.1';
    const STRUCTURED_MODEL_FILTER = 'cmsg_poster_director_structured_model';
    const STRUCTURED_TIMEOUT_FILTER = 'cmsg_poster_director_structured_timeout';

    public static function queue_diagnostic_manifest($brief, $actor_registry, $draft_id) {
        $draft_id = intval($draft_id);
        $hashes = self::hashes($brief, $actor_registry);
        $paths = self::diagnostic_paths($draft_id, $hashes['brief_hash']);

        if (empty($paths)) {
            error_log('CMSG POSTER DIRECTOR PRIVATE STORAGE FAIL: draft_id=' . $draft_id . ' reason=path_unavailable');
            return false;
        }

        if (file_exists($paths['deterministic']) && file_exists($paths['quality_report'])) {
            error_log('CMSG POSTER DIRECTOR CACHE HIT: draft_id=' . $draft_id . ' brief_hash=' . $hashes['brief_hash']);
            self::schedule_structured_enhancement($draft_id, $hashes['brief_hash'], $paths);
            return true;
        }

        $analysis = CMSG_Poster_Story_Analyzer::analyze($brief, $actor_registry);
        $manifest = self::build_deterministic_manifest($analysis, $brief, $actor_registry, $draft_id, $hashes);
        $validation = self::validate_manifest($manifest, $actor_registry);
        $quality = self::quality_report($manifest, $validation, 'generated', 'structured_pending');

        $snapshot = [
            'draft_id' => $draft_id,
            'brief_hash' => $hashes['brief_hash'],
            'actor_registry_hash' => $hashes['actor_registry_hash'],
            'manifest_version' => self::MANIFEST_VERSION,
            'brief' => self::safe_brief($brief),
            'actor_registry' => self::safe_actor_registry($actor_registry),
            'created_at' => gmdate('c'),
        ];

        if (!self::write_json_file($paths['snapshot'], $snapshot)
            || !self::write_json_file($paths['deterministic'], $manifest)
            || !self::write_json_file($paths['quality_report'], $quality)) {
            error_log('CMSG POSTER DIRECTOR PRIVATE STORAGE FAIL: draft_id=' . $draft_id . ' reason=write_failed');
            return false;
        }

        error_log('CMSG POSTER DIRECTOR DIAGNOSTIC QUEUED: draft_id=' . $draft_id . ' brief_hash=' . $hashes['brief_hash']);
        self::schedule_structured_enhancement($draft_id, $hashes['brief_hash'], $paths);
        return true;
    }

    public static function run_structured_async($draft_id, $brief_hash) {
        $draft_id = intval($draft_id);
        $brief_hash = preg_replace('/[^a-f0-9]/', '', (string)$brief_hash);
        $paths = self::diagnostic_paths($draft_id, $brief_hash);

        if (empty($paths) || !file_exists($paths['snapshot'])) {
            error_log('CMSG POSTER DIRECTOR DIAGNOSTIC ASYNC FAIL: draft_id=' . $draft_id . ' reason=snapshot_missing');
            return false;
        }

        $started_at = gmdate('c');
        $start = microtime(true);
        self::append_quality_status($paths, 'structured_running', [], [
            'started_at' => $started_at,
            'cron_trigger_method' => 'wp_cron',
        ]);
        error_log('CMSG POSTER DIRECTOR DIAGNOSTIC ASYNC START: draft_id=' . $draft_id . ' brief_hash=' . $brief_hash);

        $snapshot = self::read_json_file($paths['snapshot']);
        if (empty($snapshot['brief']) || empty($snapshot['actor_registry'])) {
            self::append_quality_status($paths, 'structured_failed', ['snapshot_invalid'], self::async_timing($start, $started_at));
            error_log('CMSG POSTER DIRECTOR DIAGNOSTIC ASYNC FAIL: draft_id=' . $draft_id . ' reason=snapshot_invalid');
            return false;
        }

        $structured = self::build_structured_ai_manifest(
            $snapshot['brief'],
            $snapshot['actor_registry'],
            $draft_id,
            [
                'brief_hash' => $brief_hash,
                'actor_registry_hash' => (string)($snapshot['actor_registry_hash'] ?? ''),
            ]
        );

        if (empty($structured['ok']) || empty($structured['manifest'])) {
            $reason = sanitize_key($structured['reason'] ?? 'structured_failed');
            self::append_quality_status($paths, 'structured_failed', [$reason], self::async_timing($start, $started_at));
            error_log('CMSG POSTER DIRECTOR DIAGNOSTIC ASYNC FAIL: draft_id=' . $draft_id . ' reason=' . $reason);
            return false;
        }

        $validation = self::validate_manifest($structured['manifest'], $snapshot['actor_registry']);
        $quality = self::quality_report($structured['manifest'], $validation, 'structured_complete', $validation['status']);

        if (!self::write_json_file($paths['structured'], $structured['manifest'])
            || !self::write_json_file($paths['quality_report'], $quality)) {
            self::append_quality_status($paths, 'structured_failed', ['write_failed'], self::async_timing($start, $started_at));
            error_log('CMSG POSTER DIRECTOR DIAGNOSTIC ASYNC FAIL: draft_id=' . $draft_id . ' reason=write_failed');
            return false;
        }

        self::append_quality_status($paths, 'structured_complete', [], self::async_timing($start, $started_at));

        error_log('CMSG POSTER DIRECTOR DIAGNOSTIC ASYNC COMPLETE: draft_id=' . $draft_id . ' status=' . sanitize_key($validation['status']));
        return true;
    }

    private static function schedule_structured_enhancement($draft_id, $brief_hash, $paths) {
        if (file_exists($paths['structured'])) {
            return false;
        }

        if (!function_exists('wp_schedule_single_event')) {
            self::append_quality_status($paths, 'scheduling_failed', ['wp_schedule_single_event_unavailable'], [
                'scheduled_at' => null,
                'cron_trigger_method' => 'wp_cron_unavailable',
            ]);
            return false;
        }

        $args = [intval($draft_id), (string)$brief_hash];
        if (function_exists('wp_next_scheduled') && wp_next_scheduled('cmsg_poster_director_structured_async', $args)) {
            self::append_quality_status($paths, 'structured_pending', [], [
                'scheduled_at' => gmdate('c'),
                'cron_trigger_method' => 'wp_cron_existing',
            ]);
            return true;
        }

        $run_at = time() + 30;
        $scheduled = (bool)wp_schedule_single_event($run_at, 'cmsg_poster_director_structured_async', $args);
        self::append_quality_status($paths, $scheduled ? 'structured_pending' : 'scheduling_failed', $scheduled ? [] : ['wp_schedule_single_event_failed'], [
            'scheduled_at' => $scheduled ? gmdate('c', $run_at) : null,
            'cron_trigger_method' => 'wp_cron_single_event',
        ]);
        return $scheduled;
    }

    private static function build_structured_ai_manifest($brief, $actor_registry, $draft_id, $hashes) {
        if (!self::openai_key()) {
            return ['ok' => false, 'reason' => 'missing_openai_key'];
        }

        $schema = self::schema_definition();
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a film poster director. Return only one JSON object matching the requested schema. Do not include markdown.',
            ],
            [
                'role' => 'user',
                'content' => self::structured_prompt($brief, $actor_registry, $schema),
            ],
        ];

        error_log('CMSG POSTER DIRECTOR STRUCTURED REQUEST: draft_id=' . intval($draft_id));
        $result = self::call_openai_text_json($messages, $draft_id, 'initial');
        $manifest = is_array($result['json'] ?? null) ? $result['json'] : [];
        $manifest = self::normalize_structured_manifest($manifest, $brief, $actor_registry, $draft_id, $hashes, 'structured_ai_schema_valid');
        $validation = self::validate_manifest($manifest, $actor_registry);

        if (!empty($validation['valid']) && empty($validation['schema_errors'])) {
            error_log('CMSG POSTER DIRECTOR STRUCTURED VALID: draft_id=' . intval($draft_id));
            return ['ok' => true, 'manifest' => $manifest, 'status' => 'structured_ai_schema_valid'];
        }

        error_log('CMSG POSTER DIRECTOR STRUCTURED INVALID: draft_id=' . intval($draft_id) . ' reason=schema_invalid');
        $repair_messages = $messages;
        $repair_messages[] = [
            'role' => 'assistant',
            'content' => self::json($manifest),
        ];
        $repair_messages[] = [
            'role' => 'user',
            'content' => 'Repair the JSON so it fully matches the schema. Preserve actor IDs exactly. Validation errors: ' . self::json($validation['schema_errors']),
        ];

        $repair = self::call_openai_text_json($repair_messages, $draft_id, 'repair');
        $repaired = is_array($repair['json'] ?? null) ? $repair['json'] : [];
        $repaired = self::normalize_structured_manifest($repaired, $brief, $actor_registry, $draft_id, $hashes, 'structured_ai_schema_repaired');
        $repair_validation = self::validate_manifest($repaired, $actor_registry);

        if (!empty($repair_validation['valid']) && empty($repair_validation['schema_errors'])) {
            error_log('CMSG POSTER DIRECTOR STRUCTURED VALID: repaired draft_id=' . intval($draft_id));
            return ['ok' => true, 'manifest' => $repaired, 'status' => 'structured_ai_schema_repaired'];
        }

        error_log('CMSG POSTER DIRECTOR STRUCTURED INVALID: draft_id=' . intval($draft_id) . ' reason=repair_schema_invalid');
        return [
            'ok' => false,
            'reason' => 'structured_ai_schema_invalid',
            'schema_errors' => $repair_validation['schema_errors'],
        ];
    }

    private static function call_openai_text_json($messages, $draft_id, $phase) {
        $api_key = self::openai_key();
        if (!$api_key || !function_exists('wp_remote_post')) {
            return ['ok' => false, 'reason' => $api_key ? 'wp_remote_post_unavailable' : 'missing_openai_key'];
        }

        $model = (string)apply_filters(self::STRUCTURED_MODEL_FILTER, 'gpt-4o-mini');
        $timeout = max(5, min(25, intval(apply_filters(self::STRUCTURED_TIMEOUT_FILTER, 18))));
        $start = microtime(true);

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'timeout' => $timeout,
            'body' => wp_json_encode([
                'model' => $model,
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object'],
                'messages' => $messages,
            ]),
        ]);

        $duration_ms = (int)round((microtime(true) - $start) * 1000);
        if (is_wp_error($response)) {
            error_log('CMSG POSTER DIRECTOR STRUCTURED INVALID: draft_id=' . intval($draft_id) . ' phase=' . sanitize_key($phase) . ' model=' . sanitize_text_field($model) . ' duration_ms=' . $duration_ms . ' error=' . $response->get_error_code());
            return ['ok' => false, 'reason' => $response->get_error_code(), 'duration_ms' => $duration_ms];
        }

        $status = intval(wp_remote_retrieve_response_code($response));
        $body = (string)wp_remote_retrieve_body($response);
        if ($status < 200 || $status >= 300) {
            $api_error = self::safe_api_error_code($body);
            error_log('CMSG POSTER DIRECTOR STRUCTURED INVALID: draft_id=' . intval($draft_id) . ' phase=' . sanitize_key($phase) . ' model=' . sanitize_text_field($model) . ' http_status=' . $status . ' api_error=' . $api_error . ' duration_ms=' . $duration_ms);
            return ['ok' => false, 'reason' => 'http_' . $status, 'api_error' => $api_error, 'duration_ms' => $duration_ms];
        }

        $decoded = json_decode($body, true);
        $content = (string)($decoded['choices'][0]['message']['content'] ?? '');
        $json = json_decode($content, true);
        return [
            'ok' => is_array($json),
            'json' => is_array($json) ? $json : [],
            'reason' => is_array($json) ? '' : 'json_decode_failed',
            'duration_ms' => $duration_ms,
            'http_status' => $status,
            'model' => $model,
        ];
    }

    private static function build_deterministic_manifest($analysis, $brief, $actor_registry, $draft_id, $hashes) {
        $actors = [];
        foreach ((array)($analysis['actors'] ?? []) as $actor) {
            if (empty($actor['actor_id'])) continue;
            $actors[$actor['actor_id']] = [
                'actor_id' => (string)$actor['actor_id'],
                'registry_index' => intval($actor['registry_index'] ?? -1),
                'source_key' => (string)($actor['source_key'] ?? ''),
                'source_type' => (string)($actor['source_type'] ?? 'principal_cast'),
                'registry_match_method' => (string)($actor['registry_match_method'] ?? 'source_key'),
                'character_name' => (string)($actor['name'] ?? ''),
                'role' => in_array(($actor['role'] ?? ''), ['lead', 'supporting'], true) ? $actor['role'] : 'supporting',
                'visual_priority' => ($actor['role'] ?? '') === 'lead' ? 'primary' : 'secondary',
                'identity_constraints' => [
                    'must_match_uploaded_reference' => true,
                    'appear_exactly_once' => true,
                    'do_not_merge_or_duplicate' => true,
                ],
                'source_instruction' => (string)($actor['instruction'] ?? ''),
                'directives' => (array)($actor['directives'] ?? []),
            ];
        }

        $props = (array)($analysis['props_and_environment'] ?? []);
        $manifest = [
            'manifest_version' => self::MANIFEST_VERSION,
            'draft_id' => intval($draft_id),
            'brief_hash' => $hashes['brief_hash'],
            'actor_registry_hash' => $hashes['actor_registry_hash'],
            'attempt' => 1,
            'generated_at' => gmdate('c'),
            'generation_state' => 'generated',
            'interpretation_result' => 'deterministic_fallback',
            'interpretation_method' => 'deterministic_fallback',
            'schema_valid' => true,
            'creative_confidence' => self::confidence('deterministic_fallback', $actors),
            'creative_thesis' => [
                'logline' => self::brief_text($brief, 'poster_description'),
                'emotional_promise' => self::brief_text($brief, 'mood'),
                'genre_signal' => self::brief_text($brief, 'genre'),
            ],
            'poster_direction' => [
                'scene_direction' => self::brief_text($brief, 'poster_description'),
                'layout' => self::brief_text($brief, 'poster_layout') ?: 'auto',
                'style_preset' => self::brief_text($brief, 'style_preset'),
            ],
            'actors' => $actors,
            'composition' => [
                'vertical_strategy' => 'Use the selected layout and preserve actor hierarchy from the registry.',
                'banner_strategy' => 'Adapt the same story hierarchy to landscape without changing actor identity.',
                'hierarchy' => self::actor_hierarchy($actors),
            ],
            'lighting' => [
                'style' => self::brief_text($brief, 'mood') ?: 'cinematic',
                'contrast' => 'story appropriate',
                'source_direction' => 'director determined',
            ],
            'color_language' => [
                'palette' => [self::brief_text($brief, 'mood') ?: 'cinematic'],
                'temperature' => 'story appropriate',
                'avoid_global_filter_overuse' => true,
            ],
            'typography' => [
                'title' => self::brief_text($brief, 'title'),
                'tagline' => self::brief_text($brief, 'tagline'),
                'title_safe_area' => 'Reserve readable title space without covering primary faces.',
            ],
            'props_and_environment' => [
                'props' => $props['props'] ?? [],
                'visual_references' => $props['visual_references'] ?? [],
                'environment' => $props['environment'] ?? [],
            ],
            'constraints' => [
                'principal_cast_only_for_actor_layers' => true,
                'do_not_use_visual_references_as_actors' => true,
                'preserve_actor_identity' => true,
                'diagnostic_only' => true,
            ],
            'unresolved' => (array)($analysis['unresolved'] ?? []),
            'conflicts' => [],
            'confidence' => [
                'overall' => self::confidence('deterministic_fallback', $actors),
                'actor_mapping' => empty($actors) ? 0.4 : 0.82,
                'creative_specificity' => self::brief_text($brief, 'poster_description') ? 0.72 : 0.35,
            ],
        ];

        return $manifest;
    }

    private static function normalize_structured_manifest($manifest, $brief, $actor_registry, $draft_id, $hashes, $status) {
        $manifest = is_array($manifest) ? $manifest : [];
        $analysis = CMSG_Poster_Story_Analyzer::analyze($brief, $actor_registry);
        $fallback = self::build_deterministic_manifest($analysis, $brief, $actor_registry, $draft_id, $hashes);

        $manifest = array_replace_recursive($fallback, $manifest);
        $manifest['manifest_version'] = self::MANIFEST_VERSION;
        $manifest['draft_id'] = intval($draft_id);
        $manifest['brief_hash'] = $hashes['brief_hash'];
        $manifest['actor_registry_hash'] = $hashes['actor_registry_hash'];
        $manifest['generation_state'] = 'structured_complete';
        $manifest['interpretation_result'] = $status;
        $manifest['interpretation_method'] = 'structured_ai';
        $manifest['generated_at'] = gmdate('c');

        return $manifest;
    }

    public static function validate_manifest($manifest, $actor_registry = []) {
        $errors = [];
        $schema_errors = [];
        $registry = CMSG_Poster_Story_Analyzer::canonical_actor_registry($actor_registry);

        if (!is_array($manifest)) {
            return [
                'valid' => false,
                'schema_valid' => false,
                'schema_errors' => ['manifest: expected object'],
                'errors' => ['manifest_not_array'],
                'status' => 'structured_ai_schema_invalid',
                'interpretation_complete' => false,
                'creative_confidence' => 0,
                'unresolved_count' => 0,
                'fallback_limitations' => [],
            ];
        }

        $schema = self::schema_definition();
        $schema_errors = array_merge($schema_errors, self::validate_schema_node($manifest, $schema, 'manifest'));

        $actors = is_array($manifest['actors'] ?? null) ? $manifest['actors'] : [];
        foreach ($actors as $id => $actor) {
            $schema_errors = array_merge($schema_errors, self::validate_schema_node($actor, $schema['actor_item'], 'manifest.actors.' . $id));
        }

        $actor_ids = array_keys($actors);
        $registry_ids = array_keys($registry);
        sort($actor_ids);
        sort($registry_ids);

        if ($actor_ids !== $registry_ids) {
            $errors[] = 'actor_registry_mismatch';
        }

        foreach ($actors as $id => $actor) {
            if (!isset($registry[$id])) {
                $errors[] = 'actor_registry_match_failed:' . $id;
                continue;
            }

            if (!is_array($actor)) {
                $errors[] = 'actor_entry_invalid:' . $id;
                continue;
            }

            if (($actor['actor_id'] ?? '') !== $id) {
                $errors[] = 'actor_id_key_mismatch:' . $id;
            }

            if (!in_array(($actor['visual_priority'] ?? ''), ['primary', 'secondary', 'tertiary'], true)) {
                $errors[] = 'actor_visual_priority_invalid:' . $id;
            }

            $identity = $actor['identity_constraints'] ?? [];
            if (empty($identity['must_match_uploaded_reference']) || empty($identity['appear_exactly_once']) || empty($identity['do_not_merge_or_duplicate'])) {
                $errors[] = 'actor_identity_constraints_invalid:' . $id;
            }

            foreach ((array)($actor['directives'] ?? []) as $directive) {
                if (!is_array($directive)) continue;
                $scope = (string)($directive['source_scope'] ?? $id);
                $target = (string)($directive['target_actor_id'] ?? $id);
                if ($scope !== $id || $target !== $id) {
                    $errors[] = 'actor_directive_scope_pollution:' . $id;
                }
            }
        }

        $confidence = $manifest['confidence'] ?? [];
        foreach (['overall', 'actor_mapping', 'creative_specificity'] as $key) {
            $value = $confidence[$key] ?? null;
            if (!is_numeric($value) || $value < 0 || $value > 1) {
                $errors[] = 'confidence_invalid:' . $key;
            }
        }

        if (empty($manifest['composition']['vertical_strategy']) || empty($manifest['composition']['banner_strategy'])) {
            $errors[] = 'composition_strategy_missing';
        }

        $schema_valid = empty($schema_errors);
        $valid = $schema_valid && empty($errors);
        $method = (string)($manifest['interpretation_method'] ?? 'deterministic_fallback');
        $result = (string)($manifest['interpretation_result'] ?? '');
        $status = $valid
            ? ($method === 'structured_ai' ? ($result ?: 'structured_ai_schema_valid') : 'fallback_manifest_generated_with_warnings')
            : 'structured_ai_schema_invalid';

        $fallback_limitations = [];
        if ($method !== 'structured_ai') {
            $fallback_limitations[] = 'Deterministic parser captures obvious brief structure but does not claim final professional art-direction quality.';
            $fallback_limitations[] = 'Structured AI interpretation is pending or unavailable.';
        }

        return [
            'valid' => $valid,
            'schema_valid' => $schema_valid,
            'schema_errors' => $schema_errors,
            'errors' => $errors,
            'status' => $status,
            'interpretation_complete' => $method === 'structured_ai' && $valid,
            'creative_confidence' => floatval($manifest['confidence']['overall'] ?? 0),
            'unresolved_count' => is_array($manifest['unresolved'] ?? null) ? count($manifest['unresolved']) : 0,
            'fallback_limitations' => $fallback_limitations,
        ];
    }

    private static function validate_schema_node($value, $schema, $path) {
        $errors = [];
        $type = $schema['type'] ?? 'mixed';

        if ($type === 'object') {
            if (!is_array($value) || self::is_list($value)) {
                return [$path . ': expected object'];
            }
            foreach ((array)($schema['required'] ?? []) as $key) {
                if (!array_key_exists($key, $value)) {
                    $errors[] = $path . '.' . $key . ': required';
                }
            }
            foreach ((array)($schema['properties'] ?? []) as $key => $child) {
                if (array_key_exists($key, $value)) {
                    $errors = array_merge($errors, self::validate_schema_node($value[$key], $child, $path . '.' . $key));
                }
            }
            return $errors;
        }

        if ($type === 'array') {
            if (!is_array($value)) {
                return [$path . ': expected array'];
            }
            $item_schema = $schema['items'] ?? null;
            if ($item_schema) {
                foreach ($value as $i => $item) {
                    $errors = array_merge($errors, self::validate_schema_node($item, $item_schema, $path . '[' . $i . ']'));
                }
            }
            return $errors;
        }

        if ($type === 'string' && !is_string($value)) return [$path . ': expected string'];
        if ($type === 'number' && !is_numeric($value)) return [$path . ': expected number'];
        if ($type === 'boolean' && !is_bool($value)) return [$path . ': expected boolean'];
        if (!empty($schema['enum']) && !in_array($value, $schema['enum'], true)) return [$path . ': invalid enum'];
        if ($type === 'number') {
            if (isset($schema['minimum']) && $value < $schema['minimum']) $errors[] = $path . ': below minimum';
            if (isset($schema['maximum']) && $value > $schema['maximum']) $errors[] = $path . ': above maximum';
        }
        return $errors;
    }

    private static function schema_definition() {
        $actor = [
            'type' => 'object',
            'required' => ['actor_id', 'registry_index', 'source_key', 'source_type', 'registry_match_method', 'character_name', 'role', 'visual_priority', 'identity_constraints', 'source_instruction', 'directives'],
            'properties' => [
                'actor_id' => ['type' => 'string'],
                'registry_index' => ['type' => 'number'],
                'source_key' => ['type' => 'string'],
                'source_type' => ['type' => 'string'],
                'registry_match_method' => ['type' => 'string'],
                'character_name' => ['type' => 'string'],
                'role' => ['type' => 'string', 'enum' => ['lead', 'supporting']],
                'visual_priority' => ['type' => 'string', 'enum' => ['primary', 'secondary', 'tertiary']],
                'identity_constraints' => [
                    'type' => 'object',
                    'required' => ['must_match_uploaded_reference', 'appear_exactly_once', 'do_not_merge_or_duplicate'],
                    'properties' => [
                        'must_match_uploaded_reference' => ['type' => 'boolean'],
                        'appear_exactly_once' => ['type' => 'boolean'],
                        'do_not_merge_or_duplicate' => ['type' => 'boolean'],
                    ],
                ],
                'source_instruction' => ['type' => 'string'],
                'directives' => ['type' => 'array'],
            ],
        ];

        return [
            'type' => 'object',
            'required' => ['manifest_version', 'draft_id', 'brief_hash', 'actor_registry_hash', 'creative_thesis', 'poster_direction', 'actors', 'composition', 'lighting', 'color_language', 'typography', 'props_and_environment', 'constraints', 'unresolved', 'conflicts', 'confidence'],
            'properties' => [
                'manifest_version' => ['type' => 'string'],
                'draft_id' => ['type' => 'number'],
                'brief_hash' => ['type' => 'string'],
                'actor_registry_hash' => ['type' => 'string'],
                'creative_thesis' => ['type' => 'object', 'required' => ['logline', 'emotional_promise', 'genre_signal'], 'properties' => ['logline' => ['type' => 'string'], 'emotional_promise' => ['type' => 'string'], 'genre_signal' => ['type' => 'string']]],
                'poster_direction' => ['type' => 'object', 'required' => ['scene_direction', 'layout', 'style_preset'], 'properties' => ['scene_direction' => ['type' => 'string'], 'layout' => ['type' => 'string'], 'style_preset' => ['type' => 'string']]],
                'actors' => ['type' => 'object'],
                'composition' => ['type' => 'object', 'required' => ['vertical_strategy', 'banner_strategy', 'hierarchy'], 'properties' => ['vertical_strategy' => ['type' => 'string'], 'banner_strategy' => ['type' => 'string'], 'hierarchy' => ['type' => 'array']]],
                'lighting' => ['type' => 'object', 'required' => ['style', 'contrast', 'source_direction'], 'properties' => ['style' => ['type' => 'string'], 'contrast' => ['type' => 'string'], 'source_direction' => ['type' => 'string']]],
                'color_language' => ['type' => 'object', 'required' => ['palette', 'temperature', 'avoid_global_filter_overuse'], 'properties' => ['palette' => ['type' => 'array'], 'temperature' => ['type' => 'string'], 'avoid_global_filter_overuse' => ['type' => 'boolean']]],
                'typography' => ['type' => 'object', 'required' => ['title', 'tagline', 'title_safe_area'], 'properties' => ['title' => ['type' => 'string'], 'tagline' => ['type' => 'string'], 'title_safe_area' => ['type' => 'string']]],
                'props_and_environment' => ['type' => 'object', 'required' => ['props', 'visual_references', 'environment'], 'properties' => ['props' => ['type' => 'array'], 'visual_references' => ['type' => 'array'], 'environment' => ['type' => 'array']]],
                'constraints' => ['type' => 'object', 'required' => ['principal_cast_only_for_actor_layers', 'do_not_use_visual_references_as_actors', 'preserve_actor_identity', 'diagnostic_only'], 'properties' => ['principal_cast_only_for_actor_layers' => ['type' => 'boolean'], 'do_not_use_visual_references_as_actors' => ['type' => 'boolean'], 'preserve_actor_identity' => ['type' => 'boolean'], 'diagnostic_only' => ['type' => 'boolean']]],
                'unresolved' => ['type' => 'array'],
                'conflicts' => ['type' => 'array'],
                'confidence' => ['type' => 'object', 'required' => ['overall', 'actor_mapping', 'creative_specificity'], 'properties' => ['overall' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1], 'actor_mapping' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1], 'creative_specificity' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1]]],
            ],
            'actor_item' => $actor,
        ];
    }

    private static function quality_report($manifest, $validation, $generation_state, $structured_state) {
        return [
            'manifest_version' => self::MANIFEST_VERSION,
            'brief_hash' => (string)($manifest['brief_hash'] ?? ''),
            'actor_registry_hash' => (string)($manifest['actor_registry_hash'] ?? ''),
            'generated_at' => gmdate('c'),
            'generated' => $generation_state === 'generated',
            'cached' => false,
            'structured_status' => $structured_state,
            'structured_pending' => $structured_state === 'structured_pending',
            'structured_complete' => $structured_state === 'structured_complete',
            'structured_failed' => $structured_state === 'structured_failed',
            'scheduled_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'duration_ms' => null,
            'cron_trigger_method' => null,
            'status' => $validation['status'] ?? 'validation_unknown',
            'valid' => !empty($validation['valid']),
            'schema_valid' => !empty($validation['schema_valid']),
            'schema_errors' => $validation['schema_errors'] ?? [],
            'errors' => $validation['errors'] ?? [],
            'interpretation_complete' => !empty($validation['interpretation_complete']),
            'creative_confidence' => floatval($validation['creative_confidence'] ?? 0),
            'unresolved_count' => intval($validation['unresolved_count'] ?? 0),
            'fallback_limitations' => $validation['fallback_limitations'] ?? [],
        ];
    }

    private static function append_quality_status($paths, $structured_state, $errors = [], $extra = []) {
        $report = file_exists($paths['quality_report']) ? self::read_json_file($paths['quality_report']) : [];
        $report['structured_status'] = $structured_state;
        $report['structured_pending'] = in_array($structured_state, ['structured_pending', 'structured_running'], true);
        $report['structured_complete'] = $structured_state === 'structured_complete';
        $report['structured_failed'] = in_array($structured_state, ['structured_failed', 'scheduling_failed'], true);
        $report['status'] = $structured_state;
        $report['errors'] = array_values(array_unique(array_merge((array)($report['errors'] ?? []), $errors)));
        foreach ($extra as $key => $value) {
            $report[$key] = $value;
        }
        $report['updated_at'] = gmdate('c');
        self::write_json_file($paths['quality_report'], $report);
    }

    private static function async_timing($start, $started_at) {
        return [
            'started_at' => $started_at,
            'completed_at' => gmdate('c'),
            'duration_ms' => (int)round((microtime(true) - $start) * 1000),
            'cron_trigger_method' => 'wp_cron',
        ];
    }

    private static function structured_prompt($brief, $actor_registry, $schema) {
        return "Create a diagnostic poster director manifest. This is analysis only and must not affect preview rendering.\n"
            . "Return a strict JSON object with all required sections and nested fields.\n"
            . "Actor IDs must match the provided actor registry exactly. Do not invent actors.\n"
            . "Each actor identity_constraints values must all be true.\n"
            . "Required schema summary: " . self::json($schema) . "\n"
            . "Brief: " . self::json(self::safe_brief($brief)) . "\n"
            . "Actor registry: " . self::json(self::safe_actor_registry($actor_registry));
    }

    private static function diagnostic_paths($draft_id, $brief_hash) {
        $dir = self::diagnostic_dir();
        if (!$dir) return [];
        $base = trailingslashit($dir) . 'poster-director-' . intval($draft_id) . '-' . preg_replace('/[^a-f0-9]/', '', (string)$brief_hash);
        return [
            'snapshot' => $base . '-snapshot.json',
            'deterministic' => $base . '-deterministic.json',
            'structured' => $base . '-structured.json',
            'quality_report' => $base . '-quality-report.json',
        ];
    }

    private static function diagnostic_dir() {
        if (!defined('ABSPATH') || !function_exists('wp_mkdir_p')) {
            return false;
        }

        $root = self::private_storage_root();
        if (!$root) {
            error_log('CMSG POSTER DIRECTOR PRIVATE STORAGE FAIL: reason=root_unavailable');
            return false;
        }

        $root = rtrim($root, "/\\");
        $dir = basename($root) === 'poster-director'
            ? $root
            : $root . DIRECTORY_SEPARATOR . 'poster-director';

        if (self::path_is_inside_document_root($dir)) {
            error_log('CMSG POSTER DIRECTOR PRIVATE STORAGE REJECTED reason=inside_document_root dir=' . $dir);
            return false;
        }

        if (!is_dir(dirname($dir)) && !wp_mkdir_p(dirname($dir))) {
            error_log('CMSG POSTER DIRECTOR PRIVATE STORAGE FAIL: dir=' . dirname($dir));
            return false;
        }
        @chmod(dirname($dir), 0750);

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            error_log('CMSG POSTER DIRECTOR PRIVATE STORAGE FAIL: dir=' . $dir);
            return false;
        }

        @chmod($dir, 0750);
        if (self::path_is_inside_document_root($dir)) {
            error_log('CMSG POSTER DIRECTOR PRIVATE STORAGE REJECTED reason=inside_document_root dir=' . $dir);
            return false;
        }

        $resolved = realpath($dir);
        if (!$resolved) {
            error_log('CMSG POSTER DIRECTOR PRIVATE STORAGE FAIL: dir=' . $dir . ' reason=realpath_failed');
            return false;
        }

        error_log('CMSG POSTER DIRECTOR PRIVATE STORAGE READY: dir=' . $resolved);
        return $resolved;
    }

    private static function private_storage_root() {
        if (defined('CMSG_PRIVATE_STORAGE_DIR') && CMSG_PRIVATE_STORAGE_DIR) {
            return (string)CMSG_PRIVATE_STORAGE_DIR;
        }

        $filtered = apply_filters('cmsg_private_storage_dir', '');
        if (is_string($filtered) && trim($filtered) !== '') {
            return trim($filtered);
        }

        return dirname(rtrim((string)ABSPATH, "/\\")) . DIRECTORY_SEPARATOR . 'cmsg-private';
    }

    private static function path_is_inside_document_root($path) {
        $doc_root = self::normalize_path(realpath(ABSPATH) ?: ABSPATH);
        $target = realpath($path);
        if (!$target) {
            $target = $path;
        }

        $target = self::normalize_path($target);
        return $doc_root !== '' && ($target === $doc_root || strpos($target, trailingslashit($doc_root)) === 0);
    }

    private static function normalize_path($path) {
        return rtrim(str_replace('\\', '/', (string)$path), '/');
    }

    private static function write_json_file($path, $data) {
        $json = self::json($data);
        $tmp = $path . '.' . wp_generate_password(8, false, false) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($path, 0640);
        return true;
    }

    private static function read_json_file($path) {
        $data = json_decode((string)@file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private static function hashes($brief, $actor_registry) {
        return [
            'brief_hash' => self::hash_value(self::json(self::safe_brief($brief))),
            'actor_registry_hash' => self::hash_value(self::json(self::safe_actor_registry($actor_registry))),
        ];
    }

    private static function openai_key() {
        if (!class_exists('CMSG_Plugin')) return '';
        $settings = CMSG_Plugin::settings();
        return trim((string)($settings['openai_api_key'] ?? ''));
    }

    private static function safe_api_error_code($body) {
        $decoded = json_decode((string)$body, true);
        return sanitize_key($decoded['error']['code'] ?? $decoded['error']['type'] ?? 'unknown');
    }

    private static function actor_hierarchy($actors) {
        $out = [];
        foreach ($actors as $id => $actor) {
            $out[] = [
                'actor_id' => $id,
                'role' => $actor['role'] ?? 'supporting',
                'visual_priority' => $actor['visual_priority'] ?? 'secondary',
            ];
        }
        return $out;
    }

    private static function confidence($method, $actors) {
        if ($method === 'structured_ai') return 0.82;
        if (count($actors) > 0) return 0.58;
        return 0.35;
    }

    private static function brief_text($brief, $key) {
        return sanitize_text_field((string)($brief[$key] ?? ''));
    }

    private static function safe_brief($brief) {
        if (!is_array($brief)) {
            return [];
        }

        $allowed = [
            'title', 'movie_title', 'tagline', 'synopsis', 'poster_description',
            'genre', 'tone', 'mood', 'setting', 'period', 'audience',
            'poster_layout', 'style_preset', 'title_font_style', 'tagline_font_style',
            'title_placement', 'poster_generation_mode', 'preserve_identity',
            'poster_scene_direction', 'visual_reference_descriptions',
            'poster_asset_reference_descriptions', 'poster_asset_references',
            'poster_assets', 'style_reference',
            'title_directions', 'color_directions', 'prop_directions',
        ];

        $safe = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $brief)) {
                $safe[$key] = self::safe_allowed_value($brief[$key], $key);
            }
        }

        if (!empty($brief['cast_members']) && is_array($brief['cast_members'])) {
            $safe['cast_members'] = self::safe_cast_members($brief['cast_members']);
        }

        for ($i = 1; $i <= 10; $i++) {
            foreach (['cast_actor_', 'cast_actor_role_', 'cast_actor_instruction_', 'cast_actor_name_'] as $prefix) {
                $key = $prefix . $i;
                if (array_key_exists($key, $brief)) {
                    $safe[$key] = $prefix === 'cast_actor_'
                        ? self::safe_asset_reference($brief[$key], $key, 'legacy_cast')
                        : self::safe_allowed_value($brief[$key], $key);
                }
            }
        }

        return $safe;
    }

    private static function safe_cast_members($members) {
        $safe = [];
        foreach ((array)$members as $index => $member) {
            if (!is_array($member)) continue;
            $entry = [
                'actor_id' => 'actor_' . chr(65 + intval($index)),
                'registry_index' => intval($index),
            ];
            foreach (['name', 'role', 'instruction', 'character_notes'] as $key) {
                if (array_key_exists($key, $member)) {
                    $entry[$key] = self::safe_allowed_value($member[$key], $key);
                }
            }
            if (!empty($member['image'])) {
                $entry['source_key'] = 'cast_members_' . intval($index) . '_image';
                $asset = self::safe_asset_reference($member['image'], 'cast_members_' . intval($index) . '_image', 'principal_cast');
                $entry['source_basename'] = $asset['source_basename'] ?? '';
                $entry['source_type'] = 'principal_cast';
            }
            $safe[] = $entry;
        }
        return $safe;
    }

    private static function safe_allowed_value($value, $field = '') {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $child_field = $field !== '' ? $field . '.' . $key : (string)$key;
                $out[$key] = self::is_asset_field($child_field)
                    ? self::safe_asset_reference($item, $child_field)
                    : self::safe_allowed_value($item, $child_field);
            }
            return $out;
        }

        if (is_bool($value) || is_numeric($value)) {
            return $value;
        }

        return is_scalar($value) ? sanitize_text_field((string)$value) : '';
    }

    private static function safe_asset_reference($value, $source_key = '', $source_type = '') {
        if (is_array($value)) {
            $asset = [
                'source_key' => sanitize_key((string)$source_key),
                'source_type' => sanitize_key((string)($value['source_type'] ?? $value['type'] ?? $source_type)),
            ];
            foreach (['actor_id', 'description', 'name', 'label'] as $key) {
                if (array_key_exists($key, $value)) {
                    $asset[$key] = self::safe_allowed_value($value[$key], $key);
                }
            }
            foreach (['image', 'path', 'source_path', 'file', 'filename', 'url'] as $path_key) {
                if (!empty($value[$path_key])) {
                    $asset['source_basename'] = self::safe_asset_basename($value[$path_key]);
                    break;
                }
            }
            return array_filter($asset, function($item) {
                return $item !== '' && $item !== null && $item !== [];
            });
        }

        return [
            'source_key' => sanitize_key((string)$source_key),
            'source_basename' => self::safe_asset_basename($value),
            'source_type' => sanitize_key((string)$source_type),
        ];
    }

    private static function safe_asset_basename($value) {
        $value = trim((string)$value);
        if ($value === '') return '';
        $path = parse_url($value, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $value = $path;
        }
        $value = str_replace('\\', '/', $value);
        return sanitize_file_name(basename($value));
    }

    private static function is_asset_field($field) {
        $field = strtolower((string)$field);
        foreach (['image', 'path', 'source_path', 'file', 'filename', 'style_reference', 'poster_assets', 'poster_asset_references'] as $needle) {
            if (strpos($field, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function safe_actor_registry($actor_registry) {
        $safe = [];
        foreach ((array)$actor_registry as $key => $entry) {
            if (!is_array($entry)) continue;
            $safe[$key] = [
                'actor_id' => (string)($entry['actor_id'] ?? $key),
                'registry_index' => intval($entry['registry_index'] ?? -1),
                'source_key' => (string)($entry['source_key'] ?? $key),
                'source_basename' => !empty($entry['source_path']) ? self::safe_asset_basename($entry['source_path']) : (!empty($entry['image']) ? self::safe_asset_basename($entry['image']) : ''),
                'source_type' => (string)($entry['source_type'] ?? 'principal_cast'),
                'accepted_as_actor' => array_key_exists('accepted_as_actor', $entry) ? !empty($entry['accepted_as_actor']) : true,
            ];
        }
        return $safe;
    }

    private static function hash_value($value) {
        return hash('sha256', (string)$value);
    }

    private static function json($value) {
        return wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private static function is_list($array) {
        if (!is_array($array)) return false;
        return array_keys($array) === range(0, count($array) - 1);
    }
}
