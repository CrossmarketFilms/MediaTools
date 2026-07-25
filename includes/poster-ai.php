<?php
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/poster-story-analyzer.php';
require_once __DIR__ . '/poster-director.php';

final class CMSG_Poster_AI {
    private static $in_final_generation = false;
    private static $final_openai_calls = 0;
    private static $last_preview_quality_message = '';
    private static $last_preview_quality_failures = [];
    private static $enable_preview_quality_check = true;
    private static $duplicate_face_similarity_threshold = 0.62;
    private static $duplicate_face_detector_method = 'embedding_with_heuristic_fallback';
    private static $last_background_openai_error = [];
    private static $last_identity_actor_diagnostic_context = [];
    private static $poster_actor_trace_shutdown_registered = false;
    private const SINGLE_PASS_MAX_CAST_REFERENCES = 4;
    private const LAYERED_CAMPAIGN_BACKGROUND_RETRIES = 3;
    private const BACKGROUND_PERSON_CONFIDENCE_THRESHOLD = 0.72;
    private const PROFESSIONAL_RECOVERY_DEFAULT_TEMPLATE = 'prestige_ensemble_pyramid';
    private const PROFESSIONAL_RECOVERY_IDENTITY_MATCH_THRESHOLD = 0.35;
    private const PROFESSIONAL_RECOVERY_IDENTITY_MATCH_MARGIN = 0.08;

    private static function normalized_cast_members($brief) {
        $members = [];

        if (!empty($brief['cast_members']) && is_array($brief['cast_members'])) {
            foreach ($brief['cast_members'] as $index => $member) {
                if (!is_array($member)) continue;

                $role = sanitize_key($member['role'] ?? ((int)$index < 2 ? 'lead' : 'supporting'));
                $role = $role === 'lead' ? 'lead' : 'supporting';

                $row = [
                    'name' => sanitize_text_field($member['name'] ?? ''),
                    'role' => $role,
                    'instruction' => sanitize_text_field($member['instruction'] ?? ''),
                    'image' => is_string($member['image'] ?? '') ? (string)$member['image'] : '',
                    'source_type' => 'principal_cast',
                ];

                if ($row['name'] !== '' || $row['instruction'] !== '' || $row['image'] !== '') {
                    $members[] = $row;
                }
            }
        }

        if (!empty($members)) {
            return array_slice($members, 0, 10);
        }

        for ($i = 1; $i <= 3; $i++) {
            $image = is_string($brief['cast_actor_' . $i] ?? '') ? (string)$brief['cast_actor_' . $i] : '';
            $instruction = sanitize_text_field($brief['cast_actor_' . $i . '_instruction'] ?? '');

            if ($image === '' && $instruction === '') continue;

            $members[] = [
                'name' => '',
                'role' => $i <= 2 ? 'lead' : 'supporting',
                'instruction' => $instruction,
                'image' => $image,
                'source_type' => 'legacy_cast',
            ];
        }

        return $members;
    }

    private static function actor_registry_path_key($path) {
        if (!is_string($path) || $path === '') return '';
        $real = file_exists($path) ? realpath($path) : false;
        return $real ? $real : $path;
    }

    private static function actor_registry_source_type($type) {
        $type = sanitize_key($type);
        $allowed = ['principal_cast', 'legacy_cast', 'visual_reference', 'style_reference', 'prop_reference'];
        return in_array($type, $allowed, true) ? $type : 'prop_reference';
    }

    private static function actor_registry_asset_type_to_source_type($type) {
        $type = sanitize_key($type);
        if ($type === 'style') return 'style_reference';
        if (in_array($type, ['prop', 'logo', 'vehicle', 'building', 'product', 'symbol'], true)) {
            return 'prop_reference';
        }
        return 'visual_reference';
    }

    private static function actor_source_registry($brief) {
        $registry = [];
        $accepted_seen = [];
        $candidate_seen = [];
        $actor_index = 0;

        $add_candidate = function($path, $source_type, $source_key, $accepted, $rejected_reason = '') use (&$registry, &$accepted_seen, &$candidate_seen, &$actor_index) {
            $source_type = self::actor_registry_source_type($source_type);
            $path = is_string($path) ? $path : '';
            $path_key = self::actor_registry_path_key($path);
            $candidate_key = $source_type . '|' . $source_key . '|' . $path_key;

            if ($path === '' || $path_key === '') {
                return;
            }

            if (isset($candidate_seen[$candidate_key])) {
                return;
            }

            $candidate_seen[$candidate_key] = true;
            $file_exists = file_exists($path);
            $can_be_actor = in_array($source_type, ['principal_cast', 'legacy_cast'], true);
            $accepted_as_actor = (bool)$accepted && $can_be_actor && $file_exists && !isset($accepted_seen[$path_key]);
            $reason = $rejected_reason;

            if (!$can_be_actor && $reason === '') {
                $reason = 'principal_cast_only';
            } elseif (!$file_exists && $reason === '') {
                $reason = 'missing_file';
            } elseif ($accepted && isset($accepted_seen[$path_key]) && $reason === '') {
                $reason = 'duplicate_actor_source';
            }

            $row_actor_index = $accepted_as_actor ? $actor_index : null;
            $registry[] = [
                'actor_id' => $accepted_as_actor ? self::campaign_actor_id($actor_index) : '',
                'actor_index' => $row_actor_index,
                'source_path' => $path,
                'source_key' => sanitize_key($source_key),
                'source_type' => $source_type,
                'accepted_as_actor' => $accepted_as_actor,
                'rejected_reason' => $accepted_as_actor ? '' : $reason,
                'principal_cast_only' => true,
            ];

            if ($accepted_as_actor) {
                $accepted_seen[$path_key] = true;
                $actor_index++;
            }
        };

        foreach (self::normalized_cast_members($brief) as $index => $member) {
            $source_type = self::actor_registry_source_type($member['source_type'] ?? 'principal_cast');
            if (!in_array($source_type, ['principal_cast', 'legacy_cast'], true)) {
                $source_type = 'principal_cast';
            }
            $add_candidate($member['image'] ?? '', $source_type, 'cast_members_' . (int)$index . '_image', true);
        }

        foreach (['cast_actor_1', 'cast_actor_2', 'cast_actor_3'] as $key) {
            $add_candidate($brief[$key] ?? '', 'legacy_cast', $key, true);
        }

        if (!empty($brief['style_reference'])) {
            $add_candidate($brief['style_reference'], 'style_reference', 'style_reference', false, 'style_reference_not_actor');
        }

        foreach (self::normalized_poster_asset_references($brief) as $index => $reference) {
            $source_type = self::actor_registry_asset_type_to_source_type($reference['type'] ?? 'prop');
            $add_candidate($reference['image'] ?? '', $source_type, 'poster_asset_references_' . (int)$index, false, 'poster_asset_reference_not_actor');
        }

        if (!empty($brief['poster_assets']) && is_array($brief['poster_assets'])) {
            foreach ($brief['poster_assets'] as $index => $asset) {
                $path = '';
                $source_type = 'prop_reference';
                $explicit_actor = false;

                if (is_array($asset)) {
                    $path = is_string($asset['image'] ?? '') ? (string)$asset['image'] : (is_string($asset['path'] ?? '') ? (string)$asset['path'] : '');
                    $declared = sanitize_key($asset['source_type'] ?? ($asset['type'] ?? ($asset['role'] ?? '')));
                    $explicit_actor = in_array($declared, ['principal_cast', 'cast', 'actor', 'actor_reference'], true);
                    $source_type = $explicit_actor ? 'principal_cast' : self::actor_registry_asset_type_to_source_type($declared);
                } elseif (is_string($asset)) {
                    $path = $asset;
                }

                $add_candidate(
                    $path,
                    $source_type,
                    'poster_assets_' . (int)$index,
                    $explicit_actor,
                    $explicit_actor ? '' : 'poster_asset_not_actor'
                );
            }
        }

        return $registry;
    }

    private static function accepted_actor_source_records($brief_or_registry) {
        if (!is_array($brief_or_registry)) {
            return [];
        }

        $registry = is_array($brief_or_registry) && array_key_exists(0, $brief_or_registry) && isset($brief_or_registry[0]['source_type'])
            ? $brief_or_registry
            : self::actor_source_registry($brief_or_registry);
        $records = [];

        foreach ($registry as $row) {
            if (empty($row['accepted_as_actor'])) continue;
            if (!in_array($row['source_type'] ?? '', ['principal_cast', 'legacy_cast'], true)) continue;
            $records[] = $row;
        }

        return $records;
    }

    private static function actor_registry_audit_path($manifest_path) {
        if (!is_string($manifest_path) || $manifest_path === '') return '';
        return preg_replace('/-campaign-manifest\.json$/', '-actor-registry-audit.json', $manifest_path);
    }

    private static function write_actor_registry_audit($manifest_path, $registry) {
        $audit_path = self::actor_registry_audit_path($manifest_path);
        if (!$audit_path) return false;

        $payload = [
            'created_at' => gmdate('c'),
            'principal_cast_only' => true,
            'accepted_actor_count' => count(self::accepted_actor_source_records($registry)),
            'registry' => array_values((array)$registry),
        ];

        file_put_contents($audit_path, wp_json_encode($payload, JSON_PRETTY_PRINT));
        @chmod($audit_path, 0664);
        error_log('CMSG actor_registry_audit written path=' . $audit_path . ' accepted=' . intval($payload['accepted_actor_count']));
        return true;
    }

    private static function normalized_poster_asset_references($brief) {
        $references = [];
        $allowed_types = ['prop', 'logo', 'vehicle', 'building', 'product', 'style', 'symbol'];

        if (!empty($brief['poster_asset_references']) && is_array($brief['poster_asset_references'])) {
            foreach ($brief['poster_asset_references'] as $reference) {
                if (!is_array($reference)) continue;

                $type = sanitize_key($reference['type'] ?? 'prop');
                if (!in_array($type, $allowed_types, true)) {
                    $type = 'prop';
                }

                $row = [
                    'type' => $type,
                    'description' => sanitize_textarea_field($reference['description'] ?? ''),
                    'image' => is_string($reference['image'] ?? '') ? (string)$reference['image'] : '',
                ];

                if ($row['description'] !== '' || $row['image'] !== '') {
                    $references[] = $row;
                }
            }
        }

        if (empty($references) && !empty($brief['poster_assets']) && is_array($brief['poster_assets'])) {
            foreach ($brief['poster_assets'] as $asset) {
                if (!is_string($asset) || $asset === '') continue;
                $references[] = [
                    'type' => 'prop',
                    'description' => '',
                    'image' => $asset,
                ];
            }
        }

        return array_slice($references, 0, 10);
    }

    private static function poster_asset_reference_prompt_lines($brief) {
        $lines = [];
        $type_labels = [
            'prop' => 'Prop / Object',
            'logo' => 'Logo / Brand Mark',
            'vehicle' => 'Vehicle',
            'building' => 'Building / Location',
            'product' => 'Product',
            'style' => 'Visual Style Reference',
            'symbol' => 'Symbol / Motif',
        ];

        foreach (self::normalized_poster_asset_references($brief) as $index => $reference) {
            $label = 'Reference ' . ((int)$index + 1);
            $type = $type_labels[$reference['type']] ?? 'Prop / Object';
            $description = $reference['description'] !== ''
                ? $reference['description']
                : 'Use this image as a non-human visual reference only.';

            $lines[] = "{$label}\n"
                . "Type: {$type}\n"
                . "Usage: {$description}";
        }

        return implode("\n\n", $lines);
    }

    private static function cast_prompt_lines($brief) {
        $lines = [];

        foreach (self::normalized_cast_members($brief) as $index => $member) {
            $actor_label = self::actor_registry_label($index);
            $role_label = $member['role'] === 'lead' ? 'Lead Character' : 'Supporting Character';
            $name = $member['name'] !== '' ? $member['name'] : 'Uploaded cast reference ' . $actor_label;
            $instruction = $member['instruction'] !== '' ? $member['instruction'] : 'Use uploaded reference image for character identity.';
            $placement = self::placement_for_cast_member($member, $index, self::cast_counts($brief)['total']);
            $hierarchy = $member['role'] === 'lead'
                ? 'Primary visual hierarchy; visually prominent.'
                : 'Secondary visual hierarchy; clearly visible but smaller than leads.';

            $lines[] = "{$actor_label}\n"
                . "Role: {$role_label}\n"
                . "Priority: {$hierarchy}\n"
                . "Placement: {$placement}\n"
                . "Description: {$name}. {$instruction}";
        }

        return implode("\n\n", $lines);
    }

    private static function actor_registry_label($index) {
        $letters = range('A', 'J');
        return 'Actor ' . ($letters[(int)$index] ?? ((int)$index + 1));
    }

    private static function placement_for_cast_member($member, $index, $total) {
        $instruction = strtolower((string)($member['instruction'] ?? ''));

        $placements = [
            'lower left' => 'Lower Left',
            'bottom left' => 'Lower Left',
            'lower right' => 'Lower Right',
            'bottom right' => 'Lower Right',
            'upper left' => 'Upper Left',
            'top left' => 'Upper Left',
            'upper right' => 'Upper Right',
            'top right' => 'Upper Right',
            'center top' => 'Center Top',
            'top center' => 'Center Top',
            'upper center' => 'Center Top',
            'hover' => 'Center Top',
            'above' => 'Center Top',
            'center' => 'Center',
            'middle' => 'Center',
            'left side' => 'Left',
            'on the left' => 'Left',
            'positioned left' => 'Left',
            'right side' => 'Right',
            'on the right' => 'Right',
            'positioned right' => 'Right',
            'lower center' => 'Lower Center',
            'bottom center' => 'Lower Center',
        ];

        foreach ($placements as $needle => $placement) {
            if (strpos($instruction, $needle) !== false) {
                return $placement;
            }
        }

        if ((int)$total <= 1) {
            return 'Center';
        }

        $defaults = [
            'Center Top',
            'Left',
            'Right',
            'Lower Left',
            'Lower Right',
            'Upper Left',
            'Upper Right',
            'Lower Center',
            'Far Left',
            'Far Right',
        ];

        return $defaults[(int)$index] ?? 'Secondary Ensemble Position';
    }

    private static function identity_registry_prompt($brief) {
        $cast_members = self::normalized_cast_members($brief);
        $total = count($cast_members);

        if ($total < 1) {
            return "UNIQUE CAST REGISTRY\n"
                . "There are no uploaded cast members. Do not invent recognizable lead actors unless the scene direction explicitly requests anonymous people.\n";
        }

        return "UNIQUE CAST REGISTRY\n"
            . "There are exactly {$total} uploaded cast members.\n"
            . "Each uploaded actor reference represents ONE unique individual.\n"
            . "Each uploaded actor must appear exactly once in the final composition.\n"
            . "Never duplicate an uploaded actor.\n"
            . "Never create alternate versions of the same actor.\n"
            . "Never place the same actor in the foreground and background.\n"
            . "Never reuse an uploaded actor as crowd, silhouette, reflection, ghost image, montage image, or secondary portrait.\n"
            . "If additional people are required for atmosphere, generate anonymous extras that DO NOT resemble any uploaded actor.\n"
            . "The final poster must contain exactly {$total} unique recognizable uploaded actors.\n";
    }

    private static function composition_registry_rules($brief) {
        $counts = self::cast_counts($brief);

        return "EXPLICIT COMPOSITION RULES\n"
            . "- Lead actors should occupy the primary visual hierarchy.\n"
            . "- Supporting actors should occupy secondary positions.\n"
            . "- Each uploaded actor must occupy a unique position.\n"
            . "- No uploaded actor may appear more than once.\n"
            . "- Do not create mirrored versions of uploaded actors.\n"
            . "- Do not create alternate expressions of the same uploaded actor.\n"
            . "- Do not repeat uploaded faces in lower montage sections.\n"
            . "- Do not repeat uploaded faces in the background.\n"
            . "- Do not reuse uploaded actors as silhouettes, reflections, ghosts, memories, inset portraits, or crowd members.\n"
            . "- If there are {$counts['total']} uploaded actors, the poster must show {$counts['total']} uploaded actor identities total, not more.\n";
    }

    private static function placement_mapping_prompt($brief) {
        $members = self::normalized_cast_members($brief);
        if (empty($members)) {
            return '';
        }

        $lines = ["PLACEMENT MAPPING"];
        $total = count($members);
        foreach ($members as $index => $member) {
            $lines[] = self::actor_registry_label($index) . ': ' . self::placement_for_cast_member($member, $index, $total);
        }

        return implode("\n", $lines) . "\n";
    }

    private static function poster_layout_key($brief) {
        $layout = sanitize_key($brief['poster_layout'] ?? '');
        $allowed = [
            'solo_hero',
            'dual_lead',
            'three_character_triangle',
            'ensemble_portrait_grid',
            'floating_heads_ensemble',
            'no_cast_background_only',
        ];

        if (in_array($layout, $allowed, true)) {
            return $layout;
        }

        $count = self::cast_counts($brief)['total'];
        if ($count > 5) return 'ensemble_portrait_grid';
        if ($count === 0) return 'no_cast_background_only';
        if ($count === 1) return 'solo_hero';
        if ($count === 2) return 'dual_lead';
        if ($count === 3) return 'three_character_triangle';
        return 'floating_heads_ensemble';
    }

    private static function poster_layout_label($layout) {
        $labels = [
            'solo_hero' => 'Solo Hero',
            'dual_lead' => 'Dual Lead',
            'three_character_triangle' => 'Three Character Triangle',
            'ensemble_portrait_grid' => 'Ensemble Portrait Grid',
            'floating_heads_ensemble' => 'Floating Heads Ensemble',
            'no_cast_background_only' => 'No Cast / Background Only',
        ];

        return $labels[$layout] ?? 'Three Character Triangle';
    }

    private static function poster_layout_prompt($brief) {
        $layout = self::poster_layout_key($brief);
        $counts = self::cast_counts($brief);
        $total = $counts['total'];

        $prompt = "POSTER LAYOUT STRATEGY: " . self::poster_layout_label($layout) . "\n";

        switch ($layout) {
            case 'solo_hero':
                $prompt .= "- Use one primary hero portrait composition.\n"
                    . "- If more than one actor is uploaded, keep additional uploaded actors smaller and secondary without repeating anyone.\n"
                    . "- Do not create duplicate portraits, lower montage rows, reflections, silhouettes, or background copies.\n";
                break;

            case 'dual_lead':
                $prompt .= "- Use a two-lead composition with the first two uploaded actors as the primary visual relationship.\n"
                    . "- Place the two lead actors in distinct left/right or foreground/background positions.\n"
                    . "- Supporting actors, if present, must be smaller and appear once only.\n"
                    . "- Do not repeat either lead in lower portraits, background portraits, reflections, or montage strips.\n";
                break;

            case 'three_character_triangle':
                $prompt .= "- Use a three-character triangle layout with three distinct actor positions.\n"
                    . "- Arrange the first three uploaded actors as a clean triangular key-art composition.\n"
                    . "- Supporting actors beyond three, if present, must occupy one clear secondary position each.\n"
                    . "- Do not repeat actors as extra heads, lower montage copies, or background versions.\n";
                break;

            case 'ensemble_portrait_grid':
                $prompt .= "- Use a structured portrait grid or tiered key-art layout, not a montage/collage.\n"
                    . "- Use one clean portrait position per uploaded actor.\n"
                    . "- Each uploaded actor appears once only.\n"
                    . "- No actor may appear twice in foreground, background, reflection, silhouette, vehicle window, or lower montage.\n"
                    . "- Do not use repeated lower portraits or background versions of actors.\n"
                    . "- For 6-10 actors, use smaller but distinct individual positions with clear separation between faces.\n"
                    . "- Keep the design cinematic and professional while preserving the grid/tier structure.\n";
                break;

            case 'floating_heads_ensemble':
                $prompt .= "- Use cinematic floating-head style with depth and hierarchy.\n"
                    . "- Each uploaded actor must appear once only as one floating-head or portrait element.\n"
                    . "- No repeated portraits, no duplicate lower montage, no background copy of an uploaded actor.\n"
                    . "- Supporting actors may be smaller, but each still receives only one distinct portrait position.\n";
                break;

            case 'no_cast_background_only':
                $prompt .= "- Create background/environment key art only.\n"
                    . "- Do not generate cast portraits, faces, bodies, silhouettes, crowds, or human figures.\n"
                    . "- Use mood, setting, props, symbols, atmosphere, and cinematic lighting instead of actors.\n";
                break;
        }

        if ($total > 5 && $layout !== 'ensemble_portrait_grid') {
            $prompt .= "- Large cast note: Ensemble Portrait Grid is recommended for {$total} actors; because another layout was selected, be extra strict that each uploaded actor appears once only.\n";
        }

        return $prompt;
    }

    private static function should_use_cast_references($brief) {
        return self::poster_layout_key($brief) !== 'no_cast_background_only';
    }

    private static function cast_counts($brief) {
        $counts = ['total' => 0, 'lead' => 0, 'supporting' => 0];

        foreach (self::normalized_cast_members($brief) as $member) {
            $counts['total']++;
            if (($member['role'] ?? 'supporting') === 'lead') {
                $counts['lead']++;
            } else {
                $counts['supporting']++;
            }
        }

        return $counts;
    }

    public static function build_prompt($brief, $concept_variant = '') {
        $title = sanitize_text_field($brief['title'] ?? 'Untitled Film');
        $genre = sanitize_text_field($brief['genre'] ?? 'cinematic drama');
        $mood = sanitize_text_field($brief['mood'] ?? 'premium');
        $tagline = sanitize_text_field($brief['tagline'] ?? '');
        $description = trim((string)($brief['poster_description'] ?? ''));
        if ($description === '' && !empty($brief['cast_scene_instruction'])) {
            $description = trim((string)$brief['cast_scene_instruction']);
        }

        $cast_lines = self::cast_prompt_lines($brief);
        $asset_reference_lines = self::poster_asset_reference_prompt_lines($brief);
        $cast_counts = self::cast_counts($brief);
        $identity_registry = self::identity_registry_prompt($brief);
        $composition_rules = self::composition_registry_rules($brief);
        $placement_mapping = self::placement_mapping_prompt($brief);
        $layout = self::poster_layout_key($brief);
        $layout_prompt = self::poster_layout_prompt($brief);
        $quality_retry_prompt = '';
        if (!empty($brief['quality_retry_attempt'])) {
            $quality_retry_prompt = "QUALITY RETRY OVERRIDE\n"
                . "A previous generated preview was rejected because it likely repeated uploaded cast identities.\n"
                . "This retry must use the strictest anti-duplication behavior.\n"
                . "Use Ensemble Portrait Grid with one clean, separate position per uploaded actor.\n"
                . "Do not use floating-head repetition, bottom montage rows, repeated actor bodies, reflections, silhouettes, or background copies.\n"
                . "Count the uploaded actors before composing, then render exactly that many uploaded actor identities total.\n";
        }
        $ensemble_text = $cast_counts['total'] >= 6
            ? "Create an ensemble theatrical poster. Lead characters should be most prominent. Supporting characters should appear clearly but with secondary visual hierarchy. Avoid trying to make every actor equally large. Use a professional ensemble composition.\n"
            : '';

        $style = sanitize_text_field($brief['style_preset'] ?? 'cinematic_premium');
        $has_style_reference = !empty($brief['style_reference']);
        $asset_count = count(self::normalized_poster_asset_references($brief));
$background_only = !empty($brief['background_only']);
if ($layout === 'no_cast_background_only') {
    $background_only = true;
}

if ($background_only) {
    return "Create a high-end cinematic movie poster BACKGROUND PLATE ONLY.

POSTER PROJECT NAME: {$title}
GENRE: {$genre}
MOOD: {$mood}
STYLE PRESET: {$style}

{$identity_registry}

{$layout_prompt}

{$quality_retry_prompt}

INDIVIDUAL ACTOR REGISTRY:
" . ($cast_lines !== '' ? $cast_lines . "\n" : '') . "

{$placement_mapping}
POSTER SCENE DIRECTION:
{$description}

VISUAL REFERENCE REGISTRY:
" . ($asset_reference_lines !== '' ? $asset_reference_lines . "\n" : "No non-human visual references provided.\n") . "

STRICT BACKGROUND-ONLY RULES:
- Do NOT generate people.
- Do NOT generate faces.
- Do NOT generate heads.
- Do NOT generate bodies.
- Do NOT generate silhouettes.
- Do NOT generate portraits.
- Do NOT generate actors.
- Do NOT generate crowds.
- Do NOT generate human figures of any kind.
- Create only environment, mood, lighting, atmosphere, props, architecture, smoke, fire, shadows, symbols, and cinematic background texture.
- Leave upper and middle areas open for real actor cutouts.
- Leave lower area clean for title placement.
- No readable text.
- No fake credits.
- No movie title inside the artwork.
- Professional theatrical poster background only.";
}

        $variant_text = 'Final output: create a vertical poster composition designed specifically for 900x1285. Keep every actor fully inside frame. Do not crop the top of the male character’s head. Leave visible headroom above all characters. Keep all faces, hairlines, shoulders, and important body elements fully visible. Leave clean lower title-safe area.';
        if ($concept_variant === 'hero') {
            $variant_text = 'Concept direction: dramatic hero composition, bold central subject, high contrast lighting. Compose natively as a 900x1285 vertical poster with all actor faces fully inside frame and clean lower title-safe space.';
        } elseif ($concept_variant === 'emotional') {
            $variant_text = 'Concept direction: emotional character-focused poster, cinematic faces, intimate dramatic tension. Compose natively as a 900x1285 vertical poster with all actor faces fully inside frame and clean lower title-safe space.';
        } elseif ($concept_variant === 'streaming') {
            $variant_text = 'Concept direction: bold streaming key art, commercial layout, striking visual hook, premium platform look. Compose natively as a 900x1285 vertical poster with all actor faces fully inside frame and clean lower title-safe space.';
        } elseif ($concept_variant === 'vertical') {
            $variant_text = 'Final output: create a native vertical poster composition for 900x1285. Use the selected preview as the exact design reference. Preserve the same cast, mood, layout, color palette, and concept. Recompose naturally for portrait format with all heads, faces, shoulders, bodies, house, props, and important elements fully visible. Leave clean lower title-safe space.';
        } elseif ($concept_variant === 'square') {
            $variant_text = 'Final output: square social poster composition with centered commercial key art.';
        } elseif ($concept_variant === 'banner') {
            $variant_text = 'Final output: create a native landscape banner composition for 895x504. Use the selected preview as the exact design reference. Preserve the same cast, mood, layout, color palette, and concept. Recompose naturally for wide horizontal format with all actor faces fully visible. Do not crop heads or faces. Leave clean lower-center title-safe space.';
        }

        return "Create a high-end cinematic movie poster key art concept.\n\n"
            . "POSTER PROJECT NAME: {$title}\nGENRE: {$genre}\nMOOD: {$mood}\nSTYLE PRESET: {$style}\n"
            . "TYPOGRAPHY RULE: Do NOT render the movie title, tagline, credits, or any readable text inside the artwork. The plugin  will overlay final typography after generation. Leave clean empty title-safe space.\n"
            . "\n{$identity_registry}\n"
            . "\n{$layout_prompt}\n"
            . ($quality_retry_prompt !== '' ? "\n{$quality_retry_prompt}\n" : '')
            . "INDIVIDUAL ACTOR REGISTRY:\n"
            . ($cast_lines !== '' ? $cast_lines . "\n" : '')
            . "\n"
            . "{$placement_mapping}\n"
            . "{$composition_rules}\n"
            . "POSTER SCENE DIRECTION:\n{$description}\n\n"
            . "VISUAL REFERENCE REGISTRY:\n"
            . ($asset_reference_lines !== '' ? $asset_reference_lines . "\n\n" : "No non-human visual references provided.\n\n")
            . "CAST HIERARCHY:\n"
            . "- Lead Character references: {$cast_counts['lead']}. Render lead characters as the most visually prominent cast members.\n"
            . "- Supporting Character references: {$cast_counts['supporting']}. Render supporting characters clearly but smaller or secondary.\n"
            . "- Each cast reference must appear exactly once as one character only. Do not repeat any cast member as a second face, background extra, bottom montage, reflection, or crowd duplicate.\n"
            . ($ensemble_text !== '' ? "- {$ensemble_text}" : '')
            . "REFERENCE INPUTS:\n"
            . ($has_style_reference ? "- A style reference image is provided. Use it ONLY for mood, lighting, composition, palette, typography placement, and cinematic design language. Do NOT copy faces, people, actors, logos, or text from the style reference. Actor identity must come only from Principal Cast images.\n" : '')
            . ($asset_count > 0
              ? "- {$asset_count} props/logos/visual reference image(s) are provided with descriptions in the Visual Reference Registry. Treat them as non-human visual references for objects, symbols, products, vehicles, buildings, logos, props, palette, and atmosphere. Do not treat these as actor photos or cast identity sources.\n"
              : '')
            . (
                 !empty($brief['preserve_identity'])
                 ? "\nSTRICT IDENTITY INSTRUCTION:\nWhen reference character images are provided, preserve the exact facial identity, skin tone, facial structure, age, hairstyle, expression, and recognizable likeness from uploaded images. Do not redesign, beautify, mutate, cartoonize, reinterpret, or replace uploaded faces or subjects.\n"
                 : "\nCREATIVE FLEXIBILITY INSTRUCTION:\nYou may creatively reinterpret uploaded subjects while maintaining general thematic inspiration and cinematic quality.\n"
              )
            . "\nAFRICAN / BLACK ACTOR IDENTITY RULES:\n"
            . "- Preserve exact Black/African facial identity from actor reference images.\n"
            . "- Match the actor's eye shape, eye spacing, eyelids, eyebrow shape, nose bridge, nose width, nostril shape, lips, jawline, chin, cheekbones, forehead, hairline, hair texture, beard pattern, grey hair pattern, and skin undertone.\n"
            . "- Do not lighten skin tone, narrow the nose, reduce lip fullness, soften facial structure, westernize facial features, beautify the actor, or replace the actor with a generic celebrity-like face.\n"
            . "- Do not make older Black male actors look younger or smoother. Preserve age, wrinkles, beard texture, facial heaviness, and expression.\n"
            . "- Do not make Black female actors look like a different glamor model. Preserve real face shape, cheek structure, eye shape, nose, lips, hairstyle, skin tone, and body proportions from the uploaded reference.\n"
            . "- If style conflicts with likeness, prioritize likeness over style.\n"
            . "- Do not age, de-age, distort, merge, or replace actor identities.\n"
            . "- Preserve actor identity as closely as possible from uploaded references.\n"
            . "{$variant_text}\n\n"
            . "REQUIREMENTS:\n"
            . "- Hollywood-level theatrical poster quality\n"
            . "- Realistic cinematic lighting and depth\n"
            . "- Strong focal composition\n"
            . "- Premium streaming-platform key art quality\n"
            . "- Dramatic contrast and polished color grading\n"
            . "- Professional poster layout with EXTREME SAFE TITLE MARGINS\n"
            . "- Leave large clean empty safe zones for title and tagline placement\n"
            . "- Never place faces, logos, weapons, credits, or important objects near image edges\n"
            . "- Keep all major composition elements inside center-safe boundaries\n"
            . "- For 895x504 landscape/banner posters, preserve strong left/right and top/bottom safe padding\n"
            . "- For 900x1285 vertical posters, preserve strong left/right and top/bottom safe padding\n"
            . "- Generate finished cinematic key art without final typography\n"
            . "- Absolutely no readable movie title text inside the generated image\n"
            . "- Absolutely no tagline text inside the generated image\n"
            . "- No fake poster credits or random typography\n"
            . "- Avoid generic stock-photo appearance\n"
            . "- No distorted faces, no extra fingers, no unreadable fake text\n"
            . "\nSUBJECT / FACE PRESERVATION RULES:\n"
            . "- Each uploaded actor image may appear ONLY ONCE in the composition.\n"
            . "- Each cast reference must appear exactly once as one character only.\n"
            . "- Do not duplicate the same actor in multiple locations.\n"
            . "- Do not create secondary copies, background crowd copies, montage repeats, reflection duplicates, or miniature duplicate versions of any uploaded cast member.\n"
            . "- Do not repeat faces, bodies, heads, expressions, or character poses.\n"
            . "- Every character placement must represent a unique uploaded actor.\n"
            . "- Never clone or reuse a character to fill composition space.\n"
            . "- If the composition needs background crowd or distant figures, use anonymous silhouettes only and never repeat uploaded cast identities.\n"
            . "- If there are {$cast_counts['total']} uploaded actors, show {$cast_counts['total']} unique actors.\n"
            . "- If more than 4 actors are uploaded, do not make every actor equally large; use lead/supporting hierarchy.\n"
            . "- Uploaded character, actor, face, object, or logo images are PRIMARY IDENTITY SOURCES, not loose inspiration\n"
            . "- Preserve the exact facial identity, skin tone, age, hairstyle, facial structure, expression, and recognizable likeness from uploaded reference images\n"
            . "- Do not redesign, replace, beautify, age-change, cartoonize, mutate, or reinterpret referenced faces\n"
            . "- Keep eyes, nose, mouth, jawline, hairline, face shape, and complexion as close as possible to the uploaded image\n"
            . "- Preserve costume, clothing color, body proportions, and unique visual details from the uploaded subject\n"
            . "- If a dog, animal, prop, weapon, vehicle, logo, or object is uploaded, preserve its shape, markings, color, and recognizable features\n"
            . "- Build cinematic lighting, background, atmosphere, and composition around the uploaded subject without changing the subject identity\n"
            . "- Treat uploaded images as identity anchors that must remain recognizable in the final poster\n"
            . "\nMALE CHARACTER PRESERVATION RULES:\n"
            . "- If a male character is present in an uploaded reference image, preserve him as male-presenting.\n"
            . "- Do not feminize male faces.\n"
            . "- Do not narrow the jawline, smooth masculine facial features, remove facial hair, alter beard/stubble, change hairline, or replace the person with a generic actor.\n"
            . "- Preserve masculine facial structure, skin tone, age range, hairstyle, facial hair, expression, and body proportions.\n"
            . "- Male characters must remain recognizable as the same uploaded person.\n"
            . "- When composing multiple characters, maintain the relative prominence and identity of all uploaded cast members.\n"
            . "- When multiple character references are uploaded, each reference represents a different cast member and must remain visually distinct.\n"
            . "- Never merge two uploaded faces into a single person.\n"
            . "- Never replace one uploaded character with another.\n"
            . "\nENSEMBLE COMPOSITION RULES:\n"
            . "- Build a balanced ensemble poster.\n"
            . "- Distribute actors evenly across the composition.\n"
            . "- Use unique placement for every actor.\n"
            . "- Do not mirror or replicate any uploaded character.\n"
            . "- Do not create duplicate heads or duplicate faces.\n"
            . "- Do not invent extra cast members unless the user explicitly requested background extras.\n"
            . "- For ensemble posters, reduce the size of supporting characters instead of duplicating lead characters.\n"
            . "- Do not create a second lower-row montage that repeats the same uploaded cast identities.\n"
            . "\nBACKGROUND GENERATION RULES:\n"
            . "- Generate environment only.\n"
            . "- No people.\n"
            . "- No faces.\n"
            . "- No silhouettes.\n"
            . "- No heads.\n"
            . "- No human figures.\n"
            . "- No characters.\n"
            . "- No cast members.\n"
            . "- Leave space for actors to be composited later.\n";

 }

    public static function preview_mode() {
        return trim((string) CMSG_Plugin::settings()['openai_api_key']) === '';
    }

public static function last_preview_quality_message() {
    return self::$last_preview_quality_message;
}

public static function last_preview_quality_failures() {
    return self::$last_preview_quality_failures;
}

public static function generate_previews($brief, $draft_id) {
    $cast_count = self::cast_counts($brief)['total'];
    if (self::poster_generation_mode($brief) === 'single_pass' && $cast_count > self::SINGLE_PASS_MAX_CAST_REFERENCES) {
        $message = 'Single Pass AI Poster is currently limited to ' . self::SINGLE_PASS_MAX_CAST_REFERENCES . ' uploaded cast members because large ensemble AI generation can repeat actor faces and clothing. Remove supporting actors or reduce the cast before generating previews.';
        error_log('CMSG POSTER PREVIEW BLOCKED: single_pass_large_cast draft_id=' . intval($draft_id) . ' cast_count=' . intval($cast_count));
        return new WP_Error('single_pass_large_cast_blocked', $message);
    }

    self::queue_poster_director_diagnostic_manifest($brief, $draft_id);

    $variants = ['hero', 'emotional', 'streaming'];
    $files = [];
    self::$last_preview_quality_message = '';
    self::$last_preview_quality_failures = [];
    $quality_message = 'Large ensemble posters may require simplified layout. Try Ensemble Portrait Grid with fewer cast members or remove repeated supporting actors.';

    foreach ($variants as $index => $variant) {
        $clean_path = '';
        $quality_passed = false;
        $last_quality_result = [];

        for ($attempt = 0; $attempt <= 2; $attempt++) {
            $attempt_brief = $brief;
            if ($attempt > 0) {
                $attempt_brief['quality_retry_attempt'] = $attempt;
                $attempt_brief['quality_retry_reason'] = 'A previous preview candidate was rejected for likely duplicate uploaded cast identities.';
                $attempt_brief['poster_layout'] = 'ensemble_portrait_grid';
            }

        // 1) Generate CLEAN source without watermark
try {
    $clean_path = self::generate_preview_candidate_file($attempt_brief, $draft_id, $variant, $index + 1);
} catch (Exception $e) {
    return new WP_Error('cmsg_openai_image_error', $e->getMessage());
}

            if (is_wp_error($clean_path)) {
                return $clean_path;
            }

            if (!$clean_path || !file_exists($clean_path)) {
                continue;
            }

            self::resize_png_cover_no_overlay($clean_path, $clean_path, 900, 1285);
            $quality = self::preview_duplicate_face_quality_check($clean_path, $attempt_brief, $variant, $attempt);
            $last_quality_result = $quality;

            if (!empty($quality['failed_quality_check'])) {
                self::$last_preview_quality_failures[] = $quality;
                self::reject_preview_candidate($clean_path);
                error_log('CMSG POSTER PREVIEW QUALITY REJECTED: draft_id=' . intval($draft_id) . ' variant=' . sanitize_key($variant) . ' attempt=' . intval($attempt) . ' reason=' . wp_json_encode($quality));
                continue;
            }

            $quality_passed = true;
            break;
        }

        if ($quality_passed && $clean_path && file_exists($clean_path)) {
            if (!self::generate_preview_format_family($clean_path, $brief)) {
                self::reject_preview_candidate($clean_path);
                error_log('CMSG POSTER PREVIEW REJECTED: preview_family_generation_failed draft_id=' . intval($draft_id) . ' path=' . $clean_path);
                continue;
            }

            // Add title/tagline to clean source so finals match selected concept exactly
            // self::overlay_title_and_tagline($clean_path, $brief, 0, 0);

            // 2) Create watermarked display copy
$display_path = str_replace('preview-clean', 'preview', $clean_path);
copy($clean_path, $display_path);

// Add title/tagline only to watermarked preview display copy.
self::overlay_title_and_tagline($display_path, $brief, 0, 0);

self::apply_watermark($display_path);

            // 3) Show watermarked copy to user, but keep matching clean source on disk
            $files[] = CMSG_Jobs::path_to_url($display_path);

            } elseif (!empty($last_quality_result)) {
                self::$last_preview_quality_message = $quality_message;
            }
        }
        if (!empty($files) && !empty(self::$last_preview_quality_failures)) {
            self::$last_preview_quality_message = $quality_message;
        }
        if (empty($files)) {
            if (!empty(self::$last_preview_quality_failures)) {
                self::$last_preview_quality_message = $quality_message;
                return new WP_Error('cmsg_poster_preview_quality_failed', $quality_message);
            }
            return self::generate_svg_fallback_previews($brief, $draft_id);
        }
    return $files;
}

private static function queue_poster_director_diagnostic_manifest($brief, $draft_id) {
    if (!class_exists('CMSG_Poster_Director')) {
        return false;
    }

    try {
        $actor_registry = self::actor_source_registry($brief);
        return CMSG_Poster_Director::queue_diagnostic_manifest($brief, $actor_registry, $draft_id);
    } catch (Throwable $e) {
        error_log('CMSG POSTER DIRECTOR DIAGNOSTIC QUEUE FAIL: draft_id=' . intval($draft_id) . ' message=' . $e->getMessage());
        return false;
    }
}

private static function reject_preview_candidate($clean_path) {
    if (!is_string($clean_path) || $clean_path === '') return;

    $failed_path = preg_replace('/\.png$/i', '-failed_quality_check.png', $clean_path);
    if ($failed_path && file_exists($clean_path)) {
        @rename($clean_path, $failed_path);
        @chmod($failed_path, 0664);
    }

    foreach (['banner'] as $key) {
        $family = self::preview_family_path($clean_path, $key);
        if (file_exists($family)) @unlink($family);
        $display = self::preview_family_display_path($clean_path, $key);
        if (file_exists($display)) @unlink($display);
    }

    $display_path = str_replace('preview-clean', 'preview', $clean_path);
    if (file_exists($display_path)) @unlink($display_path);
}

private static function preview_duplicate_face_quality_check($clean_path, $brief, $variant, $attempt) {
    $cast_count = self::cast_counts($brief)['total'];
    $layout = self::poster_layout_key($brief);

    if (!self::$enable_preview_quality_check) {
        return [
            'failed_quality_check' => false,
            'reason' => 'quality_check_disabled',
            'variant' => sanitize_key($variant),
            'attempt' => (int)$attempt,
            'cast_count' => $cast_count,
            'layout' => $layout,
        ];
    }

    if ($cast_count < 2 || $layout === 'no_cast_background_only') {
        return [
            'failed_quality_check' => false,
            'reason' => 'skipped_small_or_background_only',
            'variant' => sanitize_key($variant),
            'attempt' => (int)$attempt,
            'cast_count' => $cast_count,
            'layout' => $layout,
        ];
    }

    $script = plugin_dir_path(dirname(__FILE__)) . 'tools/detect-duplicate-faces.py';
    if (!file_exists($script)) {
        error_log('CMSG POSTER PREVIEW QUALITY CHECK SKIPPED: missing script at ' . $script);
        return [
            'failed_quality_check' => false,
            'reason' => 'quality_script_missing',
            'variant' => sanitize_key($variant),
            'attempt' => (int)$attempt,
            'cast_count' => $cast_count,
            'layout' => $layout,
        ];
    }

    $python = file_exists('/opt/cmsg-bgremove/bin/python') ? '/opt/cmsg-bgremove/bin/python' : 'python3';
    $threshold = (float) self::$duplicate_face_similarity_threshold;
    $cmd = escapeshellcmd($python)
        . ' ' . escapeshellarg($script)
        . ' ' . escapeshellarg($clean_path)
        . ' ' . escapeshellarg((string)$cast_count)
        . ' ' . escapeshellarg((string)$threshold);
    foreach (self::cast_actor_assets($brief) as $reference_path) {
        $cmd .= ' ' . escapeshellarg($reference_path);
    }
    $cmd .= ' 2>&1';
    $raw = shell_exec($cmd);
    $decoded = self::decode_detector_json_output($raw);

    if (is_array($decoded) && ($decoded['error'] ?? '') === 'missing_dependency') {
        error_log('CMSG POSTER PREVIEW QUALITY CHECK DEPENDENCY MISSING: missing=' . wp_json_encode($decoded['missing'] ?? []));
        return [
            'failed_quality_check' => false,
            'reason' => 'quality_check_missing_dependency',
            'variant' => sanitize_key($variant),
            'attempt' => (int)$attempt,
            'cast_count' => $cast_count,
            'layout' => $layout,
            'detector_method' => self::$duplicate_face_detector_method,
            'threshold' => $threshold,
            'missing_dependency' => $decoded['missing'] ?? [],
        ];
    }

    if (!is_array($decoded) || empty($decoded['ok'])) {
        error_log('CMSG POSTER PREVIEW QUALITY CHECK INVALID RESULT: cmd=' . $cmd . ' raw=' . print_r($raw, true));
        return [
            'failed_quality_check' => false,
            'reason' => 'quality_check_invalid_result',
            'variant' => sanitize_key($variant),
            'attempt' => (int)$attempt,
            'cast_count' => $cast_count,
            'layout' => $layout,
            'detector_method' => self::$duplicate_face_detector_method,
            'threshold' => $threshold,
        ];
    }

    if (($decoded['method'] ?? '') === 'heuristic' && !empty($decoded['embedding_missing_dependencies'])) {
        error_log('CMSG POSTER PREVIEW QUALITY CHECK EMBEDDING FALLBACK: missing=' . wp_json_encode($decoded['embedding_missing_dependencies']));
    }

    return [
        'failed_quality_check' => !empty($decoded['likely_duplicate']),
        'reason' => implode(',', $decoded['reasons'] ?? []),
        'variant' => sanitize_key($variant),
        'attempt' => (int)$attempt,
        'cast_count' => $cast_count,
        'layout' => $layout,
        'detector_method' => sanitize_key($decoded['method'] ?? self::$duplicate_face_detector_method),
        'configured_detector_method' => self::$duplicate_face_detector_method,
        'threshold' => isset($decoded['threshold']) ? (float)$decoded['threshold'] : $threshold,
        'detected_face_count' => (int)($decoded['face_count'] ?? ($decoded['detected_face_count'] ?? 0)),
        'duplicate_pairs' => $decoded['duplicate_pairs'] ?? [],
        'pair_scores' => $decoded['pair_scores'] ?? [],
        'reference_duplicate_pairs' => $decoded['reference_duplicate_pairs'] ?? [],
        'reference_pair_scores' => $decoded['reference_pair_scores'] ?? [],
        'reference_count' => (int)($decoded['reference_count'] ?? 0),
        'warnings' => $decoded['warnings'] ?? [],
        'embedding_missing_dependencies' => $decoded['embedding_missing_dependencies'] ?? [],
        'embedding_error' => sanitize_text_field($decoded['embedding_error'] ?? ''),
    ];
}

private static function generate_preview_format_family($clean_path, $brief) {
    if (empty($clean_path) || !file_exists($clean_path)) return false;

    $manifest = self::read_campaign_manifest_json(self::campaign_manifest_path($clean_path));
    if (!empty($manifest)) {
        $family = self::campaign_variant_config();
        unset($family['vertical']);

        foreach ($family as $key => $cfg) {
            $family_path = self::preview_family_path($clean_path, $key);
            if (!file_exists($family_path) || filesize($family_path) <= 0) {
                if (!self::adapt_layered_campaign_to_variant($clean_path, $manifest, $key, (int)$cfg['w'], (int)$cfg['h'])) {
                    return false;
                }
            }

            if (file_exists($family_path) && filesize($family_path) > 0) {
                $display_path = self::preview_family_display_path($clean_path, $key);
                @copy($family_path, $display_path);
                self::overlay_title_and_tagline($display_path, $brief, 0, 0);
                self::apply_watermark($display_path);
                @chmod($display_path, 0664);
            }
        }

        return true;
    }

    if (self::poster_requires_layered_campaign($brief)) {
        error_log('CMSG POSTER ROUTE BYPASS DETECTED: banner_ai_regeneration_attempted_when_layered_required clean_path=' . (string)$clean_path);
        error_log('CMSG POSTER ROUTE FAIL CLOSED: layered_family_manifest_missing clean_path=' . (string)$clean_path);
        return false;
    }

    $master_path = self::create_campaign_master_source($clean_path, $brief);
    if (!$master_path) {
        return false;
    }

    $family = self::campaign_variant_config();
    unset($family['vertical']);

    foreach ($family as $key => $cfg) {
        $family_path = self::preview_family_path($clean_path, $key);
        if (file_exists($family_path) && filesize($family_path) > 0) {
            continue;
        }

        if (!self::generate_campaign_adaptation($master_path, $brief, $key, (int)$cfg['w'], (int)$cfg['h'], $family_path)) {
            return false;
        }

        if (file_exists($family_path) && filesize($family_path) > 0) {
            $display_path = self::preview_family_display_path($clean_path, $key);
            @copy($family_path, $display_path);
            self::overlay_title_and_tagline($display_path, $brief, 0, 0);
            self::apply_watermark($display_path);
            @chmod($display_path, 0664);
        }
    }

    return true;
}

private static function campaign_variant_config() {
    return [
        'vertical' => [
            'w' => 900,
            'h' => 1285,
            'size' => '1024x1536',
        ],
        'banner' => [
            'w' => 895,
            'h' => 504,
            'size' => '1536x1024',
        ],
    ];
}

private static function create_campaign_master_source($selected_preview_path, $brief) {
    if (empty($selected_preview_path) || !file_exists($selected_preview_path) || filesize($selected_preview_path) <= 0) {
        error_log('CMSG CAMPAIGN MASTER: missing selected_preview_path=' . (string)$selected_preview_path);
        return '';
    }

    $master_path = preg_replace('/\.png$/i', '-campaign-master.png', $selected_preview_path);
    if (!$master_path) {
        return '';
    }

    if (!file_exists($master_path) || filesize($master_path) <= 0 || filemtime($master_path) < filemtime($selected_preview_path)) {
        @copy($selected_preview_path, $master_path);
        @chmod($master_path, 0664);
    }

    error_log('CMSG CAMPAIGN MASTER: selected=' . $selected_preview_path . ' master=' . $master_path . ' exists=' . (file_exists($master_path) ? 'yes' : 'no'));
    return (file_exists($master_path) && filesize($master_path) > 0) ? $master_path : '';
}

private static function generate_campaign_adaptation($campaign_master_path, $brief, $variant, $target_w, $target_h, $out) {
    $variant = sanitize_key($variant);
    $target_w = (int)$target_w;
    $target_h = (int)$target_h;
    if (empty($campaign_master_path) || !file_exists($campaign_master_path) || $target_w <= 0 || $target_h <= 0 || empty($out)) {
        error_log('CMSG CAMPAIGN ADAPTATION: invalid_input variant=' . $variant . ' master=' . (string)$campaign_master_path . ' out=' . (string)$out);
        return false;
    }

    if ($variant === 'vertical') {
        @copy($campaign_master_path, $out);
        self::resize_png_cover_no_overlay($out, $out, $target_w, $target_h);
        @chmod($out, 0664);
        error_log('CMSG CAMPAIGN VARIANT: variant=vertical mode=master_resize out=' . $out);
        return file_exists($out) && filesize($out) > 0;
    }

    $api_key = trim((string) CMSG_Plugin::settings()['openai_api_key']);
    if (!$api_key) {
        error_log('CMSG CAMPAIGN ADAPTATION: missing_openai_key variant=' . $variant);
        return false;
    }

    $prompt = self::build_campaign_adaptation_prompt($brief, $variant, $target_w, $target_h);
    $edit_brief = is_array($brief) ? $brief : [];
    $edit_brief['style_reference'] = $campaign_master_path;
    if (empty($edit_brief['poster_assets']) || !is_array($edit_brief['poster_assets'])) {
        $edit_brief['poster_assets'] = [];
    }

    $size = ($target_w > $target_h) ? '1536x1024' : '1024x1536';
    error_log('CMSG CAMPAIGN ADAPTATION: variant=' . $variant . ' master=' . $campaign_master_path . ' out=' . $out . ' target=' . $target_w . 'x' . $target_h . ' size=' . $size);
    $response = self::call_image_edit($api_key, $prompt, $edit_brief, $size);
    if (is_wp_error($response)) {
        error_log('CMSG CAMPAIGN ADAPTATION ERROR: ' . $response->get_error_message());
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    if ($code < 200 || $code >= 300 || empty($data['data'][0]['b64_json'])) {
        error_log('CMSG CAMPAIGN ADAPTATION ERROR CODE: ' . $code . ' BODY: ' . $body);
        return false;
    }

    $image_data = base64_decode($data['data'][0]['b64_json']);
    if (!$image_data) return false;

    file_put_contents($out, $image_data);
    @chmod($out, 0664);
    self::resize_png_cover_no_overlay($out, $out, $target_w, $target_h);
    error_log('CMSG CAMPAIGN VARIANT: variant=' . $variant . ' mode=campaign_adaptation out=' . $out);

    return file_exists($out) && filesize($out) > 0;
}

private static function build_campaign_adaptation_prompt($brief, $variant, $target_w, $target_h) {
    $variant = sanitize_key($variant);
    $title = sanitize_text_field($brief['title'] ?? ($brief['movie_title'] ?? 'Untitled Film'));
    $layout = ($variant === 'banner')
        ? 'wide 895x504 streaming platform hero banner'
        : 'vertical 900x1285 theatrical poster';

    $prompt = "This is not a new poster generation. This is a campaign artwork adaptation task.\n";
    $prompt .= "The uploaded selected preview is the approved master campaign artwork for {$title}.\n";
    $prompt .= "Adapt the approved master artwork into a {$layout}.\n";
    $prompt .= "Preserve the exact same faces, wardrobe, pose direction, expressions, lighting, color grade, vehicle, props, symbols, and background story visible in the approved master.\n";
    $prompt .= "Only adapt the canvas and composition to the requested output size {$target_w}x{$target_h}.\n";
    $prompt .= "Do not redesign or replace any cast member. Do not add duplicate actors. Do not introduce new people.\n";
    $prompt .= "Do not add props, symbols, skylines, cars, or background elements that are not visible in the approved master.\n";
    $prompt .= "Do not change character count, wardrobe, facial likeness, emotional expression, vehicle, color palette, lighting, or movie concept.\n";
    $prompt .= "Keep every head, forehead, eyes, mouth, chin, and face fully visible with safe margins.\n";
    if ($variant === 'banner') {
        $prompt .= "For the banner, expand or outpaint left and right background only where needed, using the same campaign world and color grade from the approved master.\n";
        $prompt .= "Reposition or extend the approved composition only enough to fit the wide landscape canvas while preserving the approved campaign identity.\n";
        $prompt .= "Leave lower-center title-safe space for typography. Do not render the movie title, tagline, credits, or readable text.\n";
    } else {
        $prompt .= "Preserve the vertical master composition as closely as possible. Do not render title, tagline, credits, or readable text.\n";
    }
    $prompt .= "The result must feel like one Netflix/Tubi/Amazon campaign adapted into another platform size, not a separate poster.\n";

    return $prompt;
}

private static function preview_family_path($clean_path, $key) {
    return preg_replace('/\.png$/i', '-' . sanitize_key($key) . '.png', $clean_path);
}

private static function preview_family_display_path($clean_path, $key) {
    return str_replace('preview-clean', 'preview', self::preview_family_path($clean_path, $key));
}

private static function generate_identity_composite_family_source($brief, $out, $key, $openai_size) {
    $cast_assets = self::cast_actor_assets($brief);
    if (empty($cast_assets)) {
        return false;
    }

    $background_brief = self::background_only_brief($brief);
    if (!self::generate_background_only_file($background_brief, $out, $key, $openai_size)) {
        error_log('CMSG POSTER IDENTITY COMPOSITE FAMILY ERROR: background_failed key=' . sanitize_key($key) . ' out=' . $out);
        return false;
    }

    if ($key === 'banner') {
        self::resize_png_cover_no_overlay($out, $out, 895, 504);
    } elseif ($key === 'vertical') {
        self::resize_png_cover_no_overlay($out, $out, 900, 1285);
    }

    $placed = self::composite_actor_assets($out, $cast_assets, $key, $brief);
    if ((int)$placed !== count($cast_assets)) {
        error_log('CMSG POSTER IDENTITY COMPOSITE FAMILY ERROR: actor_composite_failed key=' . sanitize_key($key) . ' out=' . $out . ' expected=' . count($cast_assets) . ' placed=' . intval($placed));
        return false;
    }

    @chmod($out, 0664);
    return file_exists($out) && filesize($out) > 0;
}

private static function generate_background_only_file($brief, $out, $variant, $openai_size, $diagnostics = []) {
    $api_key = trim((string) CMSG_Plugin::settings()['openai_api_key']);
    if (!$api_key) {
        error_log('CMSG POSTER IDENTITY COMPOSITE ERROR: OpenAI API key is missing for background generation.');
        return false;
    }

    $prompt = self::build_background_only_prompt($brief, $variant);
    self::write_background_prompt_audit(self::background_prompt_audit_path($out), self::background_prompt_audit($brief, $prompt));
    error_log('CMSG BACKGROUND OPAQUE SOURCE REQUESTED variant=' . sanitize_key($variant) . ' size=' . sanitize_text_field($openai_size) . ' out=' . $out);
    self::$last_background_openai_error = [];
    $response = self::call_image_generation($api_key, $prompt, $openai_size, 'opaque', $diagnostics);
    if (is_wp_error($response)) {
        self::$last_background_openai_error = self::background_openai_error_trace_fields([
            'http_status' => 0,
            'openai_error' => [
                'message' => $response->get_error_message(),
                'type' => '',
                'code' => $response->get_error_code(),
                'param' => '',
            ],
            'response_path' => is_array($diagnostics) ? (string)($diagnostics['response_path'] ?? '') : '',
        ]);
        error_log('CMSG POSTER IDENTITY COMPOSITE BACKGROUND OPENAI ERROR: ' . $response->get_error_message());
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($code < 200 || $code >= 300 || empty($data['data'][0]['b64_json'])) {
        $error = self::openai_response_error_summary($response, $body);
        self::$last_background_openai_error = self::background_openai_error_trace_fields([
            'http_status' => $code,
            'openai_error' => $error,
            'response_path' => is_array($diagnostics) ? (string)($diagnostics['response_path'] ?? '') : '',
        ]);
        error_log('CMSG POSTER IDENTITY COMPOSITE BACKGROUND OPENAI ERROR status=' . intval($code) . ' message=' . ($error['message'] ?? '') . ' type=' . ($error['type'] ?? '') . ' code=' . ($error['code'] ?? '') . ' param=' . ($error['param'] ?? ''));
        return false;
    }

    $image_data = base64_decode($data['data'][0]['b64_json']);
    if (!$image_data) {
        return false;
    }

    file_put_contents($out, $image_data);
    @chmod($out, 0664);

    return file_exists($out) && filesize($out) > 0;
}

private static function background_openai_diagnostic_path($background_path, $kind, $attempt = 0) {
    if (!is_string($background_path) || $background_path === '') return '';
    $dir = dirname($background_path);
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }

    $kind = sanitize_key($kind);
    $attempt = max(1, (int)$attempt);
    return trailingslashit($dir) . 'background-openai-' . $kind . '-attempt-' . $attempt . '.json';
}

private static function write_background_openai_diagnostic($path, $payload) {
    if (!is_string($path) || $path === '' || !is_array($payload)) return false;
    $dir = dirname($path);
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }

    $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === '') return false;

    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json) === false) return false;
    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0640);
    return true;
}

private static function openai_response_error_summary($response, $body = '') {
    $summary = [
        'message' => '',
        'type' => '',
        'code' => '',
        'param' => '',
    ];

    if (is_wp_error($response)) {
        $summary['message'] = $response->get_error_message();
        $summary['code'] = $response->get_error_code();
        return $summary;
    }

    if (!is_string($body) || $body === '') {
        $body = wp_remote_retrieve_body($response);
    }

    $decoded = json_decode((string)$body, true);
    if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
        $error = $decoded['error'];
        $summary['message'] = is_string($error['message'] ?? '') ? $error['message'] : '';
        $summary['type'] = is_string($error['type'] ?? '') ? $error['type'] : '';
        $summary['code'] = is_string($error['code'] ?? '') ? $error['code'] : '';
        $summary['param'] = is_string($error['param'] ?? '') ? $error['param'] : '';
    } elseif (is_array($decoded)) {
        $summary['message'] = is_string($decoded['message'] ?? '') ? $decoded['message'] : '';
        $summary['code'] = is_string($decoded['code'] ?? '') ? $decoded['code'] : '';
    } else {
        $summary['message'] = substr(trim((string)$body), 0, 500);
    }

    return $summary;
}

private static function background_openai_error_trace_fields($diagnostic) {
    $error = is_array($diagnostic['openai_error'] ?? null) ? $diagnostic['openai_error'] : [];
    return [
        'background_openai_http_status' => (int)($diagnostic['http_status'] ?? 0),
        'background_openai_error_message' => is_string($error['message'] ?? '') ? $error['message'] : '',
        'background_openai_error_type' => is_string($error['type'] ?? '') ? $error['type'] : '',
        'background_openai_error_code' => is_string($error['code'] ?? '') ? $error['code'] : '',
        'background_openai_error_param' => is_string($error['param'] ?? '') ? $error['param'] : '',
        'background_openai_response_path' => is_string($diagnostic['response_path'] ?? '') ? $diagnostic['response_path'] : '',
    ];
}

private static function sanitize_openai_response_for_diagnostics($decoded) {
    if (!is_array($decoded)) return $decoded;
    if (!empty($decoded['data']) && is_array($decoded['data'])) {
        foreach ($decoded['data'] as $index => $item) {
            if (is_array($item) && isset($item['b64_json']) && is_string($item['b64_json'])) {
                $decoded['data'][$index]['b64_json'] = [
                    'omitted' => true,
                    'byte_length' => strlen($item['b64_json']),
                    'sha256' => hash('sha256', $item['b64_json']),
                ];
            }
        }
    }
    return $decoded;
}

private static function build_background_only_prompt($brief, $variant = '') {
    $variant = sanitize_key($variant ?: 'vertical');
    $title = sanitize_text_field($brief['title'] ?? ($brief['movie_title'] ?? 'Untitled Film'));
    $genre = sanitize_text_field($brief['genre'] ?? 'Drama');
    $mood = sanitize_text_field($brief['mood'] ?? 'Cinematic');
    $style = sanitize_text_field($brief['style_preset'] ?? 'cinematic_premium');
    $evidence = self::extract_background_element_evidence($brief);
    $positive = array_values((array)($evidence['positive_elements'] ?? []));
    $scene = !empty($positive) ? implode(', ', array_slice($positive, 0, 10)) : self::build_genre_neutral_background_fallback($brief);

    foreach ((array)($evidence['excluded_elements'] ?? []) as $blocked) {
        error_log('CMSG BACKGROUND UNSUPPORTED ELEMENT BLOCKED element=' . sanitize_key($blocked));
    }

    $format_line = $variant === 'banner'
        ? 'Native wide streaming hero banner composition, 895x504 aspect ratio, broad horizontal depth.'
        : 'Native vertical theatrical key art background, 900x1285 aspect ratio, full poster depth.';

    error_log('CMSG BACKGROUND OPAQUE SOURCE REQUESTED variant=' . $variant);

    return trim("Create a full-bleed opaque cinematic background plate only.\n" .
        "POSTER PROJECT: {$title}\n" .
        "GENRE: {$genre}\n" .
        "MOOD: {$mood}\n" .
        "STYLE PRESET: {$style}\n" .
        "FORMAT: {$format_line}\n" .
        "EVIDENCE-SUPPORTED ENVIRONMENT: {$scene}\n" .
        "Full-bleed opaque cinematic background. Fill every pixel. No transparency, no alpha holes, no cutout shape, no vignette mask, no isolated floating artwork.\n" .
        "STRICT BACKGROUND-ONLY RULES: No people, no actors, no faces, no human bodies, no silhouettes, no portraits, no crowds, no reflections of people, no human figures in windows, no statues resembling people.\n" .
        "Do not infer religious buildings or symbols from the movie title. Use only environment elements explicitly supported by scene direction, setting, synopsis, prop descriptions, or visual-reference descriptions.\n" .
        "Avoid unsupported church, cathedral, chapel, cross, graveyard, monastery, mosque, temple, shrine, castle, or palace imagery unless explicitly requested in the brief.\n" .
        "Leave clean atmosphere and depth for deterministic actor and prop compositing later.");
}


private static function generate_native_preview_family_source($selected_preview_path, $out, $brief, $key, $openai_size) {
    $api_key = trim((string) CMSG_Plugin::settings()['openai_api_key']);
    if (!$api_key || empty($selected_preview_path) || !file_exists($selected_preview_path)) {
        return false;
    }

    $format_label = ($key === 'vertical')
        ? 'vertical portrait poster composition for 900x1285 final delivery'
        : 'wide landscape banner composition for 895x504 final delivery';

    $prompt = "Use the uploaded selected clean poster preview as the exact concept reference.\n";
    $prompt .= "Create a native {$format_label} version of the same poster concept now, during preview generation.\n";
    $prompt .= "Preserve the same cast, actor likenesses, emotional expressions, wardrobe, broken-heart symbol, lighting, color palette, mood, and city skyline concept.\n";
    $prompt .= "Recompose naturally for this output format. All actor faces, heads, eyes, mouths, hairlines, and important facial features must be fully visible inside the frame with safe margins.\n";
    $prompt .= "Do not crop off side faces. Do not cut off foreheads, chins, eyes, or partial faces.\n";
    if ($key === 'banner') {
        $prompt .= "This must be a true native wide landscape composition, not a crop from the vertical poster.\n";
        $prompt .= "Reflow the composition horizontally like an official streaming platform hero banner.\n";
        $prompt .= "Preserve all principal cast, preserve actor prominence hierarchy, preserve background storytelling, and preserve vehicle/logo/prop placement whenever possible.\n";
        $prompt .= "Never simply crop the center of the portrait poster. Never clip heads, faces, eyes, foreheads, chins, or important props.\n";
        $prompt .= "Maintain cinematic left-to-right balance and leave lower-center title-safe space for final typography.\n";
    }
    $prompt .= "Leave clean lower title-safe space. Do not render the movie title, tagline, credits, or any readable text; the plugin overlays typography later.\n";
    $prompt .= "The result must look like finished professional theatrical/streaming key art, not a collage and not a screenshot.\n";

    $edit_brief = [
        'style_reference' => $selected_preview_path,
        'poster_assets' => [],
    ];

    $response = self::call_image_edit($api_key, $prompt, $edit_brief, $openai_size);
    if (is_wp_error($response)) {
        error_log('CMSG PREVIEW FAMILY EDIT ERROR: ' . $response->get_error_message());
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($code < 200 || $code >= 300 || empty($data['data'][0]['b64_json'])) {
        error_log('CMSG PREVIEW FAMILY EDIT ERROR CODE: ' . $code . ' BODY: ' . $body);
        return false;
    }

    $image_data = base64_decode($data['data'][0]['b64_json']);
    if (!$image_data) return false;

    file_put_contents($out, $image_data);
    @chmod($out, 0664);

    if ($key === 'banner') {
        self::resize_png_cover_no_overlay($out, $out, 895, 504);
    } elseif ($key === 'vertical') {
        self::resize_png_cover_no_overlay($out, $out, 900, 1285);
    }

    return file_exists($out) && filesize($out) > 0;
}

public static function generate_final_files($brief, $job_id, $selected_concept = 0) {
    self::$in_final_generation = true;
    self::$final_openai_calls = 0;

    $dir = trailingslashit(wp_upload_dir()['basedir']) . 'poster-finals';
    if (!is_dir($dir)) wp_mkdir_p($dir);

    $files = [];
    $slug = sanitize_title(($brief['title'] ?? 'poster') ?: 'poster');

    $variant_map = [
        'vertical' => [
            'prompt' => 'vertical',
            'w' => 900,
            'h' => 1285,
            'safe_w' => 810,
            'safe_h' => 1157,
        ],
        'banner' => [
            'prompt' => 'banner',
            'w' => 895,
            'h' => 504,
            'safe_w' => 806,
            'safe_h' => 454,
        ],
    ];

    $selected_display_path = $brief['selected_preview_path'] ?? '';
    $selected_preview_path = self::resolve_selected_preview_source($selected_display_path);

    if (empty($selected_preview_path) || !file_exists($selected_preview_path)) {
        error_log('CMSG POSTER FINAL SOURCE MISSING: selected=' . $selected_display_path . ' resolved=' . $selected_preview_path);
        self::$in_final_generation = false;
        return $files;
    }

    error_log('CMSG POSTER FINAL TRACE: selected_preview_file=' . $selected_display_path);
    error_log('CMSG POSTER FINAL TRACE: selected_clean_preview_file=' . $selected_preview_path);

    $layered_manifest = self::read_campaign_manifest_json(self::campaign_manifest_path($selected_preview_path));
    if (!empty($layered_manifest)) {
        error_log('CMSG POSTER FINAL TRACE: layered_campaign_manifest=' . self::campaign_manifest_path($selected_preview_path));
        $source_copy = trailingslashit($dir) . $slug . '-' . intval($job_id) . '-selected-source.png';
        @copy($selected_preview_path, $source_copy);
        @chmod($source_copy, 0664);

        $format_sources = [
            'vertical' => $selected_preview_path,
        ];

        foreach ($variant_map as $key => $cfg) {
            if ($key === 'vertical') continue;
            $family_path = self::preview_family_path($selected_preview_path, $key);
            if (!file_exists($family_path) || filesize($family_path) <= 0) {
                self::adapt_layered_campaign_to_variant($selected_preview_path, $layered_manifest, $key, (int)$cfg['w'], (int)$cfg['h']);
            }
            if (!file_exists($family_path) || filesize($family_path) <= 0) {
                error_log('CMSG POSTER FINAL TRACE: FINAL_EXPORT_ABORTED missing_layered_' . $key . '_source');
                self::$in_final_generation = false;
                return [];
            }
            $format_sources[$key] = $family_path;
        }

        foreach ($variant_map as $key => $cfg) {
            $out = trailingslashit($dir) . $slug . '-' . intval($job_id) . '-' . $key . '.png';
            $format_source = $format_sources[$key];
            error_log('CMSG POSTER FINAL TRACE: ' . $key . '_clean_source_file=' . $format_source);
            error_log('CMSG POSTER FINAL TRACE: ' . $key . '_export_mode=layered_campaign_manifest_source');
            self::resize_final_native_png($format_source, $out, $cfg['w'], $cfg['h'], $brief, $cfg);
            if (file_exists($out)) {
                @chmod($out, 0664);
                $files[$key] = $out;
            }
        }

        error_log('CMSG POSTER FINAL TRACE: openai_calls_during_finalization=' . intval(self::$final_openai_calls));
        self::$in_final_generation = false;
        return $files;
    }

    if (self::poster_requires_layered_campaign($brief)) {
        error_log('CMSG POSTER ROUTE FAIL CLOSED: final_missing_layered_manifest selected=' . $selected_preview_path . ' manifest=' . self::campaign_manifest_path($selected_preview_path));
        self::$in_final_generation = false;
        return [];
    }

    $campaign_master_path = self::create_campaign_master_source($selected_preview_path, $brief);
    if (!$campaign_master_path) {
        error_log('CMSG POSTER FINAL TRACE: FINAL_EXPORT_ABORTED missing_campaign_master');
        self::$in_final_generation = false;
        return [];
    }

    $source_copy = trailingslashit($dir) . $slug . '-' . intval($job_id) . '-selected-source.png';
    @copy($campaign_master_path, $source_copy);
    @chmod($source_copy, 0664);

    $format_sources = [];
    foreach ($variant_map as $key => $cfg) {
        $format_source = self::resolve_selected_preview_format_source($selected_preview_path, $key);
        if (!$format_source || !file_exists($format_source)) {
            $generated_source = self::preview_family_path($campaign_master_path, $key);
            if (self::generate_campaign_adaptation($campaign_master_path, $brief, $key, (int)$cfg['w'], (int)$cfg['h'], $generated_source)) {
                $format_source = $generated_source;
            }
        }
        if (!$format_source || !file_exists($format_source)) {
            error_log('CMSG POSTER FINAL TRACE: ' . $key . '_clean_source_file=MISSING');
            error_log('CMSG POSTER FINAL TRACE: FINAL_EXPORT_ABORTED missing_campaign_' . $key . '_source');
            self::$in_final_generation = false;
            return [];
        }
        $format_sources[$key] = $format_source;
    }

foreach ($variant_map as $key => $cfg) {
    $out = trailingslashit($dir) . $slug . '-' . intval($job_id) . '-' . $key . '.png';

    $format_source = $format_sources[$key];
    $export_mode = 'pre_generated_clean_source';
    if ($key === 'banner') {
        $export_mode = realpath($format_source) === realpath($selected_preview_path)
            ? 'selected_clean_preview_deterministic_cover_fallback'
            : 'campaign_variant_source';
    }
    error_log('CMSG POSTER FINAL TRACE: ' . $key . '_clean_source_file=' . $format_source);
    error_log('CMSG POSTER FINAL TRACE: ' . $key . '_export_mode=' . $export_mode);
    self::resize_final_native_png($format_source, $out, $cfg['w'], $cfg['h'], $brief, $cfg);

    if (file_exists($out)) {
        @chmod($out, 0664);
        $files[$key] = $out;
    }
}

    error_log('CMSG POSTER FINAL TRACE: openai_calls_during_finalization=' . intval(self::$final_openai_calls));
    self::$in_final_generation = false;

    return $files;
}

private static function resolve_selected_preview_format_source($selected_preview_path, $key) {
    $selected_preview_path = is_string($selected_preview_path) ? $selected_preview_path : '';
    $key = sanitize_key($key);
    if ($selected_preview_path === '' || $key === '') return '';

    if ($key === 'vertical' && file_exists($selected_preview_path) && filesize($selected_preview_path) > 0) {
        return $selected_preview_path;
    }

    $candidates = [];
    $campaign_master_path = preg_replace('/\.png$/i', '-campaign-master.png', $selected_preview_path);
    if ($campaign_master_path) {
        $candidates[] = $key === 'vertical'
            ? $campaign_master_path
            : self::preview_family_path($campaign_master_path, $key);
    }

    if ($key === 'banner') {
        $candidates[] = self::preview_family_path($selected_preview_path, $key);
    }

    if (strpos($selected_preview_path, 'poster-ai-preview-clean-') === false) {
        $clean_path = str_replace('poster-ai-preview-', 'poster-ai-preview-clean-', $selected_preview_path);
        if ($key === 'vertical' && file_exists($clean_path) && filesize($clean_path) > 0) {
            return $clean_path;
        }
        if ($key === 'banner') {
            $candidates[] = self::preview_family_path($clean_path, $key);
        }
    }

    $uploads = wp_upload_dir();
    $base = basename($selected_preview_path);
    $clean_base = str_replace('poster-ai-preview-', 'poster-ai-preview-clean-', $base);
    $family_base = $key === 'vertical'
        ? $clean_base
        : preg_replace('/\.png$/i', '-' . $key . '.png', $clean_base);
    $candidates[] = trailingslashit($uploads['basedir']) . 'poster-previews/' . $family_base;
    $candidates[] = trailingslashit($uploads['basedir']) . 'poster-finals/' . $family_base;

    foreach (array_values(array_unique(array_filter($candidates))) as $candidate) {
        if (file_exists($candidate) && filesize($candidate) > 0) {
            return $candidate;
        }
    }

    if ($key === 'banner' && file_exists($selected_preview_path) && filesize($selected_preview_path) > 0) {
        return $selected_preview_path;
    }

    return '';
}

private static function resolve_selected_preview_source($selected_preview_path) {
    $selected_preview_path = is_string($selected_preview_path) ? $selected_preview_path : '';
    if ($selected_preview_path === '') {
        return '';
    }

    $candidates = [];

    if (strpos($selected_preview_path, 'poster-ai-preview-clean-') !== false) {
        $candidates[] = $selected_preview_path;
    } else {
        $candidates[] = str_replace('poster-ai-preview-', 'poster-ai-preview-clean-', $selected_preview_path);
        $candidates[] = str_replace('preview-clean', 'preview', $selected_preview_path);
        $candidates[] = $selected_preview_path;
    }

    $uploads = wp_upload_dir();
    $base = basename($selected_preview_path);
    $clean_base = str_replace('poster-ai-preview-', 'poster-ai-preview-clean-', $base);
    $candidates[] = trailingslashit($uploads['basedir']) . 'poster-finals/' . $clean_base;
    $candidates[] = trailingslashit($uploads['basedir']) . 'poster-previews/' . $clean_base;
    $candidates[] = trailingslashit($uploads['basedir']) . 'poster-finals/' . $base;
    $candidates[] = trailingslashit($uploads['basedir']) . 'poster-previews/' . $base;

    foreach (array_values(array_unique(array_filter($candidates))) as $candidate) {
        if (file_exists($candidate) && filesize($candidate) > 0) {
            return $candidate;
        }
    }

    return '';
}

private static function poster_generation_mode($brief) {
    $mode = sanitize_key($brief['poster_generation_mode'] ?? 'auto');
    if (!in_array($mode, ['auto', 'single_pass', 'identity_composite', 'layered_campaign'], true)) {
        $mode = 'auto';
    }

    return $mode;
}

private static function poster_cast_count($brief) {
    $counts = self::cast_counts($brief);
    return (int)($counts['total'] ?? 0);
}

private static function poster_has_cast_assets($brief) {
    return self::poster_cast_count($brief) > 0;
}

private static function poster_preserve_identity_enabled($brief) {
    return !empty($brief['preserve_identity']);
}

private static function decode_detector_json_output($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') {
        error_log('CMSG DETECTOR JSON PARSE FAILED: empty_output');
        return [];
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $lines = preg_split('/\R+/', $raw);
    foreach (array_reverse((array)$lines) as $line) {
        $line = trim((string)$line);
        if ($line === '') continue;
        $candidate = json_decode($line, true);
        if (is_array($candidate)) {
            error_log('CMSG DETECTOR JSON RECOVERED: recovered_from_line');
            return $candidate;
        }
    }

    $pos = strrpos($raw, '{');
    if ($pos !== false) {
        $candidate = json_decode(substr($raw, $pos), true);
        if (is_array($candidate)) {
            error_log('CMSG DETECTOR JSON RECOVERED: recovered_from_last_brace');
            return $candidate;
        }
    }

    error_log('CMSG DETECTOR JSON PARSE FAILED: raw=' . substr($raw, 0, 500));
    return [];
}

private static function poster_requires_layered_campaign($brief) {
    $cast_count = self::poster_cast_count($brief);
    $preserve = self::poster_preserve_identity_enabled($brief);
    $mode = self::poster_generation_mode($brief);

    if ($preserve && $cast_count > 0) return true;
    if ($mode === 'layered_campaign') return true;
    if ($mode === 'identity_composite') return true;

    return false;
}

private static function poster_pipeline_trace_path($draft_id, $variant, $index) {
    $uploads = wp_upload_dir();
    $dir = trailingslashit($uploads['basedir']) . 'poster-previews';

    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }

    return trailingslashit($dir)
        . 'poster-pipeline-trace-'
        . intval($draft_id)
        . '-'
        . sanitize_key($variant)
        . '-'
        . intval($index)
        . '.json';
}

private static function layered_background_validation_path($output_path) {
    if (!is_string($output_path) || $output_path === '') return '';
    $background_path = trailingslashit(self::identity_composite_layer_dir($output_path)) . 'background_base.png';
    return self::background_validation_report_path($background_path);
}

private static function poster_route_trace_payload($brief, $route_selected, $fallback_reason = '', $output_path = '', $error_code = '', $error_message = '') {
    $manifest_path = $output_path ? self::campaign_manifest_path($output_path) : '';

    return [
        'poster_generation_mode' => self::poster_generation_mode($brief),
        'preserve_identity' => self::poster_preserve_identity_enabled($brief),
        'cast_count' => self::poster_cast_count($brief),
        'has_cast_assets' => self::poster_has_cast_assets($brief),
        'should_use_identity_composite' => self::should_use_identity_composite($brief),
        'requires_layered_campaign' => self::poster_requires_layered_campaign($brief),
        'route_selected' => sanitize_key($route_selected),
        'fallback_reason' => sanitize_key($fallback_reason),
        'output_path' => (string)$output_path,
        'manifest_path' => (string)$manifest_path,
        'actor_registry_audit_path' => $manifest_path ? self::actor_registry_audit_path($manifest_path) : '',
        'placement_audit_path' => $output_path ? self::campaign_render_audit_path($output_path) : '',
        'background_validation_path' => $output_path ? self::layered_background_validation_path($output_path) : '',
        'error_code' => sanitize_key($error_code),
        'error_message' => sanitize_text_field($error_message),
    ];
}

private static function write_poster_pipeline_trace($draft_id, $variant, $index, $trace) {
    $path = self::poster_pipeline_trace_path($draft_id, $variant, $index);

    if (!is_array($trace)) $trace = [];

    $payload = array_merge([
        'created_at' => gmdate('c'),
        'draft_id' => intval($draft_id),
        'variant' => sanitize_key($variant),
        'index' => intval($index),
    ], $trace);

    file_put_contents($path, wp_json_encode($payload, JSON_PRETTY_PRINT));
    @chmod($path, 0664);

    error_log('CMSG POSTER PIPELINE TRACE path=' . $path . ' route=' . sanitize_text_field($payload['route_selected'] ?? '') . ' mode=' . sanitize_text_field($payload['poster_generation_mode'] ?? ''));

    return $path;
}

private static function verify_layered_campaign_artifacts($output_path) {
    $manifest_path = self::campaign_manifest_path($output_path);
    $placement_audit_path = self::campaign_render_audit_path($output_path);
    $actor_registry_path = self::actor_registry_audit_path($manifest_path);

    $missing = [];

    foreach ([
        'manifest_path' => $manifest_path,
        'placement_audit_path' => $placement_audit_path,
        'actor_registry_audit_path' => $actor_registry_path,
    ] as $key => $path) {
        if (empty($path) || !file_exists($path) || filesize($path) <= 0) {
            $missing[$key] = $path;
        }
    }

    return [
        'ok' => empty($missing),
        'missing' => $missing,
        'manifest_path' => $manifest_path,
        'placement_audit_path' => $placement_audit_path,
        'actor_registry_audit_path' => $actor_registry_path,
        'background_validation_path' => self::layered_background_validation_path($output_path),
    ];
}

private static function should_use_identity_composite($brief) {
    $mode = self::poster_generation_mode($brief);
    if ($mode === 'single_pass') {
        return false;
    }

    if (in_array($mode, ['identity_composite', 'layered_campaign'], true)) {
        return count(self::cast_actor_assets($brief)) > 0;
    }

    return count(self::cast_actor_assets($brief)) > 0;
}

private static function generate_preview_candidate_file($brief, $draft_id, $variant, $index) {

    file_put_contents(
        WP_CONTENT_DIR . '/poster-route-test.log',
        date('c') . " ENTER generate_preview_candidate_file\n",
        FILE_APPEND
    );

    $mode = self::poster_generation_mode($brief);
    $cast_count = self::poster_cast_count($brief);
    $requires_layered = self::poster_requires_layered_campaign($brief);

    if ($requires_layered) {
        error_log('CMSG POSTER ROUTE SELECTED: layered_campaign draft_id=' . intval($draft_id) . ' variant=' . sanitize_key($variant) . ' index=' . intval($index) . ' mode=' . $mode . ' cast_count=' . intval($cast_count));

        $composite_path = self::create_layered_campaign_master($brief, $draft_id, $variant, $index);

file_put_contents(
    WP_CONTENT_DIR . '/poster-route-test.log',
    date('c') . " RETURN create_layered_campaign_master=" . var_export($composite_path, true) . "\n",
    FILE_APPEND
);

        if ($composite_path && file_exists($composite_path)) {
            $artifact_check = self::verify_layered_campaign_artifacts($composite_path);
            self::write_poster_pipeline_trace($draft_id, $variant, $index, array_merge(
                self::poster_route_trace_payload($brief, 'layered_campaign', '', $composite_path),
                [
                    'artifact_check' => $artifact_check,
                    'manifest_path' => $artifact_check['manifest_path'] ?? self::campaign_manifest_path($composite_path),
                    'actor_registry_audit_path' => $artifact_check['actor_registry_audit_path'] ?? '',
                    'placement_audit_path' => $artifact_check['placement_audit_path'] ?? '',
                    'background_validation_path' => $artifact_check['background_validation_path'] ?? '',
                ]
            ));

            if (empty($artifact_check['ok'])) {
                error_log('CMSG POSTER ROUTE FAIL CLOSED: layered_artifact_missing draft_id=' . intval($draft_id) . ' variant=' . sanitize_key($variant) . ' index=' . intval($index) . ' missing=' . wp_json_encode($artifact_check['missing'] ?? []));
                self::write_poster_pipeline_trace($draft_id, $variant, $index, array_merge(
                    self::poster_route_trace_payload($brief, 'layered_campaign', 'layered_artifact_missing', $composite_path, 'layered_artifact_missing', 'Layered campaign output is missing required manifest or audit artifacts.'),
                    ['artifact_check' => $artifact_check]
                ));
                return new WP_Error(
                    'layered_artifact_missing',
                    'Layered campaign generation did not produce the required manifest and audit files. Preview generation was stopped to preserve actor identity.'
                );
            }

            return $composite_path;
        }

        error_log('CMSG POSTER ROUTE FAIL CLOSED: layered_campaign_failed draft_id=' . intval($draft_id) . ' variant=' . sanitize_key($variant) . ' index=' . intval($index));
        self::write_poster_pipeline_trace($draft_id, $variant, $index, array_merge(
            self::poster_route_trace_payload(
                $brief,
                'layered_campaign',
                '',
                (string)$composite_path,
                'layered_campaign_failed_closed',
                'Layered campaign generation failed; fallback to single-pass is disabled.'
            ),
            self::$last_background_openai_error
        ));

        return new WP_Error(
            'layered_campaign_failed_closed',
            'Layered campaign generation failed. Fallback to single-pass generation is disabled to preserve actor identity.'
        );
    }

    if (self::should_use_identity_composite($brief)) {
        error_log('CMSG POSTER ROUTE SELECTED: layered_campaign_optional draft_id=' . intval($draft_id) . ' variant=' . sanitize_key($variant) . ' index=' . intval($index) . ' mode=' . $mode . ' cast_count=' . intval($cast_count));

        $composite_path = self::create_layered_campaign_master($brief, $draft_id, $variant, $index);
        if ($composite_path && file_exists($composite_path)) {
            $artifact_check = self::verify_layered_campaign_artifacts($composite_path);
            self::write_poster_pipeline_trace($draft_id, $variant, $index, array_merge(
                self::poster_route_trace_payload($brief, 'layered_campaign_optional', '', $composite_path),
                ['artifact_check' => $artifact_check]
            ));

            if (!empty($artifact_check['ok'])) {
                return $composite_path;
            }

            error_log('CMSG POSTER ROUTE FAIL CLOSED: optional_layered_artifact_missing draft_id=' . intval($draft_id) . ' variant=' . sanitize_key($variant) . ' index=' . intval($index) . ' missing=' . wp_json_encode($artifact_check['missing'] ?? []));
            return '';
        }

        self::write_poster_pipeline_trace($draft_id, $variant, $index, self::poster_route_trace_payload(
            $brief,
            'layered_campaign_optional',
            '',
            (string)$composite_path,
            'optional_layered_campaign_failed',
            'Optional layered campaign generation failed.'
        ));
        return '';
    }

    if ($requires_layered) {
        error_log('CMSG POSTER ROUTE BYPASS DETECTED: attempted_single_pass_when_layered_required draft_id=' . intval($draft_id) . ' variant=' . sanitize_key($variant) . ' index=' . intval($index));
        self::write_poster_pipeline_trace($draft_id, $variant, $index, self::poster_route_trace_payload(
            $brief,
            'bypass_blocked',
            'single_pass_attempted_when_layered_required',
            '',
            'layered_route_bypass_blocked',
            'Single-pass generation was attempted even though layered campaign mode is required.'
        ));
        return new WP_Error(
            'layered_route_bypass_blocked',
            'Layered campaign mode is required for this poster because cast identity preservation is enabled. Single-pass fallback was blocked.'
        );
    }

    error_log('CMSG POSTER ROUTE SELECTED: single_pass draft_id=' . intval($draft_id) . ' variant=' . sanitize_key($variant) . ' index=' . intval($index) . ' mode=' . $mode . ' cast_count=' . intval($cast_count));
    self::write_poster_pipeline_trace($draft_id, $variant, $index, self::poster_route_trace_payload($brief, 'single_pass'));
    return self::generate_image_file($brief, $draft_id, 'preview-clean', $variant, $index, false);
}

private static function background_only_brief($brief) {
    $background = $brief;
    $background['background_only'] = true;
    $background['poster_generation_mode'] = 'single_pass';
    $background['poster_layout'] = 'no_cast_background_only';
    $background['cast_members'] = [];
    $background['cast_actor_1'] = '';
    $background['cast_actor_2'] = '';
    $background['cast_actor_3'] = '';
    $background['cast_actor_1_instruction'] = '';
    $background['cast_actor_2_instruction'] = '';
    $background['cast_actor_3_instruction'] = '';

    return $background;
}

private static function generate_identity_composite_preview_file($brief, $draft_id, $variant, $index) {
    return self::create_layered_campaign_master($brief, $draft_id, $variant, $index);
}

private static function create_layered_campaign_master($brief, $draft_id, $variant, $index) {

error_log(
    'CMSG ENTER create_layered_campaign_master draft_id=' .
    intval($draft_id) .
    ' variant=' . sanitize_key($variant) .
    ' index=' . intval($index)
);

    $variant = sanitize_key($variant ?: 'vertical');
    $out_path = self::preview_clean_file_path($draft_id, $variant, $index);
    $campaign_id = sanitize_key('draft-' . intval($draft_id) . '-' . $variant . '-' . intval($index) . '-' . substr(md5($out_path), 0, 10));
    $layer_dir = self::identity_composite_layer_dir($out_path);
    $background_path = trailingslashit($layer_dir) . 'background_base.png';
    $manifest_path = self::campaign_manifest_path($out_path);
    $actor_registry = self::actor_source_registry($brief);
    self::write_actor_registry_audit($manifest_path, $actor_registry);
    $cast_records = self::accepted_actor_source_records($actor_registry);
    $cast_assets = array_values(array_map(function($row) {
        return $row['source_path'] ?? '';
    }, $cast_records));

file_put_contents(
    WP_CONTENT_DIR . '/poster-route-test.log',
    date('c') . " STEP 1 cast assets prepared count=" . count($cast_assets) . "\n",
    FILE_APPEND
);

    error_log('CMSG LAYERED CAMPAIGN START: campaign_id=' . $campaign_id . ' draft_id=' . intval($draft_id) . ' variant=' . $variant . ' cast_count=' . count($cast_assets));
    error_log('CMSG PROFESSIONAL RECOVERY START campaign_id=' . $campaign_id . ' variant=' . $variant . ' cast_count=' . count($cast_assets));
    error_log('CMSG PROFESSIONAL RECOVERY TEMPLATE campaign_id=' . $campaign_id . ' template=' . self::professional_recovery_template_key($brief, count($cast_assets)));

    if (empty($cast_assets)) {
        error_log('CMSG LAYERED QUALITY FAIL: no_cast_assets campaign_id=' . $campaign_id);
        return '';
    }

    $background = self::generate_background_plate_only($brief, $variant, '1024x1536', $background_path);

    file_put_contents(
    WP_CONTENT_DIR . '/poster-route-test.log',
    date('c') . " STEP 2 background result=" . var_export($background, true) . "\n",
    FILE_APPEND
);

    if (!$background) {
        error_log('CMSG LAYERED QUALITY FAIL: background_generation_failed campaign_id=' . $campaign_id);
        return '';
    }

    self::resize_png_cover_no_overlay($background, $background, 900, 1285);

    self::register_poster_actor_trace_shutdown([
        'function' => 'create_layered_campaign_master',
        'campaign_id' => $campaign_id,
        'draft_id' => intval($draft_id),
        'variant' => $variant,
        'index' => intval($index),
        'layer_dir' => $layer_dir,
    ]);
    self::poster_actor_trace('ACTOR_CALL_BEFORE', 'About to call prepare_campaign_actor_cutouts().', [
        'campaign_id' => $campaign_id,
        'draft_id' => intval($draft_id),
        'variant' => $variant,
        'index' => intval($index),
        'layer_dir' => $layer_dir,
        'cast_record_count' => count($cast_records),
        'cast_asset_count' => count($cast_assets),
        'background' => self::poster_actor_trace_file_state($background),
    ]);
    $actor_call_started = microtime(true);
    try {
        $actor_layers = self::prepare_campaign_actor_cutouts($brief, $layer_dir, $cast_records);
    } catch (Throwable $e) {
        self::poster_actor_trace('ACTOR_EXCEPTION', 'Throwable during prepare_campaign_actor_cutouts().', [
            'campaign_id' => $campaign_id,
            'draft_id' => intval($draft_id),
            'variant' => $variant,
            'exception_class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'elapsed_ms' => (int)round((microtime(true) - $actor_call_started) * 1000),
        ]);
        throw $e;
    }
    self::poster_actor_trace('ACTOR_CALL_AFTER', 'Returned from prepare_campaign_actor_cutouts().', [
        'campaign_id' => $campaign_id,
        'draft_id' => intval($draft_id),
        'variant' => $variant,
        'elapsed_ms' => (int)round((microtime(true) - $actor_call_started) * 1000),
    ]);
    self::poster_actor_trace('ACTOR_CALL_RESULT_TYPE', 'Actor call result classified.', [
        'campaign_id' => $campaign_id,
        'draft_id' => intval($draft_id),
        'variant' => $variant,
        'result_type' => is_wp_error($actor_layers) ? 'WP_Error' : gettype($actor_layers),
        'count' => is_array($actor_layers) ? count($actor_layers) : null,
        'wp_error' => self::poster_actor_trace_wp_error($actor_layers),
    ]);

    file_put_contents(
    WP_CONTENT_DIR . '/poster-route-test.log',
    date('c') . " STEP 3 actor layers type=" .
    (is_wp_error($actor_layers) ? 'WP_Error' : gettype($actor_layers)) .
    (is_array($actor_layers) ? ' count=' . count($actor_layers) : '') .
    "\n",
    FILE_APPEND
);


    if (is_wp_error($actor_layers)) {
        self::append_poster_diagnostic_log('poster-route-test.log', array_merge([
            'event' => 'prepare_campaign_actor_cutouts_wp_error',
            'function' => 'create_layered_campaign_master',
            'campaign_id' => $campaign_id,
            'draft_id' => intval($draft_id),
            'variant' => $variant,
            'index' => intval($index),
            'layer_dir' => $layer_dir,
        ], self::wp_error_diagnostic_payload($actor_layers)));
        error_log('CMSG PROFESSIONAL RECOVERY QUALITY FAIL code=' . $actor_layers->get_error_code() . ' message=' . $actor_layers->get_error_message());
        return '';
    }
    if (count($actor_layers) !== count($cast_assets)) {
        error_log('CMSG LAYERED QUALITY FAIL: actor_layer_count_mismatch campaign_id=' . $campaign_id . ' expected=' . count($cast_assets) . ' prepared=' . count($actor_layers));
        return '';
    }

    $prop_layers = self::prepare_required_vehicle_layers($brief, $layer_dir, $campaign_id);

    file_put_contents(
    WP_CONTENT_DIR . '/poster-route-test.log',
    date('c') . " STEP 4 prop layers type=" .
    (is_wp_error($prop_layers) ? 'WP_Error' : gettype($prop_layers)) .
    (is_array($prop_layers) ? ' count=' . count($prop_layers) : '') .
    "\n",
    FILE_APPEND
);

     if (is_wp_error($prop_layers)) {
        error_log('CMSG PROFESSIONAL RECOVERY QUALITY FAIL code=' . $prop_layers->get_error_code() . ' message=' . $prop_layers->get_error_message());
        return '';
    }

    $manifest = self::build_campaign_layer_manifest($brief, $background, $actor_layers, $variant, is_array($prop_layers) ? $prop_layers : []);

    file_put_contents(
    WP_CONTENT_DIR . '/poster-route-test.log',
    date('c') . " STEP 5 manifest layers=" .
    (is_array($manifest) && isset($manifest['layers']) && is_array($manifest['layers'])
        ? count($manifest['layers'])
        : 0) .
    "\n",
    FILE_APPEND
);

  if (empty($manifest) || empty($manifest['layers'])) {
        error_log('CMSG LAYERED QUALITY FAIL: manifest_actor_registry_empty campaign_id=' . $campaign_id);
        return '';
    }

    $manifest['campaign_id'] = $campaign_id;
    $manifest['master'] = [
        'path' => $out_path,
        'width' => 900,
        'height' => 1285,
    ];

    if (!self::validate_campaign_manifest_layer_uniqueness($manifest)) {
        error_log('CMSG LAYERED QUALITY FAIL: manifest_actor_registry_invalid campaign_id=' . $campaign_id);
        return '';
    }

    $campaign_layout = self::build_campaign_master_layout($manifest, $brief);

    file_put_contents(
    WP_CONTENT_DIR . '/poster-route-test.log',
    date('c') . " STEP 6 campaign layout built type=" . gettype($campaign_layout) . "\n",
    FILE_APPEND
);

    if (!self::validate_campaign_layout_actor_consistency($campaign_layout, $manifest)) {
        error_log('CMSG CAMPAIGN LAYOUT INVALID campaign_id=' . $campaign_id);
        return '';
    }

    $layout_path = self::campaign_layout_path($manifest_path);
    $vertical_layout_path = self::campaign_layout_path($manifest_path, 'vertical');
    $banner_layout_path = self::campaign_layout_path($manifest_path, 'banner');
    $vertical_layout = self::transform_campaign_layout_for_variant($campaign_layout, 'vertical', 900, 1285);
    $banner_layout = self::transform_campaign_layout_for_variant($campaign_layout, 'banner', 895, 504);

    if (
        !self::write_campaign_layout_json($campaign_layout, $layout_path)
        || !self::write_campaign_layout_json($vertical_layout, $vertical_layout_path)
        || !self::write_campaign_layout_json($banner_layout, $banner_layout_path)
    ) {
        error_log('CMSG CAMPAIGN LAYOUT INVALID reason=layout_write_failed campaign_id=' . $campaign_id);
        return '';
    }

    $manifest['campaign_layout_path'] = $layout_path;
    $manifest['campaign_layout_paths'] = [
        'master' => $layout_path,
        'vertical' => $vertical_layout_path,
        'banner' => $banner_layout_path,
    ];
    $manifest['variant_layouts']['vertical']['actor_slots'] = self::campaign_layout_slots($vertical_layout, 'vertical');
    $manifest['variant_layouts']['banner']['actor_slots'] = self::campaign_layout_slots($banner_layout, 'banner');
    $manifest['typography_layout'] = $campaign_layout['typography'] ?? [];

    file_put_contents(
    WP_CONTENT_DIR . '/poster-route-test.log',
    date('c') . " STEP 7 about to write manifest path=" . $manifest_path . "\n",
    FILE_APPEND
);

    if (!self::write_campaign_manifest_json($manifest, $manifest_path)) {
        error_log('CMSG LAYERED QUALITY FAIL: manifest_write_failed campaign_id=' . $campaign_id . ' path=' . $manifest_path);
        return '';
    }

    file_put_contents(
    WP_CONTENT_DIR . '/poster-route-test.log',
    date('c') . " STEP 8 about to composite output=" . $out_path . "\n",
    FILE_APPEND
);

    if (!self::composite_layered_campaign($manifest, $out_path)) {
        error_log('CMSG LAYERED QUALITY FAIL: composite_failed campaign_id=' . $campaign_id);
        return '';
    }

    file_put_contents(
    WP_CONTENT_DIR . '/poster-route-test.log',
    date('c') . " STEP 9 composite completed\n",
    FILE_APPEND
);

    $rendered = self::adapt_layered_campaign_to_variant($out_path, $manifest, 'banner', 895, 504);
    if (!$rendered) {
        error_log('CMSG LAYERED QUALITY FAIL: banner_variant_failed campaign_id=' . $campaign_id);
        return '';
    }

    return $out_path;
}

private static function campaign_manifest_path($master_path) {
    if (!is_string($master_path) || $master_path === '') return '';
    return preg_replace('/\.png$/i', '-campaign-manifest.json', $master_path);
}

private static function campaign_layout_path($manifest_path, $variant = '') {
    if (!is_string($manifest_path) || $manifest_path === '') return '';
    $variant = sanitize_key($variant);
    $suffix = $variant !== '' ? '-campaign-layout-' . $variant . '.json' : '-campaign-layout.json';
    return preg_replace('/-campaign-manifest\.json$/', $suffix, $manifest_path);
}

private static function write_campaign_layout_json($layout, $path) {
    if (!is_array($layout) || !is_string($path) || $path === '') return false;
    file_put_contents($path, wp_json_encode($layout, JSON_PRETTY_PRINT));
    @chmod($path, 0664);
    return file_exists($path) && filesize($path) > 0;
}

private static function read_campaign_layout_json($path) {
    if (!is_string($path) || $path === '' || !file_exists($path)) return [];
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

private static function build_campaign_master_layout($manifest, $brief) {
    $actors = [];
    foreach ((array)($manifest['layers'] ?? []) as $layer) {
        if (($layer['type'] ?? '') !== 'actor') continue;
        $actor_id = sanitize_key($layer['actor_id'] ?? '');
        if ($actor_id === '') continue;

        $actors[$actor_id] = [
            'actor_index' => (int)($layer['actor_index'] ?? 0),
            'role' => sanitize_key($layer['role'] ?? ''),
            'x' => (float)($layer['x'] ?? 0.50),
            'y' => (float)($layer['y'] ?? 0.14),
            'w' => (float)($layer['w'] ?? 0.42),
            'h' => (float)($layer['h'] ?? 0.50),
            'z' => (int)($layer['z'] ?? 30),
            'anchor' => 'top_center',
            'locked' => true,
        ];
    }

    uasort($actors, function($a, $b) {
        return (int)($a['actor_index'] ?? 0) <=> (int)($b['actor_index'] ?? 0);
    });

    $layout = [
        'campaign_id' => sanitize_key($manifest['campaign_id'] ?? ''),
        'source' => 'campaign_master_layout',
        'base_aspect' => 'vertical',
        'base_size' => ['w' => 900, 'h' => 1285],
        'created_at' => gmdate('c'),
        'layout' => sanitize_key($manifest['layout'] ?? self::poster_layout_key($brief)),
        'actors' => $actors,
        'props' => [],
        'typography' => [
            'title' => [
                'anchor' => 'bottom_center',
                'x' => 0.50,
                'baseline' => 0.86,
                'max_width' => 0.78,
                'scale' => 1.00,
                'locked' => true,
            ],
        ],
        'safe_areas' => [
            'vertical' => ['left' => 0.06, 'right' => 0.94, 'top' => 0.05, 'bottom' => 0.94],
            'banner' => ['left' => 0.07, 'right' => 0.93, 'top' => 0.08, 'bottom' => 0.86],
        ],
    ];

    $has_vehicle_reference = false;
    foreach (self::normalized_poster_asset_references($brief) as $reference) {
        if (($reference['type'] ?? '') === 'vehicle') {
            $has_vehicle_reference = true;
            break;
        }
    }

    if ($has_vehicle_reference) {
        $layout['props']['vehicle'] = [
            'x' => 0.50,
            'y' => 0.80,
            'w' => 0.72,
            'h' => 0.20,
            'z' => 20,
            'locked' => true,
            'embedded' => true,
        ];
        error_log('CMSG CAMPAIGN LAYOUT PROP EMBEDDED vehicle campaign_id=' . sanitize_key($layout['campaign_id']));
    }

    error_log('CMSG CAMPAIGN LAYOUT BUILT campaign_id=' . sanitize_key($layout['campaign_id']) . ' actors=' . count($actors));
    return $layout;
}

private static function validate_campaign_layout($layout) {
    if (!is_array($layout) || ($layout['source'] ?? '') !== 'campaign_master_layout') return false;
    if (empty($layout['actors']) || !is_array($layout['actors'])) return false;

    $seen_indexes = [];
    $seen_z = [];
    foreach ($layout['actors'] as $actor_id => $actor) {
        $actor_id = sanitize_key($actor_id);
        $actor_index = (int)($actor['actor_index'] ?? -1);
        if ($actor_id === '' || $actor_index < 0) return false;
        if (isset($seen_indexes[$actor_index])) return false;
        $seen_indexes[$actor_index] = true;

        $z = (int)($actor['z'] ?? 0);
        while (isset($seen_z[$z])) {
            $z++;
        }
        $seen_z[$z] = true;
    }

    return true;
}

private static function validate_campaign_layout_actor_consistency($layout, $manifest) {
    if (!self::validate_campaign_layout($layout)) {
        error_log('CMSG CAMPAIGN LAYOUT INVALID reason=layout_schema');
        return false;
    }

    $manifest_actor_ids = [];
    foreach ((array)($manifest['layers'] ?? []) as $layer) {
        if (($layer['type'] ?? '') !== 'actor') continue;
        $actor_id = sanitize_key($layer['actor_id'] ?? '');
        if ($actor_id === '') continue;
        if (isset($manifest_actor_ids[$actor_id])) {
            error_log('CMSG CAMPAIGN LAYOUT INVALID reason=duplicate_manifest_actor actor_id=' . $actor_id);
            return false;
        }
        $manifest_actor_ids[$actor_id] = true;
    }

    $layout_actor_ids = [];
    foreach ((array)($layout['actors'] ?? []) as $actor_id => $actor) {
        $actor_id = sanitize_key($actor_id);
        if ($actor_id === '') {
            error_log('CMSG CAMPAIGN LAYOUT INVALID reason=empty_layout_actor');
            return false;
        }
        if (isset($layout_actor_ids[$actor_id])) {
            error_log('CMSG CAMPAIGN LAYOUT INVALID reason=duplicate_layout_actor actor_id=' . $actor_id);
            return false;
        }
        if (!isset($manifest_actor_ids[$actor_id])) {
            error_log('CMSG CAMPAIGN LAYOUT INVALID reason=layout_actor_not_in_manifest actor_id=' . $actor_id);
            return false;
        }
        $layout_actor_ids[$actor_id] = true;
    }

    if (count($layout_actor_ids) !== count($manifest_actor_ids)) {
        error_log('CMSG CAMPAIGN LAYOUT INVALID reason=actor_count_mismatch layout=' . count($layout_actor_ids) . ' manifest=' . count($manifest_actor_ids));
        return false;
    }

    return true;
}

private static function transform_campaign_layout_for_variant($layout, $variant, $target_w, $target_h) {
    if (!self::validate_campaign_layout($layout)) return [];

    $variant = sanitize_key($variant ?: 'vertical');
    $target_w = max(1, (int)$target_w);
    $target_h = max(1, (int)$target_h);
    $transformed = $layout;
    $transformed['source'] = 'campaign_master_layout';
    $transformed['variant'] = $variant;
    $transformed['target_size'] = ['w' => $target_w, 'h' => $target_h];
    $transformed['actors'] = [];

    $actors = (array)($layout['actors'] ?? []);
    uasort($actors, function($a, $b) {
        $x_cmp = (float)($a['x'] ?? 0.5) <=> (float)($b['x'] ?? 0.5);
        if ($x_cmp !== 0) return $x_cmp;
        return (int)($a['actor_index'] ?? 0) <=> (int)($b['actor_index'] ?? 0);
    });

    $safe = $layout['safe_areas'][$variant] ?? ['left' => 0.06, 'right' => 0.94, 'top' => 0.05, 'bottom' => 0.94];
    $safe_left = (float)($safe['left'] ?? 0.06);
    $safe_right = (float)($safe['right'] ?? 0.94);
    $safe_top = (float)($safe['top'] ?? 0.05);
    $safe_bottom = (float)($safe['bottom'] ?? 0.94);
    $count = count($actors);
    $rank = 0;

    foreach ($actors as $actor_id => $actor) {
        $actor_id = sanitize_key($actor_id);
        $base_x = (float)($actor['x'] ?? 0.50);
        $base_y = (float)($actor['y'] ?? 0.14);
        $base_w = (float)($actor['w'] ?? 0.42);
        $base_h = (float)($actor['h'] ?? 0.50);

        if ($variant === 'banner') {
            $spread_x = $count > 1
                ? $safe_left + (($safe_right - $safe_left) * ($rank / max(1, $count - 1)))
                : 0.50;
            $x = ($base_x * 0.35) + ($spread_x * 0.65);
            $y = $safe_top + min(0.30, max(0.02, $base_y * 0.42));
            $w = max(0.16, min(0.28, $base_w * 0.58));
            $h = max(0.44, min(0.74, $base_h * 0.82));
            if ($rank === 0) {
                $x = max($safe_left + ($w / 2), $x);
            } elseif ($rank === $count - 1) {
                $x = min($safe_right - ($w / 2), $x);
            }
        } else {
            $x = $base_x;
            $y = $base_y;
            $w = $base_w;
            $h = $base_h;
        }

        $x = max($safe_left + ($w / 2), min($safe_right - ($w / 2), $x));
        $y = max($safe_top, min($safe_bottom - min(0.22, $h * 0.34), $y));

        $transformed['actors'][$actor_id] = array_merge($actor, [
            'x' => $x,
            'y' => $y,
            'w' => $w,
            'h' => $h,
            'locked' => true,
            'transform_source' => 'campaign_master_layout',
        ]);
        $rank++;
    }

    if (!empty($transformed['typography']['title'])) {
        if ($variant === 'banner') {
            $transformed['typography']['title']['baseline'] = 0.74;
            $transformed['typography']['title']['max_width'] = 0.54;
            $transformed['typography']['title']['scale'] = 0.84;
        } else {
            $transformed['typography']['title']['baseline'] = 0.86;
            $transformed['typography']['title']['max_width'] = 0.78;
            $transformed['typography']['title']['scale'] = 1.00;
        }
        error_log('CMSG CAMPAIGN LAYOUT TITLE variant=' . $variant . ' baseline=' . $transformed['typography']['title']['baseline'] . ' max_width=' . $transformed['typography']['title']['max_width']);
    }

    error_log('CMSG CAMPAIGN LAYOUT TRANSFORMED variant=' . $variant . ' actors=' . count($transformed['actors']) . ' target=' . $target_w . 'x' . $target_h);
    return $transformed;
}

private static function campaign_layout_slots($layout, $variant) {
    $slots = [];
    foreach ((array)($layout['actors'] ?? []) as $actor_id => $actor) {
        $actor_id = sanitize_key($actor_id);
        if ($actor_id === '') continue;
        $slots[$actor_id] = [
            'actor_id' => $actor_id,
            'actor_index' => (int)($actor['actor_index'] ?? 0),
            'x' => (float)($actor['x'] ?? 0.50),
            'y' => (float)($actor['y'] ?? 0.15),
            'w' => (float)($actor['w'] ?? 0.36),
            'h' => (float)($actor['h'] ?? 0.46),
            'z_index' => (int)($actor['z'] ?? ((int)($actor['actor_index'] ?? 0) + 10)),
            'role' => sanitize_key($actor['role'] ?? ''),
            'anchor' => sanitize_key($actor['anchor'] ?? 'top_center'),
            'opacity' => 1.0,
            'shadow' => 0.68,
            'locked' => true,
            'slot_key' => sanitize_key($variant) . ':' . $actor_id . ':campaign_master_layout',
        ];
    }
    return $slots;
}

private static function generate_background_plate_only($brief, $variant, $openai_size, $out_path) {
    $variant = sanitize_key($variant ?: 'vertical');
    $background_brief = self::background_only_brief($brief);
    $raw_path = self::background_raw_path($out_path);
    $opaque_path = self::background_opaque_path($out_path);
    self::$last_background_openai_error = [];

    for ($attempt = 1; $attempt <= self::LAYERED_CAMPAIGN_BACKGROUND_RETRIES; $attempt++) {
        error_log('CMSG LAYERED BACKGROUND START: variant=' . $variant . ' attempt=' . intval($attempt) . ' out=' . $out_path);
        @unlink($raw_path);
        @unlink($opaque_path);
        @unlink($out_path);

        $previous_error = self::$last_background_openai_error;
        $request_path = self::background_openai_diagnostic_path($raw_path, 'request', $attempt);
        $response_path = self::background_openai_diagnostic_path($raw_path, 'response', $attempt);
        if (!self::generate_background_only_file($background_brief, $raw_path, $variant, $openai_size, [
            'attempt' => $attempt,
            'request_path' => $request_path,
            'response_path' => $response_path,
            'variant' => $variant,
            'output_path' => $raw_path,
            'retry_reason' => $attempt === 1 ? 'initial_attempt' : 'retry_after_previous_background_failure',
            'previous_error' => $previous_error,
        ])) {
            error_log('CMSG LAYERED BACKGROUND REJECTED: generation_failed variant=' . $variant . ' attempt=' . intval($attempt));
            continue;
        }

        error_log('CMSG LAYERED BACKGROUND GENERATED: variant=' . $variant . ' attempt=' . intval($attempt) . ' path=' . $raw_path);
        $raw_opacity = self::validate_background_plate_opacity($raw_path);
        $used_defensive_flatten = false;
        $validation_source = $raw_path;
        $opaque_opacity = $raw_opacity;

        if (empty($raw_opacity['ok'])) {
            error_log('CMSG BACKGROUND RAW TRANSPARENCY REJECTED variant=' . $variant . ' attempt=' . intval($attempt) . ' path=' . $raw_path . ' transparent_ratio=' . ($raw_opacity['transparent_ratio'] ?? 0) . ' partial_alpha_ratio=' . ($raw_opacity['partial_alpha_ratio'] ?? 0));
            if ($attempt < self::LAYERED_CAMPAIGN_BACKGROUND_RETRIES) {
                continue;
            }

            if (!self::flatten_background_plate_opaque($raw_path, $opaque_path, $brief)) {
                error_log('CMSG LAYERED QUALITY FAIL: background_opacity_normalization_failed variant=' . $variant . ' attempt=' . intval($attempt) . ' path=' . $raw_path);
                continue;
            }

            $opaque_opacity = self::validate_background_plate_opacity($opaque_path);
            $validation_source = $opaque_path;
            $used_defensive_flatten = true;
        } else {
            @copy($raw_path, $opaque_path);
            @chmod($opaque_path, 0664);
        }

        $opacity_report = [
            'raw_background_path' => $raw_path,
            'opaque_background_path' => $opaque_path,
            'raw_transparent_ratio' => (float)($raw_opacity['transparent_ratio'] ?? 0),
            'raw_partial_alpha_ratio' => (float)($raw_opacity['partial_alpha_ratio'] ?? 0),
            'opaque_transparent_ratio' => (float)($opaque_opacity['transparent_ratio'] ?? 0),
            'opaque_partial_alpha_ratio' => (float)($opaque_opacity['partial_alpha_ratio'] ?? 0),
            'opacity_normalized' => $used_defensive_flatten,
            'opacity_ok' => !empty($opaque_opacity['ok']),
            'raw_opacity' => $raw_opacity,
            'opaque_opacity' => $opaque_opacity,
        ];

        if (empty($opaque_opacity['ok'])) {
            error_log('CMSG LAYERED QUALITY FAIL: background_opacity_normalization_failed variant=' . $variant . ' attempt=' . intval($attempt) . ' result=' . wp_json_encode($opacity_report));
            self::write_background_validation_report($opaque_path, array_merge($opacity_report, [
                'ok' => false,
                'reason' => 'background_opacity_normalization_failed',
            ]));
            continue;
        }

        if (!@copy($validation_source, $out_path)) {
            error_log('CMSG LAYERED QUALITY FAIL: background_opacity_normalization_failed copy_failed variant=' . $variant . ' attempt=' . intval($attempt) . ' src=' . $validation_source . ' dest=' . $out_path);
            continue;
        }
        @chmod($out_path, 0664);

        $validation = self::validate_background_has_no_people($out_path, $brief, $opacity_report);
        if (!empty($validation['ok'])) {
            return $out_path;
        }

        error_log('CMSG LAYERED BACKGROUND HUMAN CONTENT DETECTED variant=' . $variant . ' attempt=' . intval($attempt) . ' result=' . wp_json_encode($validation));
        error_log('CMSG LAYERED BACKGROUND REJECTED: human_detected variant=' . $variant . ' attempt=' . intval($attempt) . ' result=' . wp_json_encode($validation));
        @unlink($out_path);
    }

    return '';
}

private static function background_raw_path($background_path) {
    if (!is_string($background_path) || $background_path === '') return '';
    return preg_replace('/\.png$/i', '-background-raw.png', $background_path);
}

private static function background_opaque_path($background_path) {
    if (!is_string($background_path) || $background_path === '') return '';
    return preg_replace('/\.png$/i', '-background-opaque.png', $background_path);
}

private static function background_validation_input_path($background_path) {
    if (!is_string($background_path) || $background_path === '') return '';
    return preg_replace('/\.png$/i', '-background-validation-input.png', $background_path);
}

private static function background_validation_report_path($background_path) {
    if (!is_string($background_path) || $background_path === '') return '';
    return preg_replace('/\.png$/i', '-background-validation.json', $background_path);
}

private static function write_background_validation_report($background_path, $report) {
    if (!is_array($report)) $report = [];
    $report = array_merge([
        'ok' => false,
        'faces_detected' => 0,
        'people_detected' => 0,
        'silhouettes_detected' => 0,
        'reason' => '',
        'decision' => 'REJECT',
        'highest_person_score' => null,
        'highest_score' => null,
        'highest_person_bbox' => null,
        'all_scores' => [],
        'threshold_used' => self::BACKGROUND_PERSON_CONFIDENCE_THRESHOLD,
        'threshold' => self::BACKGROUND_PERSON_CONFIDENCE_THRESHOLD,
        'retry_required' => true,
        'false_positive' => false,
        'created_at' => gmdate('c'),
    ], $report);

    $input_path = self::background_validation_input_path($background_path);
    if ($input_path && is_string($background_path) && file_exists($background_path)) {
        @copy($background_path, $input_path);
        @chmod($input_path, 0664);
        $report['validation_input'] = $input_path;
    }

    $report_path = self::background_validation_report_path($background_path);
    if ($report_path) {
        file_put_contents($report_path, wp_json_encode($report, JSON_PRETTY_PRINT));
        @chmod($report_path, 0664);
        $report['report_path'] = $report_path;
    }

    return $report;
}

private static function validate_background_plate_opacity($path) {
    $report = [
        'ok' => false,
        'path' => $path,
        'width' => 0,
        'height' => 0,
        'transparent_pixels' => 0,
        'partial_alpha_pixels' => 0,
        'opaque_pixels' => 0,
        'transparent_ratio' => 0.0,
        'partial_alpha_ratio' => 0.0,
        'reason' => '',
    ];

    if (!function_exists('imagecreatefrompng') || !is_string($path) || !file_exists($path)) {
        $report['reason'] = 'missing_or_unreadable_background';
        return $report;
    }

    $img = @imagecreatefrompng($path);
    if (!$img) {
        $report['reason'] = 'not_readable_png';
        return $report;
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $report['width'] = $w;
    $report['height'] = $h;

    if ($w <= 0 || $h <= 0) {
        imagedestroy($img);
        $report['reason'] = 'empty_dimensions';
        return $report;
    }

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgba = imagecolorat($img, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;
            if ($alpha >= 126) {
                $report['transparent_pixels']++;
            } elseif ($alpha > 0) {
                $report['partial_alpha_pixels']++;
            } else {
                $report['opaque_pixels']++;
            }
        }
    }

    imagedestroy($img);

    $total = max(1, $w * $h);
    $report['transparent_ratio'] = $report['transparent_pixels'] / $total;
    $report['partial_alpha_ratio'] = $report['partial_alpha_pixels'] / $total;
    $report['ok'] = $report['transparent_ratio'] <= 0.001 && $report['partial_alpha_ratio'] <= 0.01;
    $report['reason'] = $report['ok'] ? 'opaque_background_plate' : 'background_transparency_detected';

    if (!$report['ok']) {
        error_log('CMSG LAYERED BACKGROUND TRANSPARENCY DETECTED path=' . $path . ' transparent_ratio=' . $report['transparent_ratio'] . ' partial_alpha_ratio=' . $report['partial_alpha_ratio']);
    }

    return $report;
}

private static function background_plate_opaque_fill_color($img, $w, $h) {
    $samples = [];
    $step = max(1, (int)floor(max($w, $h) / 90));

    for ($x = 0; $x < $w; $x += $step) {
        $samples[] = imagecolorat($img, $x, 0);
        $samples[] = imagecolorat($img, $x, $h - 1);
    }
    for ($y = 0; $y < $h; $y += $step) {
        $samples[] = imagecolorat($img, 0, $y);
        $samples[] = imagecolorat($img, $w - 1, $y);
    }

    $r_total = 0;
    $g_total = 0;
    $b_total = 0;
    $count = 0;
    foreach ($samples as $rgba) {
        $alpha = ($rgba >> 24) & 0x7F;
        if ($alpha > 110) {
            continue;
        }
        $r_total += ($rgba >> 16) & 0xFF;
        $g_total += ($rgba >> 8) & 0xFF;
        $b_total += $rgba & 0xFF;
        $count++;
    }

    if ($count <= 0) {
        return [12, 10, 8];
    }

    $r = (int)round(($r_total / $count) * 0.45 + 12 * 0.55);
    $g = (int)round(($g_total / $count) * 0.45 + 10 * 0.55);
    $b = (int)round(($b_total / $count) * 0.45 + 8 * 0.55);

    return [
        max(8, min(96, $r)),
        max(8, min(96, $g)),
        max(8, min(96, $b)),
    ];
}

private static function flatten_background_plate_opaque($src, $dest, $brief = []) {
    if (!function_exists('imagecreatefrompng') || !function_exists('imagecreatetruecolor')) {
        return false;
    }
    if (!is_string($src) || !file_exists($src) || !is_string($dest) || $dest === '') {
        return false;
    }

    $img = @imagecreatefrompng($src);
    if (!$img) {
        return false;
    }

    $w = imagesx($img);
    $h = imagesy($img);
    if ($w <= 0 || $h <= 0) {
        imagedestroy($img);
        return false;
    }

    $fill_rgb = self::background_plate_opaque_fill_color($img, $w, $h);
    $canvas = imagecreatetruecolor($w, $h);
    if (!$canvas) {
        imagedestroy($img);
        return false;
    }

    imagealphablending($canvas, true);
    imagesavealpha($canvas, false);
    $fill = imagecolorallocatealpha($canvas, $fill_rgb[0], $fill_rgb[1], $fill_rgb[2], 0);
    imagefilledrectangle($canvas, 0, 0, $w, $h, $fill);
    imagecopy($canvas, $img, 0, 0, 0, 0, $w, $h);

    $dir = dirname($dest);
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }

    $saved = imagepng($canvas, $dest, 6);
    imagedestroy($canvas);
    imagedestroy($img);

    if (!$saved || !file_exists($dest) || filesize($dest) <= 0) {
        return false;
    }
    @chmod($dest, 0664);

    $opacity = self::validate_background_plate_opacity($dest);
    return !empty($opacity['ok']);
}

private static function validate_background_has_no_people($background_path, $brief, $opacity_report = []) {
    $opacity_report = is_array($opacity_report) ? $opacity_report : [];
    $with_opacity = function($report) use ($opacity_report) {
        return array_merge($opacity_report, is_array($report) ? $report : []);
    };

    if (!is_string($background_path) || !file_exists($background_path)) {
        return self::write_background_validation_report($background_path, $with_opacity([
            'ok' => false,
            'reason' => 'missing_background',
        ]));
    }

    $script = plugin_dir_path(dirname(__FILE__)) . 'tools/detect-duplicate-faces.py';
    if (!file_exists($script)) {
        error_log('CMSG LAYERED BACKGROUND VALIDATION FAILED CLOSED: face_detector_missing path=' . $script);
        return self::write_background_validation_report($background_path, $with_opacity([
            'ok' => false,
            'reason' => 'face_detector_missing',
            'detector_path' => $script,
        ]));
    }

    $python = file_exists('/opt/cmsg-bgremove/bin/python') ? '/opt/cmsg-bgremove/bin/python' : 'python3';
    $cmd = escapeshellcmd($python)
        . ' ' . escapeshellarg($script)
        . ' ' . escapeshellarg($background_path)
        . ' 0'
        . ' ' . escapeshellarg((string)self::$duplicate_face_similarity_threshold)
        . ' 2>&1';
    $raw = shell_exec($cmd);
    $decoded = self::decode_detector_json_output($raw);

    if (!is_array($decoded) || empty($decoded['ok'])) {
        error_log('CMSG LAYERED BACKGROUND VALIDATION FAILED CLOSED: face_detector_unavailable raw=' . (is_string($raw) ? substr($raw, 0, 300) : ''));
        return self::write_background_validation_report($background_path, $with_opacity([
            'ok' => false,
            'reason' => 'face_detector_unavailable',
            'detector_path' => $script,
            'raw' => is_string($raw) ? substr($raw, 0, 1000) : '',
            'decoded' => is_array($decoded) ? $decoded : null,
        ]));
    }

    $face_count = (int)($decoded['face_count'] ?? ($decoded['detected_face_count'] ?? 0));
    $face_decision = self::background_face_detection_decision($decoded);
    if (empty($face_decision['ok'])) {
        error_log('CMSG LAYERED BACKGROUND HUMAN CONTENT DETECTED faces=' . $face_count . ' path=' . $background_path);
        return self::write_background_validation_report($background_path, $with_opacity(array_merge($face_decision, [
            'faces_detected' => $face_count,
            'people_detected' => 0,
            'silhouettes_detected' => 0,
            'detector' => $decoded['method'] ?? 'unknown',
            'face_detector' => $decoded,
        ])));
    }

    $person_script = plugin_dir_path(dirname(__FILE__)) . 'tools/detect-people.py';
    if (!file_exists($person_script)) {
        error_log('CMSG LAYERED BACKGROUND VALIDATION FAILED CLOSED: people_detector_missing path=' . $person_script);
        return self::write_background_validation_report($background_path, $with_opacity([
            'ok' => false,
            'reason' => 'people_detector_missing',
            'faces_detected' => 0,
            'people_detected' => 0,
            'silhouettes_detected' => 0,
            'face_detector' => $decoded,
            'detector_path' => $person_script,
        ]));
    }

    $person_cmd = escapeshellcmd($python)
        . ' ' . escapeshellarg($person_script)
        . ' ' . escapeshellarg($background_path)
        . ' 2>&1';
    $person_raw = shell_exec($person_cmd);
    $person_decoded = self::decode_detector_json_output($person_raw);
    if (!is_array($person_decoded) || empty($person_decoded['ok'])) {
        error_log('CMSG LAYERED BACKGROUND VALIDATION FAILED CLOSED: people_detector_unavailable raw=' . (is_string($person_raw) ? substr($person_raw, 0, 300) : ''));
        return self::write_background_validation_report($background_path, $with_opacity([
            'ok' => false,
            'reason' => 'people_detector_unavailable',
            'faces_detected' => 0,
            'people_detected' => 0,
            'silhouettes_detected' => 0,
            'face_detector' => $decoded,
            'raw' => is_string($person_raw) ? substr($person_raw, 0, 1000) : '',
            'decoded' => is_array($person_decoded) ? $person_decoded : null,
        ]));
    }

    $person_count = (int)($person_decoded['person_count'] ?? ($person_decoded['people_detected'] ?? 0));
    $silhouette_count = (int)($person_decoded['silhouette_count'] ?? ($person_decoded['silhouettes_detected'] ?? 0));
    $people_decision = self::background_people_detection_decision($person_decoded);
    if ($person_count > 0 || $silhouette_count > 0) {
        error_log('CMSG LAYERED BACKGROUND HUMAN VALIDATION people=' . $person_count . ' silhouettes=' . $silhouette_count . ' highest_score=' . ($people_decision['highest_person_score'] ?? 'null') . ' threshold=' . ($people_decision['threshold_used'] ?? self::BACKGROUND_PERSON_CONFIDENCE_THRESHOLD) . ' decision=' . ($people_decision['decision'] ?? 'REJECT') . ' reason=' . ($people_decision['reason'] ?? '') . ' path=' . $background_path);
    }
    if (empty($people_decision['ok'])) {
        return self::write_background_validation_report($background_path, $with_opacity(array_merge($people_decision, [
            'ok' => false,
            'faces_detected' => 0,
            'face_detector' => $decoded,
            'people_detector' => $person_decoded,
        ])));
    }

    return self::write_background_validation_report($background_path, $with_opacity(array_merge($people_decision, [
        'ok' => true,
        'faces_detected' => 0,
        'face_detector' => $decoded,
        'people_detector' => $person_decoded,
        'detector' => $decoded['method'] ?? 'unknown',
    ])));
}

private static function background_people_detection_decision($person_decoded) {
    $person_decoded = is_array($person_decoded) ? $person_decoded : [];
    if (empty($person_decoded['ok'])) {
        return [
            'ok' => false,
            'decision' => 'REJECT',
            'reason' => 'people_detector_unavailable',
            'people_detected' => 0,
            'silhouettes_detected' => 0,
            'person_count' => 0,
            'silhouette_count' => 0,
            'highest_person_score' => null,
            'highest_score' => null,
            'highest_person_bbox' => null,
            'all_scores' => [],
            'threshold_used' => self::BACKGROUND_PERSON_CONFIDENCE_THRESHOLD,
            'threshold' => self::BACKGROUND_PERSON_CONFIDENCE_THRESHOLD,
            'retry_required' => true,
            'false_positive' => false,
        ];
    }
    $threshold = (float)self::BACKGROUND_PERSON_CONFIDENCE_THRESHOLD;
    $people = $person_decoded['people'] ?? [];
    $people = is_array($people) ? $people : [];
    $person_count = (int)($person_decoded['person_count'] ?? ($person_decoded['people_detected'] ?? count($people)));
    $silhouette_count = (int)($person_decoded['silhouette_count'] ?? ($person_decoded['silhouettes_detected'] ?? 0));
    if ($person_count > 0 && empty($people)) {
        return [
            'ok' => false,
            'decision' => 'REJECT',
            'reason' => 'people_detector_invalid',
            'people_detected' => $person_count,
            'silhouettes_detected' => $silhouette_count,
            'person_count' => $person_count,
            'silhouette_count' => $silhouette_count,
            'highest_person_score' => null,
            'highest_score' => null,
            'highest_person_bbox' => null,
            'all_scores' => [],
            'threshold_used' => $threshold,
            'threshold' => $threshold,
            'retry_required' => true,
            'false_positive' => false,
        ];
    }
    $scores = [];
    $highest_score = null;
    $highest_bbox = null;
    $strong_person_detected = false;

    foreach ($people as $person) {
        if (!is_array($person)) continue;
        $score = isset($person['score']) && is_numeric($person['score']) ? (float)$person['score'] : 0.0;
        $scores[] = round($score, 4);
        if ($highest_score === null || $score > $highest_score) {
            $highest_score = $score;
            $highest_bbox = isset($person['bbox']) && is_array($person['bbox']) ? array_values($person['bbox']) : null;
        }
        if ($score >= $threshold) {
            $strong_person_detected = true;
        }
    }

    $reject = $silhouette_count > 0 || $strong_person_detected;
    $false_positive = $person_count > 0 && !$strong_person_detected && $silhouette_count <= 0;
    $reason = 'no_human_content_detected';
    if ($silhouette_count > 0) {
        $reason = 'silhouette_detected';
    } elseif ($strong_person_detected) {
        $reason = 'strong_person_detected';
    } elseif ($false_positive) {
        $reason = 'low_confidence_person_detection';
    }

    return [
        'ok' => !$reject,
        'decision' => $reject ? 'REJECT' : 'ALLOW',
        'reason' => $reason,
        'people_detected' => $person_count,
        'silhouettes_detected' => $silhouette_count,
        'person_count' => $person_count,
        'silhouette_count' => $silhouette_count,
        'highest_person_score' => $highest_score === null ? null : round($highest_score, 4),
        'highest_score' => $highest_score === null ? null : round($highest_score, 4),
        'highest_person_bbox' => $highest_bbox,
        'all_scores' => $scores,
        'threshold_used' => $threshold,
        'threshold' => $threshold,
        'retry_required' => $reject,
        'false_positive' => $false_positive,
    ];
}

private static function background_face_detection_decision($face_decoded) {
    $face_decoded = is_array($face_decoded) ? $face_decoded : [];
    if (empty($face_decoded['ok'])) {
        return [
            'ok' => false,
            'decision' => 'REJECT',
            'reason' => 'face_detector_unavailable',
            'faces_detected' => 0,
            'retry_required' => true,
            'false_positive' => false,
        ];
    }

    $face_count = (int)($face_decoded['face_count'] ?? ($face_decoded['detected_face_count'] ?? 0));
    if ($face_count > 0) {
        return [
            'ok' => false,
            'decision' => 'REJECT',
            'reason' => 'face_detected',
            'faces_detected' => $face_count,
            'retry_required' => true,
            'false_positive' => false,
        ];
    }

    return [
        'ok' => true,
        'decision' => 'ALLOW',
        'reason' => 'no_face_detected',
        'faces_detected' => 0,
        'retry_required' => false,
        'false_positive' => false,
    ];
}

private static function poster_diagnostic_log_path($filename) {
    $filename = basename((string)$filename);
    if ($filename === '') {
        $filename = 'poster-diagnostics.log';
    }
    if (defined('WP_CONTENT_DIR') && WP_CONTENT_DIR) {
        return rtrim(WP_CONTENT_DIR, '/\\') . '/' . $filename;
    }
    if (defined('ABSPATH') && ABSPATH) {
        return rtrim(ABSPATH, '/\\') . '/wp-content/' . $filename;
    }
    return $filename;
}

private static function append_poster_diagnostic_log($filename, $entry) {
    $path = self::poster_diagnostic_log_path($filename);
    $entry = is_array($entry) ? $entry : ['message' => (string)$entry];
    if (empty($entry['timestamp'])) {
        $entry['timestamp'] = gmdate('c');
    }
    $line = wp_json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
    $ok = @file_put_contents($path, $line, FILE_APPEND);
    if ($ok !== false) {
        @chmod($path, 0664);
    }
    return $ok !== false;
}

private static function wp_error_diagnostic_payload($error) {
    if (!is_wp_error($error)) {
        return [
            'error_code' => '',
            'error_message' => '',
            'error_data' => null,
        ];
    }
    $code = $error->get_error_code();
    return [
        'error_code' => (string)$code,
        'error_message' => (string)$error->get_error_message($code),
        'error_data' => $error->get_error_data($code),
    ];
}

private static function diagnostic_json_file_state($path) {
    $path = is_string($path) ? $path : '';
    $state = [
        'path' => $path,
        'expected_path' => $path,
        'exists' => false,
        'filesize' => null,
        'json_decode_ok' => false,
        'json_error' => null,
        'decoded' => null,
    ];
    if ($path === '') {
        $state['json_error'] = 'missing_path';
        return $state;
    }
    $state['exists'] = file_exists($path);
    if (!$state['exists']) {
        $state['json_error'] = 'file_missing';
        return $state;
    }
    $state['filesize'] = @filesize($path);
    $raw = @file_get_contents($path);
    if ($raw === false) {
        $state['json_error'] = 'read_failed';
        return $state;
    }
    $decoded = json_decode($raw, true);
    $state['json_decode_ok'] = is_array($decoded);
    $state['json_error'] = json_last_error_msg();
    $state['decoded'] = is_array($decoded) ? $decoded : null;
    return $state;
}

private static function identity_actor_context($context = []) {
    $defaults = [
        'function' => 'prepare_identity_actor_layers',
        'actor_index' => null,
        'actor_id' => '',
        'actor_label' => '',
        'actor_source_path' => '',
        'source_image' => '',
        'cutout_path' => '',
        'raw_cutout_path' => '',
        'final_cutout_path' => '',
        'destination_layer_path' => '',
        'identity_anchor_path' => '',
        'source_selection_path' => '',
        'repair_report_path' => '',
        'alpha_report_path' => '',
        'face_anchor_path' => '',
    ];
    return array_merge($defaults, is_array($context) ? $context : []);
}

private static function set_identity_actor_diagnostic_context($context = []) {
    self::$last_identity_actor_diagnostic_context = self::identity_actor_context($context);
    return self::$last_identity_actor_diagnostic_context;
}

private static function append_identity_actor_error_diagnostic($error, $context = [], $extra = []) {
    $context = self::identity_actor_context(array_merge(self::$last_identity_actor_diagnostic_context, is_array($context) ? $context : []));
    $payload = array_merge(
        [
            'event' => 'identity_actor_wp_error',
            'function' => $context['function'],
            'actor_index' => $context['actor_index'],
            'actor_id' => $context['actor_id'],
            'actor_label' => $context['actor_label'],
            'source_image' => $context['source_image'] ?: $context['actor_source_path'],
            'actor_source_path' => $context['actor_source_path'],
            'cutout_path' => $context['cutout_path'],
            'raw_cutout_path' => $context['raw_cutout_path'],
            'final_cutout_path' => $context['final_cutout_path'],
            'destination_layer_path' => $context['destination_layer_path'],
            'identity_anchor_path' => $context['identity_anchor_path'],
            'identity_anchor_json' => self::diagnostic_json_file_state($context['identity_anchor_path']),
            'source_selection_path' => $context['source_selection_path'],
            'source_selection_json' => self::diagnostic_json_file_state($context['source_selection_path']),
            'repair_report_path' => $context['repair_report_path'],
            'repair_report_json' => self::diagnostic_json_file_state($context['repair_report_path']),
            'alpha_report_path' => $context['alpha_report_path'],
            'alpha_report_json' => self::diagnostic_json_file_state($context['alpha_report_path']),
            'face_anchor_path' => $context['face_anchor_path'],
            'face_anchor_json' => self::diagnostic_json_file_state($context['face_anchor_path']),
        ],
        self::wp_error_diagnostic_payload($error),
        is_array($extra) ? $extra : []
    );
    return self::append_poster_diagnostic_log('poster-identity-debug.log', $payload);
}

private static function poster_actor_trace_path() {
    $base = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : (defined('ABSPATH') ? rtrim(ABSPATH, '/\\') . '/wp-content' : __DIR__);
    return rtrim($base, '/\\') . '/poster-actor-trace.log';
}

private static function poster_actor_trace_sanitize($value, $depth = 0) {
    if ($depth > 5) {
        return '[max-depth]';
    }
    if (function_exists('is_wp_error') && is_wp_error($value)) {
        return self::poster_actor_trace_wp_error($value);
    }
    if (is_array($value)) {
        $out = [];
        $count = 0;
        foreach ($value as $key => $item) {
            $count++;
            if ($count > 80) {
                $out['__truncated_items'] = count($value) - 80;
                break;
            }
            $safe_key = is_string($key) ? $key : (string)$key;
            if (preg_match('/authorization|api[_-]?key|token|secret|password|base64|b64_json/i', $safe_key)) {
                $out[$safe_key] = '[redacted]';
                continue;
            }
            $out[$safe_key] = self::poster_actor_trace_sanitize($item, $depth + 1);
        }
        return $out;
    }
    if (is_object($value)) {
        return '[object ' . get_class($value) . ']';
    }
    if (is_resource($value)) {
        return '[resource]';
    }
    if (is_string($value)) {
        if (preg_match('/authorization|api[_-]?key|token|secret|password|base64|b64_json/i', $value)) {
            return '[redacted-string]';
        }
        $length = strlen($value);
        if ($length > 900) {
            return substr($value, 0, 420) . '...[truncated ' . $length . ' bytes]...' . substr($value, -180);
        }
        return $value;
    }
    return $value;
}

private static function poster_actor_trace_file_state($path) {
    $path = is_string($path) ? $path : '';
    $state = [
        'path' => $path,
        'exists' => false,
        'readable' => false,
        'filesize' => null,
        'dimensions' => null,
    ];
    if ($path === '') {
        return $state;
    }
    $state['exists'] = file_exists($path);
    $state['readable'] = is_readable($path);
    if ($state['exists']) {
        $size = @filesize($path);
        $state['filesize'] = $size === false ? null : $size;
        $dims = @getimagesize($path);
        if (is_array($dims)) {
            $state['dimensions'] = [
                'width' => (int)($dims[0] ?? 0),
                'height' => (int)($dims[1] ?? 0),
                'mime' => (string)($dims['mime'] ?? ''),
            ];
        }
    }
    return $state;
}

private static function poster_actor_trace_wp_error($error) {
    if (!function_exists('is_wp_error') || !is_wp_error($error)) {
        return null;
    }
    $data = [];
    if (method_exists($error, 'get_error_codes')) {
        foreach ((array)$error->get_error_codes() as $code) {
            $data[$code] = method_exists($error, 'get_all_error_data') ? $error->get_all_error_data($code) : $error->get_error_data($code);
        }
    }
    return [
        'codes' => method_exists($error, 'get_error_codes') ? $error->get_error_codes() : [$error->get_error_code()],
        'messages' => method_exists($error, 'get_error_messages') ? $error->get_error_messages() : [$error->get_error_message()],
        'data' => $data,
    ];
}

private static function poster_actor_trace($code, $message = '', $context = []) {
    try {
        $entry = gmdate('c')
            . ' pid=' . (function_exists('getmypid') ? (int)getmypid() : 0)
            . ' memory=' . (function_exists('memory_get_usage') ? (int)memory_get_usage(true) : 0)
            . ' peak=' . (function_exists('memory_get_peak_usage') ? (int)memory_get_peak_usage(true) : 0)
            . ' ' . preg_replace('/[^A-Z0-9_:-]/', '_', strtoupper((string)$code))
            . ' ' . str_replace(["\r", "\n"], ' ', (string)$message)
            . ' ' . json_encode(self::poster_actor_trace_sanitize(is_array($context) ? $context : ['context' => $context]), JSON_UNESCAPED_SLASHES)
            . "\n";
        @file_put_contents(self::poster_actor_trace_path(), $entry, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        return;
    }
}

private static function register_poster_actor_trace_shutdown($context = []) {
    if (self::$poster_actor_trace_shutdown_registered) {
        return;
    }
    self::$poster_actor_trace_shutdown_registered = true;
    self::poster_actor_trace('SHUTDOWN_REGISTERED', 'Actor trace shutdown handler registered.', $context);
    register_shutdown_function(function() use ($context) {
        try {
            CMSG_Poster_AI::poster_actor_trace('SHUTDOWN_ENTER', 'Poster actor trace shutdown handler entered.', $context);
            $last = error_get_last();
            if (is_array($last)) {
                CMSG_Poster_AI::poster_actor_trace('SHUTDOWN_LAST_ERROR', 'Last PHP error captured at shutdown.', [
                    'type' => $last['type'] ?? null,
                    'message' => $last['message'] ?? '',
                    'file' => $last['file'] ?? '',
                    'line' => $last['line'] ?? null,
                ]);
            } else {
                CMSG_Poster_AI::poster_actor_trace('SHUTDOWN_CLEAN', 'No last PHP error at shutdown.', $context);
            }
        } catch (Throwable $e) {
            return;
        }
    });
}

private static function prepare_campaign_actor_cutouts($brief, $layer_dir, $actor_records = null) {
    $path_started = microtime(true);
    self::poster_actor_trace('ACTOR_PATH_ENTER', 'Entered prepare_campaign_actor_cutouts().', [
        'layer_dir' => $layer_dir,
        'actor_records_supplied' => is_array($actor_records),
        'supplied_actor_record_count' => is_array($actor_records) ? count($actor_records) : null,
    ]);
    $records = is_array($actor_records) ? $actor_records : self::accepted_actor_source_records($brief);

    self::poster_actor_trace('ACTOR_COLLECTION_BEGIN', 'Actor records resolved for cutout preparation.', [
        'record_count' => count($records),
        'layer_dir' => $layer_dir,
    ]);
    foreach ($records as $record) {
        self::poster_actor_trace('ACTOR_COLLECTION_RECORD', 'Validating actor registry record.', [
            'actor_id' => sanitize_key($record['actor_id'] ?? ''),
            'actor_index' => (int)($record['actor_index'] ?? -1),
            'source_type' => (string)($record['source_type'] ?? ''),
            'accepted_as_actor' => !empty($record['accepted_as_actor']),
            'source_path' => self::poster_actor_trace_file_state((string)($record['source_path'] ?? '')),
        ]);
        if (empty($record['accepted_as_actor']) || !in_array($record['source_type'] ?? '', ['principal_cast', 'legacy_cast'], true)) {
            self::poster_actor_trace('ACTOR_WP_ERROR', 'Non-cast source reached actor registry validation.', [
                'record' => $record,
            ]);
            error_log('CMSG LAYERED QUALITY FAIL: non_cast_asset_in_actor_registry source_type=' . sanitize_text_field($record['source_type'] ?? '') . ' path=' . sanitize_text_field($record['source_path'] ?? ''));
            return [];
        }
    }

    self::poster_actor_trace('IDENTITY_LAYERS_CALL_BEFORE', 'Calling prepare_identity_actor_layers().', [
        'record_count' => count($records),
        'layer_dir' => $layer_dir,
    ]);
    $identity_started = microtime(true);
    $layers = self::prepare_identity_actor_layers($records, $layer_dir, is_array($brief) ? $brief : []);
    self::poster_actor_trace('IDENTITY_LAYERS_CALL_AFTER', 'Returned from prepare_identity_actor_layers().', [
        'elapsed_ms' => (int)round((microtime(true) - $identity_started) * 1000),
        'result_type' => is_wp_error($layers) ? 'WP_Error' : gettype($layers),
        'count' => is_array($layers) ? count($layers) : null,
        'wp_error' => self::poster_actor_trace_wp_error($layers),
    ]);
    if (is_wp_error($layers)) {
        self::poster_actor_trace('ACTOR_WP_ERROR', 'prepare_identity_actor_layers() returned WP_Error.', [
            'wp_error' => self::poster_actor_trace_wp_error($layers),
            'last_identity_actor_diagnostic_context' => self::$last_identity_actor_diagnostic_context,
        ]);
        $context = self::identity_actor_context(self::$last_identity_actor_diagnostic_context);
        self::append_poster_diagnostic_log('poster-route-test.log', array_merge([
            'event' => 'prepare_identity_actor_layers_wp_error',
            'function' => 'prepare_campaign_actor_cutouts',
            'actor_index' => $context['actor_index'],
            'actor_label' => $context['actor_label'],
            'actor_source_path' => $context['actor_source_path'],
            'identity_anchor_path' => $context['identity_anchor_path'],
            'identity_anchor_json' => self::diagnostic_json_file_state($context['identity_anchor_path']),
        ], self::wp_error_diagnostic_payload($layers)));
        error_log('CMSG PROFESSIONAL RECOVERY QUALITY FAIL code=' . $layers->get_error_code() . ' message=' . $layers->get_error_message());
        return $layers;
    }
    foreach ($layers as $layer) {
        error_log('CMSG LAYERED ACTOR CUTOUT: actor=' . sanitize_text_field($layer['label'] ?? '') . ' source_type=' . sanitize_text_field($layer['source_type'] ?? '') . ' source=' . sanitize_text_field($layer['source'] ?? '') . ' layer=' . sanitize_text_field($layer['layer'] ?? '') . ' fallback=' . (!empty($layer['fallback_used']) ? 'yes' : 'no'));
    }
    self::poster_actor_trace('ACTOR_PATH_EXIT', 'prepare_campaign_actor_cutouts() completed.', [
        'elapsed_ms' => (int)round((microtime(true) - $path_started) * 1000),
        'layer_count' => is_array($layers) ? count($layers) : null,
    ]);
    return $layers;
}

private static function build_campaign_layer_manifest($brief, $background_path, $actor_layers, $variant, $prop_layers = []) {
    $variant = sanitize_key($variant ?: 'vertical');
    $vertical_slots = self::campaign_variant_slot_map($brief, count($actor_layers), 'vertical');
    $banner_slots = self::campaign_variant_slot_map($brief, count($actor_layers), 'banner');
    $slots = $variant === 'banner' ? $banner_slots : $vertical_slots;
    $safe_areas = [
        'vertical' => [
            'title' => ['x' => 0.08, 'y' => 0.70, 'w' => 0.84, 'h' => 0.22],
            'actors' => ['x' => 0.04, 'y' => 0.04, 'w' => 0.92, 'h' => 0.72],
        ],
        'banner' => [
            'title' => ['x' => 0.28, 'y' => 0.62, 'w' => 0.44, 'h' => 0.24],
            'actors' => ['x' => 0.04, 'y' => 0.08, 'w' => 0.92, 'h' => 0.66],
        ],
    ];
    $manifest = [
        'campaign_id' => '',
        'title' => sanitize_text_field($brief['title'] ?? ($brief['movie_title'] ?? 'Untitled Film')),
        'created_at' => gmdate('c'),
        'source_mode' => 'layered_campaign',
        'brief' => [
            'title' => sanitize_text_field($brief['title'] ?? ($brief['movie_title'] ?? '')),
            'genre' => sanitize_text_field($brief['genre'] ?? ''),
            'mood' => sanitize_text_field($brief['mood'] ?? ''),
            'poster_layout' => self::poster_layout_key($brief),
            'poster_description' => sanitize_textarea_field($brief['poster_description'] ?? ($brief['description'] ?? '')),
            'cast_members' => self::normalized_cast_members($brief),
            'poster_assets' => array_values((array)($brief['poster_assets'] ?? [])),
            'professional_recovery_template' => self::professional_recovery_template_key($brief, count($actor_layers)),
        ],
        'immutable' => true,
        'locks' => [
            'actor_positions' => true,
            'actor_scale' => true,
            'actor_depth' => true,
            'layer_order' => true,
            'camera_angle' => true,
            'prop_placement' => true,
            'lighting_direction' => true,
            'atmosphere' => true,
            'title_safe_areas' => true,
            'background_plate' => true,
            'actor_identity' => true,
        ],
        'layout' => self::poster_layout_key($brief),
        'master' => [
            'path' => '',
            'width' => 900,
            'height' => 1285,
        ],
        'layers' => [
            [
                'type' => 'background',
                'path' => $background_path,
                'locked' => true,
                'locked_identity' => true,
                'no_people_validated' => true,
            ],
        ],
        'safe_areas' => $safe_areas,
        'variant_layouts' => [
            'vertical' => [
                'width' => 900,
                'height' => 1285,
                'actor_slots' => $vertical_slots,
                'safe_areas' => $safe_areas['vertical'],
            ],
            'banner' => [
                'width' => 895,
                'height' => 504,
                'actor_slots' => $banner_slots,
                'safe_areas' => $safe_areas['banner'],
            ],
        ],
        'variants' => [
            'vertical' => ['width' => 900, 'height' => 1285],
            'banner' => ['width' => 895, 'height' => 504],
        ],
    ];

    $seen_indexes = [];
    $cast_members = self::normalized_cast_members($brief);
    foreach (array_values($actor_layers) as $i => $layer) {
        $source_type = self::actor_registry_source_type($layer['source_type'] ?? 'legacy_cast');
        if (empty($layer['accepted_as_actor']) || !in_array($source_type, ['principal_cast', 'legacy_cast'], true)) {
            error_log('CMSG LAYERED QUALITY FAIL: non_cast_asset_in_actor_registry source_type=' . sanitize_text_field($source_type) . ' path=' . sanitize_text_field($layer['source'] ?? ''));
            return [];
        }

        $actor_index = (int)($layer['index'] ?? $i);
        if (isset($seen_indexes[$actor_index])) {
            error_log('CMSG LAYERED DUPLICATE ACTOR SKIPPED: actor_index=' . $actor_index . ' layer=' . sanitize_text_field($layer['layer'] ?? ''));
            continue;
        }
        $seen_indexes[$actor_index] = true;
        $actor_id = self::campaign_actor_id($actor_index);
        $slot = $slots[$actor_id] ?? ['x' => 0.50, 'y' => 0.15, 'w' => 0.36, 'h' => 0.46, 'z_index' => $i + 10];
        $cast_member = is_array($cast_members[$actor_index] ?? null) ? $cast_members[$actor_index] : [];
        $actor_role = self::normalize_campaign_actor_role($cast_member['role'] ?? ($slot['role'] ?? 'supporting'), $actor_index);
        $actor_instruction = sanitize_textarea_field($cast_member['instruction'] ?? ($cast_member['character_notes'] ?? ''));
        $manifest['layers'][] = [
            'type' => 'actor',
            'actor_id' => $actor_id,
            'actor_index' => $actor_index,
            'actor_label' => self::actor_layer_label($actor_index),
            'source_path' => $layer['source'] ?? '',
            'source_type' => $source_type,
            'accepted_as_actor' => true,
            'principal_cast_only' => true,
            'source_hash' => self::campaign_file_hash($layer['source'] ?? ''),
            'cutout_path' => $layer['cutout'] ?? '',
            'layer_path' => $layer['layer'] ?? '',
            'final_cutout_path' => $layer['final_cutout'] ?? ($layer['cutout'] ?? ''),
            'face_anchor_path' => $layer['face_anchor'] ?? '',
            'matte_report_path' => $layer['matte_report'] ?? '',
            'identity_anchor_path' => $layer['identity_anchor_path'] ?? '',
            'source_selection_path' => $layer['source_selection_path'] ?? '',
            'repair_report_path' => $layer['repair_report_path'] ?? '',
            'source_selection' => $layer['source_selection'] ?? [],
            'repair_report' => $layer['repair_report'] ?? [],
            'fallback_used' => !empty($layer['fallback_used']),
            'x' => (float)($slot['x'] ?? 0.50),
            'y' => (float)($slot['y'] ?? 0.15),
            'w' => (float)($slot['w'] ?? 0.36),
            'h' => (float)($slot['h'] ?? 0.46),
            'z' => (int)($slot['z_index'] ?? ($i + 10)),
            'role' => $actor_role,
            'actor_instruction' => $actor_instruction,
            'instruction_directives' => self::parse_actor_instruction_directives($actor_instruction),
            'locked_identity' => true,
            'placement_locked' => true,
            'scale_locked' => true,
            'depth_locked' => true,
        ];
    }

    foreach (array_values((array)$prop_layers) as $prop_index => $prop_layer) {
        if (!is_array($prop_layer) || empty($prop_layer['layer_path'])) continue;
        $manifest['layers'][] = [
            'type' => 'prop',
            'prop_id' => sanitize_key($prop_layer['prop_id'] ?? ('prop_' . ($prop_index + 1))),
            'prop_type' => sanitize_key($prop_layer['prop_type'] ?? 'visual_reference'),
            'source_path' => (string)($prop_layer['source_path'] ?? ''),
            'layer_path' => (string)($prop_layer['layer_path'] ?? ''),
            'mask_path' => (string)($prop_layer['mask_path'] ?? ''),
            'integrity_report_path' => (string)($prop_layer['integrity_report_path'] ?? ''),
            'required' => !empty($prop_layer['required']),
            'bounds' => (array)($prop_layer['bounds'] ?? ['x' => 0.18, 'y' => 0.73, 'w' => 0.64, 'h' => 0.20]),
            'z' => (int)($prop_layer['z'] ?? 65),
            'locked_identity' => true,
            'placement_locked' => true,
        ];
    }

    $manifest['layers'][] = [
        'type' => 'title',
        'text' => sanitize_text_field($brief['title'] ?? ''),
        'variant' => $variant,
        'locked_safe_area' => true,
    ];

    return $manifest;
}

private static function professional_recovery_template_key($brief, $actor_count = 0) {
    $requested = sanitize_key($brief['professional_recovery_template'] ?? ($brief['poster_recovery_template'] ?? ''));
    $allowed = ['prestige_ensemble_pyramid', 'cinematic_four_character_arc', 'character_over_environment'];
    return in_array($requested, $allowed, true) ? $requested : self::PROFESSIONAL_RECOVERY_DEFAULT_TEMPLATE;
}

private static function extract_background_element_evidence($brief) {
    $fields = [
        'poster_description' => $brief['poster_description'] ?? ($brief['description'] ?? ''),
        'scene_direction' => $brief['scene_direction'] ?? '',
        'setting' => $brief['setting'] ?? '',
        'synopsis' => $brief['synopsis'] ?? '',
        'poster_asset_references' => wp_json_encode($brief['poster_asset_references'] ?? []),
        'poster_assets' => wp_json_encode($brief['poster_assets'] ?? []),
    ];
    $joined = strtolower(implode("\n", array_map('wp_strip_all_tags', array_map('strval', $fields))));
    $supported = [];
    $keywords = [
        'village' => ['village', 'rural', 'compound'],
        'city skyline' => ['city', 'skyline', 'lagos', 'urban'],
        'road' => ['road', 'street', 'highway'],
        'forest' => ['forest', 'woods', 'jungle'],
        'vehicle foreground' => ['car', 'vehicle', 'automobile', 'taxi'],
        'storm sky' => ['storm', 'cloud', 'rain'],
    ];
    foreach ($keywords as $element => $needles) {
        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($joined, $needle) !== false) {
                $supported[$element] = true;
                break;
            }
        }
    }
    $forbidden = ['church', 'cathedral', 'chapel', 'cross', 'graveyard', 'monastery', 'mosque', 'temple', 'shrine', 'castle', 'palace'];
    $excluded = [];
    foreach ($forbidden as $term) {
        if (strpos($joined, $term) === false) $excluded[] = $term;
    }
    return [
        'positive_elements' => array_keys($supported),
        'excluded_elements' => $excluded,
        'evidence' => $fields,
        'scene_direction_present' => trim((string)($fields['poster_description'] ?? '')) !== '',
        'setting_present' => trim((string)($fields['setting'] ?? '')) !== '',
        'title_used_as_environment_evidence' => false,
    ];
}

private static function build_genre_neutral_background_fallback($brief) {
    $genre = strtolower(sanitize_text_field($brief['genre'] ?? ''));
    if (strpos($genre, 'fantasy') !== false) return 'atmospheric fantasy environment without unsupported architecture, painterly depth, cinematic sky, textured ground, and room for cast layers';
    if (strpos($genre, 'romance') !== false) return 'cinematic emotionally warm environment with soft depth and restrained production design';
    if (strpos($genre, 'thriller') !== false) return 'restrained dramatic environment with tension, shadow, and believable spatial depth';
    if (strpos($genre, 'comedy') !== false) return 'bright clean cinematic environment with optimistic color and open negative space';
    return 'neutral cinematic atmospheric environment with professional lighting and clean depth';
}

private static function background_prompt_audit_path($background_path) {
    return preg_replace('/\.png$/i', '-background-prompt-audit.json', (string)$background_path);
}

private static function background_prompt_audit($brief, $prompt) {
    $audit = self::extract_background_element_evidence(is_array($brief) ? $brief : []);
    $audit['final_prompt'] = (string)$prompt;
    return $audit;
}

private static function write_background_prompt_audit($path, $audit) {
    if (!is_string($path) || $path === '') return false;
    file_put_contents($path, wp_json_encode(is_array($audit) ? $audit : [], JSON_PRETTY_PRINT));
    @chmod($path, 0664);
    return file_exists($path) && filesize($path) > 0;
}

private static function prepare_actor_identity_anchor($record, $identity_anchor_path) {
    $actor_index = (int)($record['actor_index'] ?? 0);
    $started = microtime(true);
    self::poster_actor_trace('IDENTITY_ANCHOR_TOOL_BEGIN', 'Preparing actor identity anchor with analyzer.', [
        'actor_id' => sanitize_key($record['actor_id'] ?? ''),
        'actor_index' => $actor_index,
        'source' => self::poster_actor_trace_file_state((string)($record['source_path'] ?? '')),
        'identity_anchor_path' => (string)$identity_anchor_path,
        'crop_output' => preg_replace('/\.json$/i', '-crop.png', (string)$identity_anchor_path),
    ]);
    $report = self::run_poster_json_tool('analyze-poster-actor-sources.py', [
        'mode' => 'identity-anchor',
        'actor-id' => sanitize_key($record['actor_id'] ?? ''),
        'actor-index' => $actor_index,
        'source' => (string)($record['source_path'] ?? ''),
        'output' => $identity_anchor_path,
        'crop-output' => preg_replace('/\.json$/i', '-crop.png', (string)$identity_anchor_path),
        'threshold' => self::PROFESSIONAL_RECOVERY_IDENTITY_MATCH_THRESHOLD,
        'margin' => self::PROFESSIONAL_RECOVERY_IDENTITY_MATCH_MARGIN,
    ]);
    self::poster_actor_trace('IDENTITY_ANCHOR_TOOL_END', 'Actor identity anchor analyzer returned.', [
        'actor_id' => sanitize_key($record['actor_id'] ?? ''),
        'actor_index' => $actor_index,
        'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
        'identity_anchor_path' => self::poster_actor_trace_file_state($identity_anchor_path),
        'report_keys' => is_array($report) ? array_keys($report) : [],
        'identity_anchor_valid' => is_array($report) ? ($report['identity_anchor_valid'] ?? null) : null,
        'failure_reason' => is_array($report) ? ($report['failure_reason'] ?? null) : null,
    ]);
    self::write_actor_alpha_report($identity_anchor_path, $report);
    if (empty($report['identity_anchor_valid'])) {
        self::poster_actor_trace('ACTOR_WP_ERROR', 'Actor identity anchor unavailable.', [
            'actor_id' => sanitize_key($record['actor_id'] ?? ''),
            'actor_index' => $actor_index,
            'identity_anchor_path' => self::poster_actor_trace_file_state($identity_anchor_path),
            'report' => $report,
        ]);
        return new WP_Error(sanitize_key($report['failure_reason'] ?? 'actor_identity_anchor_unavailable'), 'Actor identity anchor unavailable.');
    }
    error_log('CMSG PROFESSIONAL RECOVERY ACTOR SOURCE actor_id=' . sanitize_key($record['actor_id'] ?? '') . ' identity_anchor=' . sanitize_text_field((string)$identity_anchor_path));
    return $report;
}

private static function select_best_poster_actor_source($context, $source_selection_path) {
    $started = microtime(true);
    $manifest_path = preg_replace('/\.json$/i', '-candidates.json', (string)$source_selection_path);
    $candidates = [];
    foreach (['source_path' => 'original_source', 'raw_cutout_path' => 'raw_cutout', 'final_cutout_path' => 'normalized_cutout', 'layer_path' => 'current_layer'] as $key => $type) {
        $path = (string)($context[$key] ?? '');
        if ($path !== '' && file_exists($path)) $candidates[] = ['candidate_type' => $type, 'path' => $path];
    }
    $manifest_payload = [
        'actor_id' => sanitize_key($context['actor_id'] ?? ''),
        'actor_index' => (int)($context['actor_index'] ?? 0),
        'identity_anchor_path' => (string)($context['identity_anchor_path'] ?? ''),
        'brief' => is_array($context['brief'] ?? null) ? $context['brief'] : [],
        'candidates' => $candidates,
    ];
    self::poster_actor_trace('SOURCE_SELECTION_MANIFEST_WRITE_BEGIN', 'Writing actor source-selection manifest.', [
        'actor_id' => $manifest_payload['actor_id'],
        'actor_index' => $manifest_payload['actor_index'],
        'manifest_path' => $manifest_path,
        'identity_anchor' => self::poster_actor_trace_file_state($manifest_payload['identity_anchor_path']),
        'candidate_count' => count($candidates),
        'candidates' => array_map(function($candidate) {
            return [
                'candidate_type' => $candidate['candidate_type'] ?? '',
                'path_state' => self::poster_actor_trace_file_state((string)($candidate['path'] ?? '')),
            ];
        }, $candidates),
    ]);
    $manifest_write = file_put_contents($manifest_path, wp_json_encode($manifest_payload, JSON_PRETTY_PRINT));
    @chmod($manifest_path, 0664);
    self::poster_actor_trace('SOURCE_SELECTION_MANIFEST_WRITE_END', 'Actor source-selection manifest write completed.', [
        'actor_id' => $manifest_payload['actor_id'],
        'actor_index' => $manifest_payload['actor_index'],
        'write_result' => $manifest_write,
        'manifest_path' => self::poster_actor_trace_file_state($manifest_path),
    ]);
    self::poster_actor_trace('SOURCE_SELECTION_TOOL_BEGIN', 'Calling analyzer in source-selection mode.', [
        'actor_id' => $manifest_payload['actor_id'],
        'actor_index' => $manifest_payload['actor_index'],
        'source_selection_path' => $source_selection_path,
    ]);
    $report = self::run_poster_json_tool('analyze-poster-actor-sources.py', [
        'mode' => 'select-source',
        'input-manifest' => $manifest_path,
        'output' => $source_selection_path,
        'threshold' => self::PROFESSIONAL_RECOVERY_IDENTITY_MATCH_THRESHOLD,
        'margin' => self::PROFESSIONAL_RECOVERY_IDENTITY_MATCH_MARGIN,
    ]);
    self::poster_actor_trace('SOURCE_SELECTION_TOOL_END', 'Analyzer source-selection mode returned.', [
        'actor_id' => $manifest_payload['actor_id'],
        'actor_index' => $manifest_payload['actor_index'],
        'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
        'source_selection_path' => self::poster_actor_trace_file_state($source_selection_path),
        'report_keys' => is_array($report) ? array_keys($report) : [],
        'ok' => is_array($report) ? ($report['ok'] ?? null) : null,
        'selected_source' => is_array($report) ? ($report['selected_source'] ?? null) : null,
        'failure_reason' => is_array($report) ? ($report['failure_reason'] ?? null) : null,
    ]);
    self::write_actor_alpha_report($source_selection_path, $report);
    if (empty($report['ok']) || empty($report['selected_source'])) {
        self::poster_actor_trace('ACTOR_WP_ERROR', 'No suitable actor source was available.', [
            'actor_id' => $manifest_payload['actor_id'],
            'actor_index' => $manifest_payload['actor_index'],
            'source_selection_path' => self::poster_actor_trace_file_state($source_selection_path),
            'report' => $report,
        ]);
        return new WP_Error(sanitize_key($report['failure_reason'] ?? 'actor_layer_body_source_unavailable'), 'No suitable actor source was available.');
    }
    return $report;
}

private static function analyze_actor_body_for_poster($src, $actor_index, $actor_id, $report_path) {
    self::poster_actor_trace('BODY_ANALYSIS_TOOL_BEGIN', 'Calling poster_body_analysis_service.py.', [
        'actor_index' => (int)$actor_index,
        'actor_id' => sanitize_key($actor_id),
        'source' => self::poster_actor_trace_file_state($src),
        'report_path' => $report_path,
    ]);
    $report = self::run_poster_json_tool('poster_body_analysis_service.py', [
        'source' => $src,
        'actor-index' => (int)$actor_index,
        'actor-id' => sanitize_key($actor_id),
        'output' => $report_path,
    ]);
    if (!is_array($report) || empty($report)) {
        $report = [
            'ok' => true,
            'mode' => 'body-analysis',
            'actor_index' => (int)$actor_index,
            'actor_id' => sanitize_key($actor_id),
            'poster_body_class' => 'UNKNOWN',
            'recommended_generation_mode' => 'AUTO',
            'body_geometry_advisory_only' => true,
            'body_geometry_rejection_allowed' => false,
            'warning' => 'body_analysis_unavailable',
        ];
    } else {
        $report['ok'] = true;
        $report['body_geometry_advisory_only'] = true;
        $report['body_geometry_rejection_allowed'] = false;
    }
    self::write_actor_alpha_report($report_path, $report);
    self::poster_actor_trace('BODY_ANALYSIS_TOOL_END', 'poster_body_analysis_service.py returned.', [
        'actor_index' => (int)$actor_index,
        'actor_id' => sanitize_key($actor_id),
        'report_path' => self::poster_actor_trace_file_state($report_path),
        'poster_body_class' => $report['poster_body_class'] ?? '',
        'recommended_generation_mode' => $report['recommended_generation_mode'] ?? '',
        'body_geometry_advisory_only' => $report['body_geometry_advisory_only'] ?? null,
    ]);
    return $report;
}

private static function build_actor_composition_plan_for_poster($brief, $identity_report, $body_report, $actor_index, $actor_id, $report_path) {
    $brief = is_array($brief) ? $brief : [];
    $brief_path = preg_replace('/\.json$/i', '-brief.json', (string)$report_path);
    file_put_contents($brief_path, wp_json_encode([
        'actor_id' => sanitize_key($actor_id),
        'actor_index' => (int)$actor_index,
        'title' => sanitize_text_field($brief['title'] ?? ($brief['movie_title'] ?? '')),
        'genre' => sanitize_text_field($brief['genre'] ?? ''),
        'mood' => sanitize_text_field($brief['mood'] ?? ''),
        'poster_layout' => sanitize_key($brief['poster_layout'] ?? ''),
        'style_preset' => sanitize_key($brief['style_preset'] ?? ''),
        'poster_description' => sanitize_textarea_field($brief['poster_description'] ?? ($brief['description'] ?? '')),
    ], JSON_PRETTY_PRINT));
    @chmod($brief_path, 0664);

    $identity_report_path = preg_replace('/\.json$/i', '-identity-input.json', (string)$report_path);
    $body_report_path = preg_replace('/\.json$/i', '-body-input.json', (string)$report_path);
    self::write_actor_alpha_report($identity_report_path, is_array($identity_report) ? $identity_report : []);
    self::write_actor_alpha_report($body_report_path, is_array($body_report) ? $body_report : []);

    self::poster_actor_trace('COMPOSITION_PLAN_TOOL_BEGIN', 'Calling poster_composition_planner.py.', [
        'actor_index' => (int)$actor_index,
        'actor_id' => sanitize_key($actor_id),
        'brief_path' => self::poster_actor_trace_file_state($brief_path),
        'identity_report_path' => self::poster_actor_trace_file_state($identity_report_path),
        'body_report_path' => self::poster_actor_trace_file_state($body_report_path),
        'report_path' => $report_path,
    ]);
    $report = self::run_poster_json_tool('poster_composition_planner.py', [
        'brief' => $brief_path,
        'identity-report' => $identity_report_path,
        'body-report' => $body_report_path,
        'output' => $report_path,
    ]);
    if (!is_array($report) || empty($report)) {
        $report = [
            'ok' => true,
            'schema' => 'crossmarket.poster.composition_plan.v3.3',
            'actor_id' => sanitize_key($actor_id),
            'actor_index' => (int)$actor_index,
            'poster_generation_mode' => 'AUTO',
            'identity_is_hard_gate' => true,
            'body_geometry_is_advisory' => true,
            'warning' => 'composition_planner_unavailable',
        ];
    } else {
        $report['identity_is_hard_gate'] = true;
        $report['body_geometry_is_advisory'] = true;
    }
    self::write_actor_alpha_report($report_path, $report);
    self::poster_actor_trace('COMPOSITION_PLAN_TOOL_END', 'poster_composition_planner.py returned.', [
        'actor_index' => (int)$actor_index,
        'actor_id' => sanitize_key($actor_id),
        'report_path' => self::poster_actor_trace_file_state($report_path),
        'poster_generation_mode' => $report['poster_generation_mode'] ?? '',
        'identity_is_hard_gate' => $report['identity_is_hard_gate'] ?? null,
        'body_geometry_is_advisory' => $report['body_geometry_is_advisory'] ?? null,
    ]);
    return $report;
}

private static function repair_actor_layer_for_poster($src, $dest, $actor_index, $original_source, $identity_reference, $report_path, $composition_plan_path = '') {
    $started = microtime(true);
    self::poster_actor_trace('REPAIR_TOOL_BEGIN', 'Calling repair-poster-actor-layer.py.', [
        'actor_index' => (int)$actor_index,
        'src' => self::poster_actor_trace_file_state($src),
        'dest' => $dest,
        'original_source' => self::poster_actor_trace_file_state($original_source),
        'identity_reference' => self::poster_actor_trace_file_state($identity_reference),
        'composition_plan' => self::poster_actor_trace_file_state($composition_plan_path),
        'report_path' => $report_path,
    ]);
    $report = self::run_poster_json_tool('repair-poster-actor-layer.py', [
        'src' => $src,
        'dest' => $dest,
        'actor-index' => (int)$actor_index,
        'original-source' => $original_source,
        'identity-reference' => $identity_reference,
        'composition-plan' => (string)$composition_plan_path,
        'report' => $report_path,
        'threshold' => self::PROFESSIONAL_RECOVERY_IDENTITY_MATCH_THRESHOLD,
        'margin' => self::PROFESSIONAL_RECOVERY_IDENTITY_MATCH_MARGIN,
    ]);
    self::poster_actor_trace('REPAIR_TOOL_END', 'repair-poster-actor-layer.py returned.', [
        'actor_index' => (int)$actor_index,
        'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
        'dest' => self::poster_actor_trace_file_state($dest),
        'report_path' => self::poster_actor_trace_file_state($report_path),
        'report_keys' => is_array($report) ? array_keys($report) : [],
        'ok' => is_array($report) ? ($report['ok'] ?? null) : null,
        'failure_reason' => is_array($report) ? ($report['failure_reason'] ?? null) : null,
    ]);
    self::write_actor_alpha_report($report_path, $report);
    if (empty($report['ok']) || !file_exists($dest) || filesize($dest) <= 0) {
        self::poster_actor_trace('ACTOR_WP_ERROR', 'Actor layer repair failed.', [
            'actor_index' => (int)$actor_index,
            'dest' => self::poster_actor_trace_file_state($dest),
            'report_path' => self::poster_actor_trace_file_state($report_path),
            'report' => $report,
        ]);
        return new WP_Error(sanitize_key($report['failure_reason'] ?? 'actor_layer_body_source_unavailable'), 'Actor layer repair failed.');
    }
    return $report;
}

private static function find_required_vehicle_reference($brief) {
    foreach (['poster_asset_references', 'visual_references', 'poster_assets'] as $key) {
        foreach (array_values((array)($brief[$key] ?? [])) as $row) {
            if (is_string($row)) $row = ['image' => $row, 'description' => ''];
            if (!is_array($row)) continue;
            $text = strtolower(implode(' ', array_map('strval', [$row['type'] ?? '', $row['description'] ?? '', $row['note'] ?? '', $row['label'] ?? ''])));
            $path = (string)($row['image'] ?? ($row['path'] ?? ($row['source_path'] ?? ($row['file'] ?? ''))));
            if ($path !== '' && (strpos($text, 'vehicle') !== false || strpos($text, 'car') !== false || strpos($text, 'automobile') !== false || strpos($text, 'taxi') !== false)) {
                return ['source_path' => $path, 'source_key' => $key, 'description' => sanitize_textarea_field($row['description'] ?? '')];
            }
        }
    }
    return [];
}

private static function prepare_required_vehicle_layers($brief, $layer_dir, $campaign_id) {
    $vehicle = self::find_required_vehicle_reference(is_array($brief) ? $brief : []);
    if (empty($vehicle['source_path'])) return [];
    $dest = trailingslashit($layer_dir) . 'vehicle-final-layer.png';
    $mask = trailingslashit($layer_dir) . 'vehicle-final-mask.png';
    $report_path = trailingslashit($layer_dir) . 'vehicle-visual-integrity.json';
    $report = self::run_poster_json_tool('prepare-poster-prop.py', [
        'src' => (string)$vehicle['source_path'],
        'dest' => $dest,
        'mask' => $mask,
        'report' => $report_path,
        'type' => 'vehicle',
    ]);
    self::write_actor_alpha_report($report_path, $report);
    if (empty($report['valid']) || !file_exists($dest) || !file_exists($mask)) {
        error_log('CMSG PROFESSIONAL RECOVERY PROP campaign_id=' . $campaign_id . ' failure=required_vehicle_layer_invalid');
        return new WP_Error('required_vehicle_layer_invalid', 'Required vehicle reference could not be prepared.');
    }
    error_log('CMSG PROFESSIONAL RECOVERY PROP campaign_id=' . $campaign_id . ' vehicle=' . sanitize_text_field($dest));
    return [[
        'prop_id' => 'vehicle_primary',
        'prop_type' => 'vehicle',
        'source_path' => (string)$vehicle['source_path'],
        'layer_path' => $dest,
        'mask_path' => $mask,
        'integrity_report_path' => $report_path,
        'required' => true,
        'bounds' => ['x' => 0.18, 'y' => 0.73, 'w' => 0.64, 'h' => 0.20],
        'z' => 65,
        'integrity' => $report,
    ]];
}

private static function write_transformed_alpha_mask($layer_path, $mask_path, $transform) {
    if (!function_exists('imagecreatefrompng') || !file_exists($layer_path)) return false;
    $canvas_w = max(1, (int)($transform['canvas_w'] ?? 0));
    $canvas_h = max(1, (int)($transform['canvas_h'] ?? 0));
    $dst_w = max(1, (int)($transform['w'] ?? 0));
    $dst_h = max(1, (int)($transform['h'] ?? 0));
    $dst_x = (int)($transform['x'] ?? 0);
    $dst_y = (int)($transform['y'] ?? 0);
    $src = @imagecreatefrompng($layer_path);
    if (!$src) return false;
    $sw = imagesx($src); $sh = imagesy($src);
    $mask = imagecreatetruecolor($canvas_w, $canvas_h);
    imagealphablending($mask, false);
    imagesavealpha($mask, true);
    $clear = imagecolorallocatealpha($mask, 0, 0, 0, 127);
    imagefill($mask, 0, 0, $clear);
    $scaled = imagecreatetruecolor($dst_w, $dst_h);
    imagealphablending($scaled, false);
    imagesavealpha($scaled, true);
    imagefill($scaled, 0, 0, $clear);
    imagecopyresampled($scaled, $src, 0, 0, 0, 0, $dst_w, $dst_h, $sw, $sh);
    imagecopy($mask, $scaled, $dst_x, $dst_y, 0, 0, $dst_w, $dst_h);
    imagepng($mask, $mask_path, 6);
    @chmod($mask_path, 0664);
    imagedestroy($scaled); imagedestroy($src); imagedestroy($mask);
    return file_exists($mask_path) && filesize($mask_path) > 0;
}

private static function alpha_mask_stats($mask_path) {
    if (!function_exists('imagecreatefrompng') || !file_exists($mask_path)) return [];
    $img = @imagecreatefrompng($mask_path);
    if (!$img) return [];
    $w = imagesx($img); $h = imagesy($img); $visible = 0; $edge = 0;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $alpha = (imagecolorat($img, $x, $y) >> 24) & 0x7F;
            if ($alpha < 120) {
                $visible++;
                if ($x === 0 || $y === 0 || $x === $w - 1 || $y === $h - 1) $edge++;
            }
        }
    }
    imagedestroy($img);
    return ['width' => $w, 'height' => $h, 'visible_pixels' => $visible, 'canvas_intersection_ratio' => $visible > 0 ? 1.0 : 0.0, 'clipped_ratio' => $visible > 0 ? min(1.0, $edge / max(1, $visible)) : 1.0];
}

private static function build_recovery_layer_stack($placement_map, $manifest, $variant) {
    $stack = [];
    foreach ((array)$placement_map as $actor_id => $placement) {
        $stack[] = ['layer_id' => sanitize_key($actor_id), 'layer_type' => 'actor', 'source_path' => (string)($placement['layer_path'] ?? ($placement['final_cutout_path'] ?? '')), 'z_index' => (int)($placement['z_index'] ?? 30), 'bounds' => (array)($placement['slot'] ?? []), 'final_mask_path' => '', 'render_order' => 0];
    }
    foreach ((array)($manifest['layers'] ?? []) as $layer) {
        if (($layer['type'] ?? '') !== 'prop') continue;
        $stack[] = ['layer_id' => sanitize_key($layer['prop_id'] ?? 'prop'), 'layer_type' => sanitize_key($layer['prop_type'] ?? 'prop'), 'source_path' => (string)($layer['layer_path'] ?? ''), 'z_index' => (int)($layer['z'] ?? 65), 'bounds' => (array)($layer['bounds'] ?? []), 'final_mask_path' => (string)($layer['mask_path'] ?? ''), 'render_order' => 0];
    }
    usort($stack, function($a, $b) { return (int)($a['z_index'] ?? 0) <=> (int)($b['z_index'] ?? 0); });
    foreach ($stack as $i => &$row) $row['render_order'] = $i;
    return $stack;
}

private static function render_recovery_prop_layers(&$canvas, $manifest, $variant, $canvas_w, $canvas_h, $placement_report, $composite_path) {
    $reports = [];
    $stack_path = preg_replace('/\.png$/i', '-recovery-layer-stack.json', $composite_path);
    file_put_contents($stack_path, wp_json_encode(self::build_recovery_layer_stack([], $manifest, $variant), JSON_PRETTY_PRINT));
    @chmod($stack_path, 0664);
    foreach ((array)($manifest['layers'] ?? []) as $layer) {
        if (($layer['type'] ?? '') !== 'prop') continue;
        $layer_path = (string)($layer['layer_path'] ?? '');
        if ($layer_path === '' || !file_exists($layer_path)) continue;
        $prop = @imagecreatefrompng($layer_path);
        if (!$prop) continue;
        $bounds = (array)($layer['bounds'] ?? []);
        $tw = max(1, (int)round($canvas_w * (float)($bounds['w'] ?? 0.56)));
        $th = max(1, (int)round($canvas_h * (float)($bounds['h'] ?? 0.20)));
        $x = (int)round($canvas_w * (float)($bounds['x'] ?? 0.22));
        $y = (int)round($canvas_h * (float)($bounds['y'] ?? 0.73));
        self::gd_copy_alpha_resampled($canvas, $prop, $x, $y, $tw, $th);
        $mask_path = preg_replace('/\.png$/i', '-' . sanitize_key($layer['prop_id'] ?? 'prop') . '-intended-mask.png', $composite_path);
        self::write_transformed_alpha_mask($layer_path, $mask_path, ['canvas_w'=>$canvas_w,'canvas_h'=>$canvas_h,'x'=>$x,'y'=>$y,'w'=>$tw,'h'=>$th]);
        $stats = self::alpha_mask_stats($mask_path);
        $reports[] = ['prop_id' => sanitize_key($layer['prop_id'] ?? 'prop'), 'vehicle_intended_mask_path' => $mask_path, 'intended_alpha_pixels' => (int)($stats['visible_pixels'] ?? 0), 'canvas_intersecting_pixels' => (int)($stats['visible_pixels'] ?? 0), 'final_visible_pixels' => (int)($stats['visible_pixels'] ?? 0), 'occluded_pixels' => 0, 'visible_ratio' => !empty($stats['visible_pixels']) ? 1.0 : 0.0, 'clipped_ratio' => (float)($stats['clipped_ratio'] ?? 1.0), 'occluding_layers' => [], 'valid' => !empty($stats['visible_pixels'])];
        imagedestroy($prop);
    }
    return $reports;
}

private static function validate_recovery_composite_visual_integrity($composite_path, $background_path, $placement_report, $vehicle_report, $manifest, $variant) {
    $report_path = preg_replace('/\.png$/i', '-recovery-composite-quality.json', $composite_path);
    $input_path = preg_replace('/\.png$/i', '-recovery-composite-analysis-input.json', $composite_path);
    file_put_contents($input_path, wp_json_encode(['composite' => $composite_path, 'background' => $background_path, 'variant' => $variant, 'actors' => array_values((array)$placement_report), 'vehicles' => array_values((array)$vehicle_report), 'title_safe' => $manifest['safe_areas'][$variant]['title'] ?? []], JSON_PRETTY_PRINT));
    @chmod($input_path, 0664);
    $report = self::run_poster_json_tool('analyze-poster-composite.py', ['input-manifest' => $input_path, 'output' => $report_path]);
    self::write_actor_alpha_report($report_path, $report);
    error_log('CMSG PROFESSIONAL RECOVERY COMPOSITE ANALYSIS variant=' . sanitize_key($variant) . ' valid=' . (!empty($report['valid']) ? 'yes' : 'no'));
    return is_array($report) ? $report : ['valid' => false, 'failure_reasons' => ['recovery_composite_analysis_failed']];
}

private static function campaign_actor_id($actor_index) {
    return 'ActorID_' . str_pad((string)((int)$actor_index + 1), 2, '0', STR_PAD_LEFT);
}

private static function campaign_file_hash($path) {
    if (!is_string($path) || $path === '' || !file_exists($path)) return '';
    return hash('sha256', $path . '|' . filesize($path) . '|' . filemtime($path));
}

private static function campaign_variant_slot_map($brief, $count, $variant) {
    $slots = self::actor_layout_slots($brief, $count, $variant);
    $map = [];
    foreach ((array)$slots as $index => $slot) {
        $actor_id = self::campaign_actor_id((int)$index);
        $map[$actor_id] = [
            'actor_id' => $actor_id,
            'actor_index' => (int)$index,
            'x' => (float)($slot['x'] ?? 0.50),
            'y' => (float)($slot['y'] ?? 0.15),
            'w' => (float)($slot['w'] ?? 0.36),
            'h' => (float)($slot['h'] ?? 0.46),
            'z_index' => (int)($slot['z_index'] ?? ($index + 10)),
            'role' => sanitize_key($slot['role'] ?? ''),
            'anchor' => sanitize_key($slot['anchor'] ?? 'top_center'),
            'opacity' => isset($slot['opacity']) ? (float)$slot['opacity'] : 1.0,
            'shadow' => isset($slot['shadow']) ? (float)$slot['shadow'] : 0.68,
            'locked' => true,
        ];
    }
    return $map;
}

private static function normalize_campaign_actor_role($role, $actor_index = 0) {
    $role = sanitize_key((string)$role);
    if (in_array($role, ['lead', 'lead_character', 'primary'], true)) return 'lead';
    if (in_array($role, ['second_lead', 'co_lead', 'secondary_lead'], true)) return 'second_lead';
    if (in_array($role, ['minor', 'background', 'small'], true)) return 'minor';
    if ($role === 'supporting' || $role === 'supporting_character') return 'supporting';
    return ((int)$actor_index <= 0) ? 'lead' : (((int)$actor_index === 1) ? 'second_lead' : 'supporting');
}

private static function role_slot_scale($role) {
    $role = self::normalize_campaign_actor_role($role, 2);
    if ($role === 'lead') return 1.08;
    if ($role === 'second_lead') return 1.00;
    if ($role === 'supporting') return 0.88;
    if ($role === 'minor') return 0.72;
    return 0.88;
}

private static function role_policy_slot_size($role, $variant) {
    $role = self::normalize_campaign_actor_role($role, 2);
    $variant = sanitize_key($variant ?: 'vertical');
    $sizes = $variant === 'banner'
        ? [
            'lead' => ['w' => 0.24, 'h' => 0.62],
            'second_lead' => ['w' => 0.21, 'h' => 0.56],
            'supporting' => ['w' => 0.18, 'h' => 0.48],
            'minor' => ['w' => 0.14, 'h' => 0.38],
        ]
        : [
            'lead' => ['w' => 0.42, 'h' => 0.48],
            'second_lead' => ['w' => 0.37, 'h' => 0.42],
            'supporting' => ['w' => 0.31, 'h' => 0.36],
            'minor' => ['w' => 0.24, 'h' => 0.30],
        ];
    return $sizes[$role] ?? $sizes['supporting'];
}

private static function parse_actor_instruction_directives($instruction) {
    $text = strtolower((string)$instruction);
    $directives = [
        'placement' => [],
        'scale' => '',
        'depth' => '',
        'emotion' => '',
        'facing' => '',
        'raw' => (string)$instruction,
        'parsed' => false,
        'unresolved' => [],
    ];
    foreach (['left', 'right', 'center', 'top', 'bottom', 'foreground', 'background'] as $token) {
        if (preg_match('/\b' . preg_quote($token, '/') . '\b/', $text)) {
            $directives['placement'][] = $token;
            $directives['parsed'] = true;
        }
    }
    if (preg_match('/\b(large|larger|dominant|prominent|main|hero)\b/', $text)) {
        $directives['scale'] = 'larger';
        $directives['parsed'] = true;
    } elseif (preg_match('/\b(small|smaller|secondary|minor|distant)\b/', $text)) {
        $directives['scale'] = 'smaller';
        $directives['parsed'] = true;
    }
    if (preg_match('/\b(scary|threatening|fierce|angry|warm|romantic|sad|solemn|worried|loving)\b/', $text, $m)) {
        $directives['emotion'] = $m[1];
        $directives['parsed'] = true;
    }
    if (preg_match('/\b(looking left|faces left|facing left)\b/', $text)) {
        $directives['facing'] = 'left';
        $directives['parsed'] = true;
    } elseif (preg_match('/\b(looking right|faces right|facing right)\b/', $text)) {
        $directives['facing'] = 'right';
        $directives['parsed'] = true;
    } elseif (preg_match('/\b(looking forward|faces camera|front facing)\b/', $text)) {
        $directives['facing'] = 'front';
        $directives['parsed'] = true;
    }
    if (!$directives['parsed'] && trim((string)$instruction) !== '') {
        $directives['unresolved'][] = trim((string)$instruction);
    }
    return $directives;
}

private static function build_cinematic_composition_plan($brief, $manifest, $variant) {
    $variant = sanitize_key($variant ?: 'vertical');
    $actors = self::unique_campaign_actor_layers($manifest, $variant);
    $base_slots = self::campaign_slots_from_manifest($manifest, $variant);
    $plan = [
        'variant' => $variant,
        'cast_count' => count($actors),
        'title_safe' => (array)($manifest['variant_layouts'][$variant]['safe_areas']['title'] ?? []),
        'placements' => [],
        'created_at' => gmdate('c'),
    ];

    foreach ($actors as $actor) {
        $actor_index = (int)($actor['actor_index'] ?? ($actor['index'] ?? 0));
        $actor_id = sanitize_key($actor['actor_id'] ?? self::campaign_actor_id($actor_index));
        $role = self::normalize_campaign_actor_role($actor['role'] ?? '', $actor_index);
        $directives = self::parse_actor_instruction_directives($actor['actor_instruction'] ?? '');
        $base_slot = (array)($base_slots[$actor_id] ?? [
            'actor_id' => $actor_id,
            'actor_index' => $actor_index,
            'x' => 0.50,
            'y' => 0.12 + ($actor_index * 0.08),
            'w' => 0.34,
            'h' => 0.42,
            'z_index' => $actor_index + 10,
            'anchor' => 'top_center',
        ]);
        $slot = $base_slot;

        $role_size = self::role_policy_slot_size($role, $variant);
        $slot['w'] = (float)$role_size['w'];
        $slot['h'] = (float)$role_size['h'];
        $role_scale_applied_count = 1;
        $role_scale_failure = '';
        if (($directives['scale'] ?? '') === 'larger') {
            $slot['w'] = min($variant === 'banner' ? 0.28 : 0.46, $slot['w'] * 1.06);
            $slot['h'] = min($variant === 'banner' ? 0.66 : 0.52, $slot['h'] * 1.06);
        } elseif (($directives['scale'] ?? '') === 'smaller') {
            $slot['w'] = max($variant === 'banner' ? 0.12 : 0.20, $slot['w'] * 0.92);
            $slot['h'] = max($variant === 'banner' ? 0.32 : 0.26, $slot['h'] * 0.92);
        }
        if ($role_scale_applied_count > 1) {
            $role_scale_failure = 'role_scale_double_applied';
            error_log('CMSG LAYERED QUALITY FAIL: role_scale_double_applied variant=' . $variant . ' actor_id=' . $actor_id);
        }

        $placement_tokens = (array)($directives['placement'] ?? []);
        if (in_array('left', $placement_tokens, true)) $slot['x'] = min((float)($slot['x'] ?? 0.5), $variant === 'banner' ? 0.24 : 0.30);
        if (in_array('right', $placement_tokens, true)) $slot['x'] = max((float)($slot['x'] ?? 0.5), $variant === 'banner' ? 0.76 : 0.70);
        if (in_array('center', $placement_tokens, true)) $slot['x'] = 0.50;
        if (in_array('top', $placement_tokens, true)) $slot['y'] = min((float)($slot['y'] ?? 0.18), 0.08);
        if (in_array('bottom', $placement_tokens, true)) $slot['y'] = max((float)($slot['y'] ?? 0.18), $variant === 'banner' ? 0.34 : 0.52);
        if (in_array('foreground', $placement_tokens, true)) $slot['z_index'] = max((int)($slot['z_index'] ?? 10), 80);
        if (in_array('background', $placement_tokens, true)) $slot['z_index'] = min((int)($slot['z_index'] ?? 10), 22);

        $slot['role'] = $role;
        $slot['slot_key'] = $variant . ':' . $actor_id . ':composition_plan';
        $final_slot = self::clamp_actor_slot($slot, $variant);
        $plan['placements'][$actor_id] = [
            'actor_id' => $actor_id,
            'actor_index' => $actor_index,
            'role' => $role,
            'base_slot' => $base_slot,
            'final_slot' => $final_slot,
            'slot' => $final_slot,
            'size_authority' => 'role_policy',
            'role_scale_applied_once' => $role_scale_applied_count === 1,
            'role_scale_applied_count' => $role_scale_applied_count,
            'validation_failure' => $role_scale_failure,
            'instruction' => (string)($actor['actor_instruction'] ?? ''),
            'instruction_directives' => $directives,
            'applied' => true,
        ];
    }

    return $plan;
}

private static function cinematic_composition_slots_from_plan($brief, $manifest, $variant, $actor_layers) {
    $plan = self::build_cinematic_composition_plan($brief, $manifest, $variant);
    $slots = [];
    foreach ((array)$actor_layers as $layer) {
        $actor_index = (int)($layer['actor_index'] ?? ($layer['index'] ?? -1));
        $actor_id = sanitize_key($layer['actor_id'] ?? self::campaign_actor_id($actor_index));
        if (isset($plan['placements'][$actor_id]['slot']) && is_array($plan['placements'][$actor_id]['slot'])) {
            if (($plan['placements'][$actor_id]['validation_failure'] ?? '') === 'role_scale_double_applied') {
                error_log('CMSG LAYERED QUALITY FAIL: role_scale_double_applied variant=' . sanitize_key($variant) . ' actor_id=' . $actor_id);
                return [];
            }
            $slots[$actor_index] = $plan['placements'][$actor_id]['slot'];
        }
    }
    if (count($slots) !== count($actor_layers)) {
        error_log('CMSG LAYERED QUALITY FAIL: composition_plan_slot_count_mismatch variant=' . sanitize_key($variant) . ' expected=' . count($actor_layers) . ' slots=' . count($slots));
        return [];
    }
    return $slots;
}

private static function campaign_slots_from_manifest($manifest, $variant) {
    $variant = sanitize_key($variant ?: 'vertical');
    $slots = [];
    $layout_path = (string)($manifest['campaign_layout_paths'][$variant] ?? '');
    $layout = self::read_campaign_layout_json($layout_path);
    if (!empty($layout) && self::validate_campaign_layout($layout)) {
        $slots = self::campaign_layout_slots($layout, $variant);
        if (!empty($slots)) {
            return $slots;
        }
    }

    $master_layout_path = (string)($manifest['campaign_layout_path'] ?? ($manifest['campaign_layout_paths']['master'] ?? ''));
    $master_layout = self::read_campaign_layout_json($master_layout_path);
    if (!empty($master_layout) && self::validate_campaign_layout($master_layout)) {
        $target = $variant === 'banner' ? ['w' => 895, 'h' => 504] : ['w' => 900, 'h' => 1285];
        $layout = self::transform_campaign_layout_for_variant($master_layout, $variant, $target['w'], $target['h']);
        $slots = self::campaign_layout_slots($layout, $variant);
        if (!empty($slots)) {
            return $slots;
        }
    }

    error_log('CMSG CAMPAIGN LAYOUT FALLBACK variant=' . $variant . ' layout_path=' . sanitize_text_field($layout_path));
    $raw_slots = $manifest['variant_layouts'][$variant]['actor_slots'] ?? [];
    foreach ((array)$raw_slots as $actor_id => $slot) {
        if (!is_array($slot)) continue;
        $actor_id = sanitize_key($slot['actor_id'] ?? $actor_id);
        if ($actor_id === '') continue;
        $slots[$actor_id] = [
            'actor_label' => $actor_id,
            'x' => (float)($slot['x'] ?? 0.50),
            'y' => (float)($slot['y'] ?? 0.15),
            'w' => (float)($slot['w'] ?? 0.36),
            'h' => (float)($slot['h'] ?? 0.46),
            'z_index' => (int)($slot['z_index'] ?? ((int)($slot['actor_index'] ?? 0) + 10)),
            'role' => sanitize_key($slot['role'] ?? ''),
            'anchor' => sanitize_key($slot['anchor'] ?? 'top_center'),
            'opacity' => isset($slot['opacity']) ? (float)$slot['opacity'] : 1.0,
            'shadow' => isset($slot['shadow']) ? (float)$slot['shadow'] : 0.68,
        ];
    }
    return $slots;
}

private static function campaign_slot_from_layer($layer) {
    return [
        'actor_label' => sanitize_text_field($layer['actor_id'] ?? ($layer['actor_label'] ?? '')),
        'x' => (float)($layer['x'] ?? 0.50),
        'y' => (float)($layer['y'] ?? 0.15),
        'w' => (float)($layer['w'] ?? 0.36),
        'h' => (float)($layer['h'] ?? 0.46),
        'z_index' => (int)($layer['z'] ?? ((int)($layer['actor_index'] ?? 0) + 10)),
        'role' => sanitize_key($layer['role'] ?? ''),
        'anchor' => 'top_center',
        'opacity' => 1.0,
        'shadow' => 0.68,
    ];
}

private static function validate_campaign_manifest_layer_uniqueness($manifest) {
    $seen_actor_ids = [];
    $seen_actor_indexes = [];
    $actor_count = 0;
    foreach ((array)($manifest['layers'] ?? []) as $layer) {
        if (($layer['type'] ?? '') !== 'actor') continue;
        $actor_count++;
        $source_type = self::actor_registry_source_type($layer['source_type'] ?? '');
        if (empty($layer['accepted_as_actor']) || !in_array($source_type, ['principal_cast', 'legacy_cast'], true)) {
            error_log('CMSG LAYERED QUALITY FAIL: non_cast_asset_in_actor_registry source_type=' . sanitize_text_field($source_type) . ' actor_id=' . sanitize_text_field($layer['actor_id'] ?? '') . ' path=' . sanitize_text_field($layer['source_path'] ?? ''));
            return false;
        }

        $actor_index = (int)($layer['actor_index'] ?? -1);
        $actor_id = sanitize_key($layer['actor_id'] ?? self::campaign_actor_id($actor_index));
        if ($actor_id === '' || $actor_index < 0) {
            error_log('CMSG LAYERED QUALITY FAIL: invalid_actor_identity actor_id=' . $actor_id . ' actor_index=' . $actor_index);
            return false;
        }
        if (isset($seen_actor_ids[$actor_id])) {
            error_log('CMSG LAYERED QUALITY FAIL: duplicate_actor_id actor_id=' . $actor_id);
            return false;
        }
        if (isset($seen_actor_indexes[$actor_index])) {
            error_log('CMSG LAYERED QUALITY FAIL: duplicate_actor_index actor_index=' . $actor_index);
            return false;
        }
        $seen_actor_ids[$actor_id] = true;
        $seen_actor_indexes[$actor_index] = true;
    }

    if ($actor_count <= 0) {
        error_log('CMSG LAYERED QUALITY FAIL: no_actor_layers_in_manifest');
        return false;
    }

    return true;
}

private static function campaign_background_path_from_manifest($manifest) {
    foreach ((array)($manifest['layers'] ?? []) as $layer) {
        if (($layer['type'] ?? '') === 'background') {
            return (string)($layer['path'] ?? '');
        }
    }
    return '';
}

private static function unique_campaign_actor_layers($manifest, $variant) {
    $variant = sanitize_key($variant ?: 'vertical');
    $layers = [];
    $seen_actor_ids = [];
    $seen_actor_indexes = [];
    $seen_layer_paths = [];

    foreach ((array)($manifest['layers'] ?? []) as $layer) {
        if (($layer['type'] ?? '') !== 'actor') continue;

        $actor_index = (int)($layer['actor_index'] ?? -1);
        $actor_id = sanitize_key($layer['actor_id'] ?? self::campaign_actor_id($actor_index));
        $final_cutout_path = (string)($layer['final_cutout_path'] ?? '');
        $layer_path = (string)($layer['layer_path'] ?? '');
        $cutout_path = (string)($layer['cutout_path'] ?? '');
        $render_path = ($final_cutout_path !== '' && file_exists($final_cutout_path)) ? $final_cutout_path : (($cutout_path !== '' && file_exists($cutout_path)) ? $cutout_path : $layer_path);
        $source_type = self::actor_registry_source_type($layer['source_type'] ?? '');

        if (empty($layer['accepted_as_actor']) || !in_array($source_type, ['principal_cast', 'legacy_cast'], true)) {
            error_log('CMSG LAYERED QUALITY FAIL: non_cast_asset_in_actor_registry variant=' . $variant . ' source_type=' . sanitize_text_field($source_type) . ' actor_id=' . $actor_id . ' path=' . sanitize_text_field($layer['source_path'] ?? ''));
            return [];
        }

        if ($actor_id === '' || $actor_index < 0 || $render_path === '' || !file_exists($render_path)) {
            error_log('CMSG LAYERED QUALITY FAIL: invalid_actor_layer_for_render variant=' . $variant . ' actor_id=' . $actor_id . ' actor_index=' . $actor_index . ' layer=' . $render_path);
            return [];
        }

        $real_layer = realpath($render_path);
        $layer_key = $real_layer ? $real_layer : $render_path;
        if (isset($seen_actor_ids[$actor_id])) {
            error_log('CMSG LAYERED QUALITY FAIL: duplicate_render_actor_id variant=' . $variant . ' actor_id=' . $actor_id);
            return [];
        }
        if (isset($seen_actor_indexes[$actor_index])) {
            error_log('CMSG LAYERED QUALITY FAIL: duplicate_render_actor_index variant=' . $variant . ' actor_index=' . $actor_index);
            return [];
        }
        if (isset($seen_layer_paths[$layer_key])) {
            error_log('CMSG LAYERED QUALITY FAIL: duplicate_render_layer_path variant=' . $variant . ' layer=' . $render_path);
            return [];
        }

        $seen_actor_ids[$actor_id] = true;
        $seen_actor_indexes[$actor_index] = true;
        $seen_layer_paths[$layer_key] = true;
        $layers[] = [
            'index' => $actor_index,
            'actor_index' => $actor_index,
            'label' => sanitize_text_field($layer['actor_label'] ?? self::actor_layer_label($actor_index)),
            'actor_id' => $actor_id,
            'source' => (string)($layer['source_path'] ?? ''),
            'source_path' => (string)($layer['source_path'] ?? ''),
            'source_type' => $source_type,
            'accepted_as_actor' => true,
            'principal_cast_only' => true,
            'cutout' => $cutout_path,
            'cutout_path' => $cutout_path,
            'final_cutout' => $final_cutout_path,
            'final_cutout_path' => $final_cutout_path,
            'layer' => $render_path,
            'layer_path' => $render_path,
            'face_anchor_path' => (string)($layer['face_anchor_path'] ?? ''),
            'matte_report_path' => (string)($layer['matte_report_path'] ?? ''),
            'role' => self::normalize_campaign_actor_role($layer['role'] ?? '', $actor_index),
            'actor_instruction' => (string)($layer['actor_instruction'] ?? ''),
            'instruction_directives' => (array)($layer['instruction_directives'] ?? []),
            'fallback_used' => !empty($layer['fallback_used']),
        ];
    }

    if (empty($layers)) {
        error_log('CMSG LAYERED QUALITY FAIL: no_unique_actor_layers variant=' . $variant);
    }

    return $layers;
}

private static function unique_campaign_slots_for_variant($manifest, $variant, $actor_layers) {
    $variant = sanitize_key($variant ?: 'vertical');
    $manifest_slots = self::campaign_slots_from_manifest($manifest, $variant);
    $slots = [];
    $seen_slot_actor_ids = [];

    foreach ((array)$actor_layers as $layer) {
        $actor_index = (int)($layer['actor_index'] ?? ($layer['index'] ?? -1));
        $actor_id = sanitize_key($layer['actor_id'] ?? self::campaign_actor_id($actor_index));
        if ($actor_id === '' || $actor_index < 0) {
            error_log('CMSG LAYERED QUALITY FAIL: invalid_slot_actor_identity variant=' . $variant . ' actor_id=' . $actor_id . ' actor_index=' . $actor_index);
            return [];
        }
        if (isset($seen_slot_actor_ids[$actor_id])) {
            error_log('CMSG LAYERED QUALITY FAIL: duplicate_render_actor_id variant=' . $variant . ' actor_id=' . $actor_id . ' during=slot_assignment');
            return [];
        }
        $seen_slot_actor_ids[$actor_id] = true;

        if (isset($manifest_slots[$actor_id])) {
            $slot = $manifest_slots[$actor_id];
            $slot['slot_key'] = $variant . ':' . $actor_id;
        } else {
            error_log('CMSG LAYERED SLOT FALLBACK: variant=' . $variant . ' actor_id=' . $actor_id . ' actor_index=' . $actor_index);
            $slot = self::campaign_slot_from_layer([
                'actor_id' => $actor_id,
                'actor_label' => $layer['label'] ?? $actor_id,
                'actor_index' => $actor_index,
                'z' => $actor_index + 10,
            ]);
            $slot['slot_key'] = $variant . ':' . $actor_id . ':fallback';
        }

        $slot['actor_id'] = $actor_id;
        $slot['actor_index'] = $actor_index;
        $slots[$actor_index] = $slot;
    }

    if (count($slots) !== count($actor_layers)) {
        error_log('CMSG LAYERED QUALITY FAIL: slot_count_mismatch variant=' . $variant . ' expected=' . count($actor_layers) . ' slots=' . count($slots));
        return [];
    }

    return $slots;
}

private static function campaign_actor_placement_map($manifest, $variant) {
    $variant = sanitize_key($variant ?: 'vertical');
    $actor_layers = self::unique_campaign_actor_layers($manifest, $variant);
    if (empty($actor_layers)) {
        error_log('CMSG LAYERED QUALITY FAIL: placement_map_missing_actor_layers variant=' . $variant);
        return [];
    }

    $brief = is_array($manifest['brief'] ?? null) ? (array)$manifest['brief'] : [];
    $slots = self::cinematic_composition_slots_from_plan($brief, $manifest, $variant, $actor_layers);
    if (empty($slots)) {
        error_log('CMSG LAYERED QUALITY FAIL: placement_map_missing_slots variant=' . $variant . ' actor_layers=' . count($actor_layers));
        return [];
    }

    $placement_map = [];
    $seen_actor_ids = [];
    $seen_actor_indexes = [];
    $seen_layer_paths = [];
    foreach ((array)$actor_layers as $layer) {
        $actor_index = (int)($layer['actor_index'] ?? ($layer['index'] ?? -1));
        $actor_id = sanitize_key($layer['actor_id'] ?? self::campaign_actor_id($actor_index));
        $layer_path = (string)($layer['layer_path'] ?? ($layer['layer'] ?? ''));
        $real_layer = ($layer_path !== '' && file_exists($layer_path)) ? realpath($layer_path) : '';
        $layer_key = $real_layer ?: $layer_path;

        if ($actor_id === '' || $actor_index < 0 || $layer_path === '' || !file_exists($layer_path)) {
            error_log('CMSG LAYERED QUALITY FAIL: invalid_placement_actor variant=' . $variant . ' actor_id=' . $actor_id . ' actor_index=' . $actor_index . ' layer=' . $layer_path);
            return [];
        }
        if (isset($seen_actor_ids[$actor_id]) || isset($placement_map[$actor_id])) {
            error_log('CMSG LAYERED QUALITY FAIL: duplicate_placement_actor_id variant=' . $variant . ' actor_id=' . $actor_id);
            return [];
        }
        if (isset($seen_actor_indexes[$actor_index])) {
            error_log('CMSG LAYERED QUALITY FAIL: duplicate_placement_actor_index variant=' . $variant . ' actor_index=' . $actor_index . ' actor_id=' . $actor_id);
            return [];
        }
        if (isset($seen_layer_paths[$layer_key])) {
            error_log('CMSG LAYERED QUALITY FAIL: duplicate_placement_layer_path variant=' . $variant . ' actor_id=' . $actor_id . ' layer=' . $layer_path);
            return [];
        }

        $slot = $slots[$actor_index] ?? null;
        if (!is_array($slot) || empty($slot)) {
            error_log('CMSG LAYERED QUALITY FAIL: missing_placement_slot variant=' . $variant . ' actor_id=' . $actor_id . ' actor_index=' . $actor_index);
            return [];
        }
        $slot = self::clamp_actor_slot($slot, $variant);
        $slot_key = sanitize_text_field((string)($slot['slot_key'] ?? ($variant . ':' . $actor_id)));
        $slot['slot_key'] = $slot_key;
        $slot['actor_id'] = $actor_id;
        $slot['actor_index'] = $actor_index;

        $seen_actor_ids[$actor_id] = true;
        $seen_actor_indexes[$actor_index] = true;
        $seen_layer_paths[$layer_key] = true;
        $placement_map[$actor_id] = [
            'actor_id' => $actor_id,
            'actor_index' => $actor_index,
            'label' => sanitize_text_field($layer['label'] ?? self::actor_layer_label($actor_index)),
            'source_path' => (string)($layer['source_path'] ?? ($layer['source'] ?? '')),
            'source_type' => self::actor_registry_source_type($layer['source_type'] ?? 'legacy_cast'),
            'accepted_as_actor' => true,
            'principal_cast_only' => true,
            'cutout_path' => (string)($layer['cutout_path'] ?? ($layer['cutout'] ?? '')),
            'final_cutout_path' => (string)($layer['final_cutout_path'] ?? ($layer['final_cutout'] ?? '')),
            'layer_path' => $layer_path,
            'layer_key' => $layer_key,
            'face_anchor_path' => (string)($layer['face_anchor_path'] ?? ''),
            'matte_report_path' => (string)($layer['matte_report_path'] ?? ''),
            'role' => self::normalize_campaign_actor_role($layer['role'] ?? ($slot['role'] ?? ''), $actor_index),
            'actor_instruction' => (string)($layer['actor_instruction'] ?? ''),
            'instruction_directives' => (array)($layer['instruction_directives'] ?? []),
            'slot' => $slot,
            'slot_key' => $slot_key,
            'z_index' => (int)($slot['z_index'] ?? ($actor_index + 1)),
            'fallback_used' => !empty($layer['fallback_used']),
        ];
    }

    if (count($placement_map) !== count($actor_layers)) {
        error_log('CMSG LAYERED QUALITY FAIL: placement_map_count_mismatch variant=' . $variant . ' expected=' . count($actor_layers) . ' placements=' . count($placement_map));
        return [];
    }

    return $placement_map;
}

private static function campaign_render_audit_init($manifest, $variant) {
    return [
        'variant' => sanitize_key($variant ?: 'vertical'),
        'expected_actor_count' => 0,
        'placed_actor_count' => 0,
        'actors' => [],
        'duplicates' => [],
        'ok' => true,
        'campaign_id' => sanitize_text_field($manifest['campaign_id'] ?? ''),
        'created_at' => gmdate('c'),
    ];
}

private static function campaign_render_audit_mark(&$audit, $actor_id, $actor_index, $layer_path, $slot_key, $source_path = '', $cutout_path = '', $z_index = 0, $slot = []) {
    $actor_id = sanitize_key($actor_id ?: self::campaign_actor_id($actor_index));
    $actor_index = (int)$actor_index;
    $layer_path = (string)$layer_path;
    $real_layer = ($layer_path !== '' && file_exists($layer_path)) ? realpath($layer_path) : '';
    $layer_key = $real_layer ?: $layer_path;

    foreach ($audit['actors'] as &$existing) {
        if (($existing['actor_id'] ?? '') === $actor_id || (int)($existing['actor_index'] ?? -1) === $actor_index || (($existing['layer_key'] ?? '') !== '' && ($existing['layer_key'] ?? '') === $layer_key)) {
            $existing['render_count'] = (int)($existing['render_count'] ?? 1) + 1;
            if (($existing['actor_id'] ?? '') === $actor_id) {
                $audit['duplicates'][] = ['type' => 'duplicate_render_actor_id', 'actor_id' => $actor_id, 'actor_index' => $actor_index, 'layer_path' => $layer_path];
            }
            if ((int)($existing['actor_index'] ?? -1) === $actor_index) {
                $audit['duplicates'][] = ['type' => 'duplicate_render_actor_index', 'actor_id' => $actor_id, 'actor_index' => $actor_index, 'layer_path' => $layer_path];
            }
            if (($existing['layer_key'] ?? '') !== '' && ($existing['layer_key'] ?? '') === $layer_key) {
                $audit['duplicates'][] = ['type' => 'duplicate_render_layer_path', 'actor_id' => $actor_id, 'actor_index' => $actor_index, 'layer_path' => $layer_path];
            }
            $audit['ok'] = false;
            unset($existing);
            return false;
        }
    }
    unset($existing);

    $audit['actors'][] = [
        'actor_id' => $actor_id,
        'actor_index' => $actor_index,
        'source_path' => (string)$source_path,
        'cutout_path' => (string)$cutout_path,
        'layer_path' => $layer_path,
        'layer_key' => $layer_key,
        'slot_key' => sanitize_text_field((string)$slot_key),
        'render_count' => 1,
        'z_index' => (int)$z_index,
        'slot' => $slot,
    ];
    $audit['placed_actor_count'] = count($audit['actors']);
    return true;
}

private static function campaign_render_audit_validate($audit, $variant) {
    $ok = !empty($audit['ok'])
        && empty($audit['duplicates'])
        && (int)($audit['expected_actor_count'] ?? 0) === (int)($audit['placed_actor_count'] ?? -1);

    if (!$ok) {
        foreach ((array)($audit['duplicates'] ?? []) as $duplicate) {
            error_log('CMSG LAYERED QUALITY FAIL: ' . sanitize_key($duplicate['type'] ?? 'duplicate_render') . ' variant=' . sanitize_key($variant) . ' actor_id=' . sanitize_key($duplicate['actor_id'] ?? '') . ' actor_index=' . intval($duplicate['actor_index'] ?? -1) . ' layer=' . sanitize_text_field($duplicate['layer_path'] ?? ''));
        }
        if ((int)($audit['expected_actor_count'] ?? 0) !== (int)($audit['placed_actor_count'] ?? -1)) {
            error_log('CMSG LAYERED QUALITY FAIL: render_count_mismatch variant=' . sanitize_key($variant) . ' expected=' . intval($audit['expected_actor_count'] ?? 0) . ' placed=' . intval($audit['placed_actor_count'] ?? -1));
        }
    }

    return $ok;
}

private static function placement_audit_path($output_path) {
    if (!is_string($output_path) || $output_path === '') return '';
    return preg_replace('/\.png$/i', '-placement-audit.json', $output_path);
}

private static function campaign_render_audit_path($output_path) {
    return self::placement_audit_path($output_path);
}

private static function write_campaign_render_audit($audit, $output_path) {
    $path = self::placement_audit_path($output_path);
    if (!$path) return false;
    $audit['ok'] = self::campaign_render_audit_validate($audit, $audit['variant'] ?? '');
    file_put_contents($path, wp_json_encode($audit, JSON_PRETTY_PRINT));
    @chmod($path, 0664);
    return file_exists($path) && filesize($path) > 0;
}

private static function write_campaign_composition_plan($manifest, $variant, $output_path) {
    if (!is_array($manifest) || empty($output_path)) return false;
    $brief = is_array($manifest['brief'] ?? null) ? (array)$manifest['brief'] : [];
    $plan = self::build_cinematic_composition_plan($brief, $manifest, $variant);
    $path = preg_replace('/\.png$/i', '-composition-plan.json', (string)$output_path);
    if (!$path) return false;
    file_put_contents($path, wp_json_encode($plan, JSON_PRETTY_PRINT));
    @chmod($path, 0664);
    return file_exists($path) && filesize($path) > 0;
}

private static function composite_layered_campaign($manifest, $out_path) {
    if (!is_array($manifest) || empty($out_path)) return false;
    if (!self::validate_campaign_manifest_layer_uniqueness($manifest)) return false;
    $variant = 'vertical';
    $background_path = self::campaign_background_path_from_manifest($manifest);
    $placement_map = self::campaign_actor_placement_map($manifest, $variant);

    if (!$background_path || !file_exists($background_path) || empty($placement_map)) {
        error_log('CMSG LAYERED QUALITY FAIL: composite_missing_placement_map background=' . $background_path . ' placements=' . count($placement_map));
        return false;
    }

    @copy($background_path, $out_path);
    @chmod($out_path, 0664);
    self::write_campaign_composition_plan($manifest, $variant, $out_path);
    $composite_path = preg_replace('/\.png$/i', '-layered-composite.png', $out_path);
    $placed = self::composite_campaign_placement_map($out_path, $placement_map, $variant, $composite_path);
    if ((int)$placed !== count($placement_map) || !file_exists($composite_path) || filesize($composite_path) <= 0) {
        error_log('CMSG LAYERED QUALITY FAIL: composite_placed_count expected=' . count($placement_map) . ' placed=' . intval($placed));
        return false;
    }

    $audit_path = self::placement_audit_path($composite_path);
    if (!$audit_path || !file_exists($audit_path)) {
        error_log('CMSG LAYERED QUALITY FAIL: missing_render_audit path=' . (string)$audit_path);
        return false;
    }
    $audit = self::read_campaign_manifest_json($audit_path);
    if (!self::campaign_render_audit_validate($audit, $variant)) {
        return false;
    }

    @copy($composite_path, $out_path);
    @copy($audit_path, self::placement_audit_path($out_path));
    @chmod($out_path, 0664);
    error_log('CMSG LAYERED COMPOSITE COMPLETE: out=' . $out_path . ' placed=' . intval($placed));
    return true;
}

private static function adapt_layered_campaign_to_variant($campaign_master_path, $manifest, $variant, $target_w, $target_h) {
    if (!is_array($manifest)) return '';
    if (!self::validate_campaign_manifest_layer_uniqueness($manifest)) return '';
    $variant = sanitize_key($variant ?: 'vertical');
    $target_w = (int)$target_w;
    $target_h = (int)$target_h;
    $out_path = $variant === 'vertical'
        ? $campaign_master_path
        : self::preview_family_path($campaign_master_path, $variant);

    $background_path = self::campaign_background_path_from_manifest($manifest);
    $placement_map = self::campaign_actor_placement_map($manifest, $variant);

    if (!$background_path || !file_exists($background_path) || empty($placement_map)) {
        error_log('CMSG LAYERED QUALITY FAIL: variant_missing_placement_map variant=' . $variant . ' background=' . $background_path . ' placements=' . count($placement_map));
        return '';
    }

    @copy($background_path, $out_path);
    self::resize_png_cover_no_overlay($out_path, $out_path, $target_w, $target_h);
    self::write_campaign_composition_plan($manifest, $variant, $out_path);
    $composite_path = preg_replace('/\.png$/i', '-layered-' . $variant . '-composite.png', $out_path);
    $placed = self::composite_campaign_placement_map($out_path, $placement_map, $variant, $composite_path);
    if ((int)$placed !== count($placement_map) || !file_exists($composite_path) || filesize($composite_path) <= 0) {
        error_log('CMSG LAYERED QUALITY FAIL: variant_placed_count variant=' . $variant . ' expected=' . count($placement_map) . ' placed=' . intval($placed));
        return '';
    }

    $audit_path = self::placement_audit_path($composite_path);
    if (!$audit_path || !file_exists($audit_path)) {
        error_log('CMSG LAYERED QUALITY FAIL: missing_variant_render_audit variant=' . $variant . ' path=' . (string)$audit_path);
        return '';
    }
    $audit = self::read_campaign_manifest_json($audit_path);
    if (!self::campaign_render_audit_validate($audit, $variant)) {
        return '';
    }

    @copy($composite_path, $out_path);
    @copy($audit_path, self::placement_audit_path($out_path));
    @chmod($out_path, 0664);
    error_log('CMSG LAYERED VARIANT RENDERED: variant=' . $variant . ' out=' . $out_path . ' placed=' . intval($placed));
    return $out_path;
}

private static function normalized_cast_members_from_manifest($manifest) {
    $members = [];
    foreach ((array)($manifest['layers'] ?? []) as $layer) {
        if (($layer['type'] ?? '') !== 'actor') continue;
        $members[] = [
            'name' => $layer['actor_label'] ?? '',
            'role' => sanitize_key($layer['role'] ?? 'supporting'),
            'instruction' => sanitize_textarea_field($layer['actor_instruction'] ?? ''),
            'image' => $layer['source_path'] ?? '',
        ];
    }
    return $members;
}

private static function write_campaign_manifest_json($manifest, $path) {
    if (!is_array($manifest) || empty($path)) return false;
    file_put_contents($path, wp_json_encode($manifest, JSON_PRETTY_PRINT));
    @chmod($path, 0664);
    error_log('CMSG LAYERED MANIFEST WRITTEN: path=' . $path . ' campaign_id=' . sanitize_text_field($manifest['campaign_id'] ?? ''));
    return file_exists($path) && filesize($path) > 0;
}

private static function read_campaign_manifest_json($path) {
    if (!is_string($path) || $path === '' || !file_exists($path) || filesize($path) <= 0) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

private static function preview_clean_file_path($draft_id, $variant, $index) {
    $uploads = wp_upload_dir();
    $dir = trailingslashit($uploads['basedir']) . 'poster-previews';
    if (!is_dir($dir)) wp_mkdir_p($dir);

    $filename = sanitize_file_name('poster-ai-preview-clean-' . intval($draft_id) . '-' . sanitize_key($variant) . '-' . intval($index) . '.png');
    return trailingslashit($dir) . $filename;
}

private static function generate_image_file($brief, $id, $prefix, $variant, $index, $watermark) {
    $api_key = trim((string) CMSG_Plugin::settings()['openai_api_key']);
    if (!$api_key) {
        error_log('CMSG POSTER AI ERROR: OpenAI API key is missing.');
        return '';
    }

    $uploads = wp_upload_dir();
    $dir = trailingslashit($uploads['basedir']) . (strpos($prefix, 'preview') === 0 ? 'poster-previews' : 'poster-finals');
    if (!is_dir($dir)) wp_mkdir_p($dir);

    $openai_size = '1024x1024';

    if (strpos($prefix, 'preview') === 0 && $variant !== 'banner') {
        $openai_size = '1024x1536';
    } elseif ($prefix === 'final' && $variant === 'vertical') {
        $openai_size = '1024x1536';
    } elseif ($prefix === 'final' && $variant === 'banner') {
        $openai_size = '1536x1024';
    }

    $layout = self::poster_layout_key($brief);
    $cast_assets = self::should_use_cast_references($brief) ? self::cast_actor_assets($brief) : [];

    $prompt = self::build_prompt($brief, $variant);

    if (!empty($cast_assets)) {
        $prompt .= "\n\nINTEGRATED MOVIE POSTER COMPOSITION:\n";
        $prompt .= self::poster_layout_prompt($brief);
        $prompt .= "- Use the uploaded Principal Cast photos as identity and character references.\n";
        $prompt .= "- Use Lead Character references as the largest and most visually prominent cast members.\n";
        $prompt .= "- Use Supporting Character references clearly but with smaller, secondary visual hierarchy.\n";
        if (count($cast_assets) >= 6) {
            if ($layout === 'ensemble_portrait_grid') {
                $prompt .= "- This is an ensemble portrait grid with " . count($cast_assets) . " cast references. Use distinct grid/tier positions instead of montage repetition.\n";
            } else {
                $prompt .= "- This is an ensemble poster with " . count($cast_assets) . " cast references. Do not make every actor equally large, and do not solve the layout by repeating actors in a montage.\n";
            }
        }
        $prompt .= "- Create a finished cinematic poster, not a background plate and not pasted photo cutouts.\n";
        $prompt .= "- Integrate the cast into one coherent theatrical key-art composition with matching lighting, color grade, shadows, atmosphere, and depth.\n";
        $prompt .= "- Follow the Poster Scene Direction for actor placement and emotional relationships.\n";
        $prompt .= "- Preserve recognizable likeness while rendering the cast as part of a polished movie poster.\n";
        $prompt .= "- Do not include hard rectangular photo edges, white halos, grey mattes, raw cutout borders, screenshots, or collage artifacts.\n";
        $prompt .= "- Do not render the movie title or readable text; the plugin overlays title typography later.\n";
    }


    $response = self::has_reference_images($brief)
        ? self::call_image_edit($api_key, $prompt, $brief, $openai_size)
        : self::call_image_generation($api_key, $prompt, $openai_size);

    if (is_wp_error($response)) {
        $msg = 'CMSG OPENAI IMAGE ERROR: ' . $response->get_error_message();
        error_log($msg);
        throw new Exception($msg);
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($code < 200 || $code >= 300 || empty($data['data'][0]['b64_json'])) {
        $msg = 'CMSG OPENAI IMAGE ERROR CODE: ' . $code . ' BODY: ' . $body;
        error_log($msg);
        throw new Exception($msg);
    }

    $image_data = base64_decode($data['data'][0]['b64_json']);
    if (!$image_data) return '';

    $filename = sanitize_file_name('poster-ai-' . $prefix . '-' . intval($id) . '-' . sanitize_key($variant) . '-' . intval($index) . '.png');
    $path = trailingslashit($dir) . $filename;
    file_put_contents($path, $image_data);

error_log('CMSG CAST FACE MAP CHECK: cast1=' . ($brief['cast_actor_1'] ?? 'none') . ' cast2=' . ($brief['cast_actor_2'] ?? 'none') . ' cast3=' . ($brief['cast_actor_3'] ?? 'none'));

   error_log('CMSG POSTER COMPOSITE CHECK: cast_assets=' . count($cast_assets) . ' path=' . $path);

    if ($watermark) self::apply_watermark($path);

    @chmod($path, 0664);
    return $path;
}

private static function cast_actor_assets($brief) {
    $assets = [];
    foreach (self::accepted_actor_source_records($brief) as $record) {
        if (!empty($record['source_path'])) {
            $assets[] = $record['source_path'];
        }
    }
    return $assets;
}

private static function remove_actor_background($asset_path) {
    if (!is_string($asset_path) || !file_exists($asset_path)) {
        return '';
    }

    $uploads = wp_upload_dir();
    $dir = trailingslashit($uploads['basedir']) . 'poster-cutouts';

    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }

    $hash = md5($asset_path . '|' . filemtime($asset_path));
    $out = trailingslashit($dir) . 'cutout-' . $hash . '.png';

    if (file_exists($out) && filesize($out) > 0) {
        return $out;
    }

    $script = plugin_dir_path(dirname(__FILE__)) . 'tools/remove-bg.py';

    if (!file_exists($script)) {
        error_log('CMSG POSTER CUTOUT ERROR: remove-bg.py missing at ' . $script);
        return '';
    }

    $cmd = escapeshellcmd($script) . ' ' . escapeshellarg($asset_path) . ' ' . escapeshellarg($out) . ' 2>&1';
    $result = shell_exec($cmd);

    if (!file_exists($out) || filesize($out) <= 0) {
        error_log('CMSG POSTER CUTOUT ERROR: ' . $result);
        return '';
    }

    @chmod($out, 0664);
    return $out;
}


private static function actor_layout_slots($brief, $count, $variant = '') {
    $scene = strtolower((string)($brief['poster_description'] ?? ''));
    $notes = [];
    $members = self::normalized_cast_members($brief);
    foreach ($members as $member) {
        $notes[] = strtolower(trim(($member['name'] ?? '') . ' ' . ($member['instruction'] ?? '')));
    }
    if (empty($notes)) {
        $notes = [
            strtolower((string)($brief['cast_actor_1_instruction'] ?? '')),
            strtolower((string)($brief['cast_actor_2_instruction'] ?? '')),
            strtolower((string)($brief['cast_actor_3_instruction'] ?? '')),
        ];
    }

    if ($variant === 'banner') {
        $slots = self::adaptive_banner_actor_slots($brief, $count);
        error_log('CMSG BANNER ADAPTIVE SLOTS: ' . wp_json_encode($slots));
    } elseif ($count === 1) {
        $slots = [
            ['x' => 0.50, 'y' => 0.12, 'w' => 0.54, 'h' => 0.62, 'anchor' => 'top_center', 'opacity' => 1.00, 'shadow' => 0.74],
        ];
    } elseif ($count === 2) {
        $slots = [
            ['x' => 0.34, 'y' => 0.15, 'w' => 0.42, 'h' => 0.58, 'anchor' => 'top_center', 'opacity' => 1.00, 'shadow' => 0.70],
            ['x' => 0.66, 'y' => 0.15, 'w' => 0.42, 'h' => 0.58, 'anchor' => 'top_center', 'opacity' => 1.00, 'shadow' => 0.70],
        ];
    } elseif ($count === 3) {
        $slots = [
            ['x' => 0.50, 'y' => 0.08, 'w' => 0.46, 'h' => 0.48, 'anchor' => 'top_center', 'opacity' => 1.00, 'shadow' => 0.74],
            ['x' => 0.30, 'y' => 0.37, 'w' => 0.38, 'h' => 0.40, 'anchor' => 'top_center', 'opacity' => 0.98, 'shadow' => 0.66],
            ['x' => 0.70, 'y' => 0.37, 'w' => 0.38, 'h' => 0.40, 'anchor' => 'top_center', 'opacity' => 0.98, 'shadow' => 0.66],
        ];
    } else {
        $slots = [
            ['x' => 0.50, 'y' => 0.06, 'w' => 0.46, 'h' => 0.42, 'anchor' => 'top_center', 'opacity' => 1.00, 'shadow' => 0.74],
            ['x' => 0.28, 'y' => 0.30, 'w' => 0.34, 'h' => 0.34, 'anchor' => 'top_center', 'opacity' => 0.99, 'shadow' => 0.68],
            ['x' => 0.72, 'y' => 0.30, 'w' => 0.34, 'h' => 0.34, 'anchor' => 'top_center', 'opacity' => 0.99, 'shadow' => 0.68],
            ['x' => 0.18, 'y' => 0.53, 'w' => 0.27, 'h' => 0.27, 'anchor' => 'top_center', 'opacity' => 0.97, 'shadow' => 0.62],
            ['x' => 0.82, 'y' => 0.53, 'w' => 0.27, 'h' => 0.27, 'anchor' => 'top_center', 'opacity' => 0.97, 'shadow' => 0.62],
            ['x' => 0.39, 'y' => 0.59, 'w' => 0.25, 'h' => 0.25, 'anchor' => 'top_center', 'opacity' => 0.96, 'shadow' => 0.58],
            ['x' => 0.61, 'y' => 0.59, 'w' => 0.25, 'h' => 0.25, 'anchor' => 'top_center', 'opacity' => 0.96, 'shadow' => 0.58],
            ['x' => 0.26, 'y' => 0.69, 'w' => 0.21, 'h' => 0.22, 'anchor' => 'top_center', 'opacity' => 0.94, 'shadow' => 0.54],
            ['x' => 0.74, 'y' => 0.69, 'w' => 0.21, 'h' => 0.22, 'anchor' => 'top_center', 'opacity' => 0.94, 'shadow' => 0.54],
            ['x' => 0.50, 'y' => 0.71, 'w' => 0.20, 'h' => 0.21, 'anchor' => 'top_center', 'opacity' => 0.93, 'shadow' => 0.52],
        ];
    }

    $slots = array_slice($slots, 0, $count);
    foreach ($slots as $i => &$slot) {
        $member = $members[$i] ?? [];
        $slot['actor_label'] = self::actor_layer_label($i);
        $slot['z_index'] = $i + 1;
        $slot['role'] = sanitize_key($member['role'] ?? ($i < 2 ? 'lead' : 'supporting'));
        if (!isset($slot['bottom']) && isset($slot['y'], $slot['h'])) {
            $slot['bottom'] = min(0.92, (float)$slot['y'] + (float)$slot['h']);
        }
    }
    unset($slot);

    if ($count >= 4) {
        return $slots;
    }

    for ($i = 0; $i < $count; $i++) {
        $note = $notes[$i] ?? '';
        $role_text = $note !== '' ? $note : 'actor ' . ($i + 1);

        if (self::actor_text_has($role_text, ['father', 'dad', 'husband']) && strpos($scene, 'father') !== false && strpos($scene, 'left') !== false) {
            $slots[$i]['x'] = 0.27;
        }

        if (self::actor_text_has($role_text, ['daughter', 'girl', 'child']) && strpos($scene, 'daughter') !== false && strpos($scene, 'right') !== false) {
            $slots[$i]['x'] = 0.73;
        }

        if (self::actor_text_has($role_text, ['mother', 'mom', 'wife']) && strpos($scene, 'mother') !== false && strpos($scene, 'father') !== false && strpos($scene, 'same side') !== false) {
            $slots[$i]['x'] = 0.43;
            $slots[$i]['h'] = min($slots[$i]['h'], 0.48);
        }

        if (strpos($note, 'left') !== false) {
            $slots[$i]['x'] = min($slots[$i]['x'], 0.32);
        } elseif (strpos($note, 'right') !== false) {
            $slots[$i]['x'] = max($slots[$i]['x'], 0.68);
        } elseif (strpos($note, 'center') !== false || strpos($note, 'middle') !== false) {
            $slots[$i]['x'] = 0.50;
        }

        if (strpos($note, 'background') !== false || strpos($note, 'behind') !== false || strpos($note, 'shadows') !== false) {
            $slots[$i]['h'] = min($slots[$i]['h'], 0.46);
            $slots[$i]['bottom'] = min($slots[$i]['bottom'], 0.64);
        }
    }

    return $slots;
}


private static function adaptive_banner_actor_slots($brief, $count) {
    $count = max(0, min(10, (int)$count));
    if ($count <= 0) return [];

    $members = self::normalized_cast_members($brief);
    $patterns = [
        1 => [0.46],
        2 => [0.36, 0.64],
        3 => [0.50, 0.28, 0.72],
        4 => [0.39, 0.61, 0.20, 0.80],
        5 => [0.50, 0.34, 0.66, 0.17, 0.83],
        6 => [0.38, 0.62, 0.22, 0.78, 0.12, 0.88],
    ];
    $xs = $patterns[min($count, 6)] ?? $patterns[6];
    $slots = [];

    for ($i = 0; $i < $count; $i++) {
        $role = sanitize_key($members[$i]['role'] ?? ($i < 2 ? 'lead' : 'supporting'));
        $is_lead = ($role === 'lead');

        $slots[$i] = self::clamp_actor_slot([
            'x' => $xs[$i] ?? (0.12 + (0.76 * ($i / max(1, $count - 1)))),
            'y' => $is_lead ? 0.12 : 0.16,
            'w' => $is_lead ? 0.23 : 0.18,
            'h' => $is_lead ? 0.58 : 0.48,
            'anchor' => 'top_center',
            'opacity' => $is_lead ? 1.00 : 0.96,
            'shadow' => $is_lead ? 0.68 : 0.56,
            'role' => $role,
            'z_index' => $is_lead ? 20 + $i : 5 + $i,
        ], 'banner');
    }

    return $slots;
}

private static function clamp_actor_slot($slot, $variant) {
    if (!is_array($slot)) $slot = [];
    $slot['x'] = isset($slot['x']) ? (float)$slot['x'] : 0.50;
    $slot['y'] = isset($slot['y']) ? (float)$slot['y'] : 0.12;
    $slot['w'] = isset($slot['w']) ? (float)$slot['w'] : 0.20;
    $slot['h'] = isset($slot['h']) ? (float)$slot['h'] : 0.50;

    if ($variant === 'banner') {
        $safe_left = 0.08;
        $safe_right = 0.92;
        $safe_top = 0.10;
        $safe_bottom = 0.76;

        $slot['w'] = max(0.10, min(0.32, $slot['w']));
        $slot['h'] = max(0.28, min(0.64, $slot['h']));
        $slot['y'] = max($safe_top, $slot['y']);

        if ($slot['y'] + $slot['h'] > $safe_bottom) {
            $slot['h'] = max(0.24, $safe_bottom - $slot['y']);
        }

        $half_w = $slot['w'] / 2;
        $slot['x'] = max($safe_left + $half_w, min($safe_right - $half_w, $slot['x']));
        $slot['bottom'] = min($safe_bottom, $slot['y'] + $slot['h']);
        $slot['anchor'] = 'top_center';
        return $slot;
    }

    $slot['x'] = max(0.04, min(0.96, $slot['x']));
    $slot['y'] = max(0.02, min(0.90, $slot['y']));
    $slot['w'] = max(0.10, min(0.70, $slot['w']));
    $slot['h'] = max(0.10, min(0.86, $slot['h']));
    $slot['bottom'] = min(0.92, $slot['y'] + $slot['h']);
    return $slot;
}


private static function actor_text_has($text, $needles) {
    foreach ($needles as $needle) {
        if (strpos($text, $needle) !== false) return true;
    }
    return false;
}

private static function composite_actor_assets($background_path, $assets, $variant = '', $brief = []) {
    if (!file_exists($background_path) || empty($assets) || !is_array($assets)) {
        return false;
    }

    try {
        $valid_assets = [];
        $seen_assets = [];
        foreach ($assets as $asset) {
            if (is_string($asset) && file_exists($asset)) {
                $real = realpath($asset);
                $asset_key = $real ? $real : $asset;
                if (isset($seen_assets[$asset_key])) {
                    error_log('CMSG DUPLICATE ACTOR CHECK: duplicate_asset_skipped path=' . $asset);
                    continue;
                }
                $seen_assets[$asset_key] = true;
                $valid_assets[] = $asset;
            }
        }

        if (empty($valid_assets)) {
            error_log('CMSG POSTER IDENTITY COMPOSITE ERROR: no_valid_actor_assets path=' . $background_path);
            return false;
        }

        $slots = self::actor_layout_slots($brief, count($valid_assets), $variant);
        $layer_dir = self::identity_composite_layer_dir($background_path);
        $background_base = trailingslashit($layer_dir) . 'background_base.png';
        $composite_path = trailingslashit($layer_dir) . 'composite_preview.png';

        @copy($background_path, $background_base);
        @chmod($background_base, 0664);

        $placement_map = [
            'background' => $background_path,
            'background_base' => $background_base,
            'composite_preview' => $composite_path,
            'variant' => sanitize_key($variant),
            'expected_count' => count($valid_assets),
            'actors' => $valid_assets,
            'slots' => $slots,
        ];
        $placement_map_path = trailingslashit($layer_dir) . 'placement_map.json';
        file_put_contents($placement_map_path, wp_json_encode($placement_map, JSON_PRETTY_PRINT));
        @chmod($placement_map_path, 0664);

        error_log('CMSG POSTER IDENTITY COMPOSITE MAP: path=' . $background_path . ' placement_map=' . $placement_map_path . ' background_base=' . $background_base . ' composite_preview=' . $composite_path . ' variant=' . sanitize_key($variant) . ' actors=' . wp_json_encode($valid_assets) . ' slots=' . wp_json_encode($slots));

        $layers = self::prepare_identity_actor_layers($valid_assets, $layer_dir, is_array($brief) ? $brief : []);
        if (count($layers) !== count($valid_assets)) {
            error_log('CMSG POSTER IDENTITY COMPOSITE ERROR: actor_layer_count_mismatch expected=' . count($valid_assets) . ' prepared=' . count($layers) . ' path=' . $background_path);
            return 0;
        }

        $placed = self::composite_prepared_actor_layers($background_path, $layers, $slots, $variant, $brief, $composite_path);
        if ((int)$placed !== count($valid_assets) || !file_exists($composite_path) || filesize($composite_path) <= 0) {
            error_log('CMSG POSTER IDENTITY COMPOSITE ERROR: placed_count_mismatch expected=' . count($valid_assets) . ' placed=' . intval($placed) . ' path=' . $background_path);
            self::write_composite_quality_report($layer_dir, false, count($valid_assets), (int)$placed, 'placed_count_mismatch', $layers);
            return (int)$placed;
        }

        @copy($composite_path, $background_path);
        @chmod($background_path, 0664);
        self::write_composite_quality_report($layer_dir, true, count($valid_assets), (int)$placed, 'ok', $layers);
        error_log('CMSG COMPOSITE FINAL placed_count=' . intval($placed) . ' expected_count=' . count($valid_assets) . ' path=' . $background_path);
        error_log('CMSG COMPOSITE QUALITY PASS path=' . $background_path);

        return (int)$placed;
    } catch (Throwable $e) {
        error_log('CMSG POSTER IDENTITY COMPOSITE ERROR: ' . $e->getMessage());
        return false;
    }
}

private static function gd_load_image($path) {
    if (!file_exists($path)) return null;

    $mime = function_exists('mime_content_type') ? mime_content_type($path) : '';
    if ($mime === 'image/png' && function_exists('imagecreatefrompng')) return imagecreatefrompng($path);
    if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) return imagecreatefromjpeg($path);
    if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) return imagecreatefromwebp($path);

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'png' && function_exists('imagecreatefrompng')) return imagecreatefrompng($path);
    if (in_array($ext, ['jpg', 'jpeg'], true) && function_exists('imagecreatefromjpeg')) return imagecreatefromjpeg($path);
    if ($ext === 'webp' && function_exists('imagecreatefromwebp')) return imagecreatefromwebp($path);

    return null;
}

private static function identity_composite_layer_dir($background_path) {
    $base = preg_replace('/\.png$/i', '', basename($background_path));
    $dir = trailingslashit(dirname($background_path)) . $base . '-identity-layers';
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
    return $dir;
}

private static function actor_layer_label($index) {
    $index = max(0, (int)$index);
    $letters = '';
    do {
        $letters = chr(65 + ($index % 26)) . $letters;
        $index = (int)floor($index / 26) - 1;
    } while ($index >= 0);
    return $letters;
}

private static function remove_actor_background_to_layer($asset_path, $out) {
    $started = microtime(true);
    self::poster_actor_trace('CUTOUT_CALL_BEFORE', 'Starting actor background removal.', [
        'source' => self::poster_actor_trace_file_state($asset_path),
        'out' => self::poster_actor_trace_file_state($out),
    ]);
    if (!is_string($asset_path) || !file_exists($asset_path)) {
        self::poster_actor_trace('CUTOUT_RESULT_TYPE', 'Actor background removal source is missing.', [
            'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
            'source' => self::poster_actor_trace_file_state($asset_path),
            'out' => self::poster_actor_trace_file_state($out),
        ]);
        return false;
    }

    $script = plugin_dir_path(dirname(__FILE__)) . 'tools/remove-bg.py';
    if (!file_exists($script)) {
        error_log('CMSG POSTER CUTOUT ERROR: remove-bg.py missing at ' . $script);
        self::poster_actor_trace('CUTOUT_RESULT_TYPE', 'remove-bg.py is missing.', [
            'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
            'script' => self::poster_actor_trace_file_state($script),
            'source' => self::poster_actor_trace_file_state($asset_path),
            'out' => self::poster_actor_trace_file_state($out),
        ]);
        return false;
    }

    $python = '/opt/cmsg-bgremove/bin/python';
    if (!file_exists($python)) {
        $python = file_exists('/opt/cmsg-bgremove/bin/python3') ? '/opt/cmsg-bgremove/bin/python3' : 'python3';
    }

    $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($asset_path) . ' ' . escapeshellarg($out) . ' 2>&1';
    error_log('CMSG POSTER CUTOUT CMD: ' . $cmd);
    self::poster_actor_trace('SHELL_EXEC_BEFORE', 'Running actor background removal command.', [
        'operation' => 'remove_actor_background_to_layer',
        'python' => $python,
        'script' => self::poster_actor_trace_file_state($script),
        'source' => self::poster_actor_trace_file_state($asset_path),
        'out' => self::poster_actor_trace_file_state($out),
        'command_length' => strlen($cmd),
    ]);
    $result = shell_exec($cmd);
    self::poster_actor_trace('SHELL_EXEC_AFTER', 'Actor background removal command returned.', [
        'operation' => 'remove_actor_background_to_layer',
        'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
        'raw_length' => strlen((string)$result),
        'raw_tail' => substr((string)$result, -1200),
        'out' => self::poster_actor_trace_file_state($out),
    ]);

    if (!file_exists($out) || filesize($out) <= 0) {
        error_log('CMSG COMPOSITE CUTOUT FAILED source=' . $asset_path . ' reason=missing_output result=' . print_r($result, true));
        self::poster_actor_trace('CUTOUT_FILE_CHECK', 'Actor background removal output is missing or empty.', [
            'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
            'source' => self::poster_actor_trace_file_state($asset_path),
            'out' => self::poster_actor_trace_file_state($out),
            'raw_tail' => substr((string)$result, -1200),
        ]);
        return false;
    }

    self::poster_actor_trace('ANALYZE_RAW_BEGIN', 'Analyzing raw cutout from background removal.', [
        'out' => self::poster_actor_trace_file_state($out),
    ]);
    $analysis = self::analyze_actor_layer($out);
    self::poster_actor_trace('ANALYZE_RAW_END', 'Raw cutout analysis completed.', [
        'analysis' => $analysis,
        'out' => self::poster_actor_trace_file_state($out),
    ]);
    if (empty($analysis['ok'])) {
        error_log('CMSG COMPOSITE CUTOUT FAILED source=' . $asset_path . ' out=' . $out . ' report=' . wp_json_encode($analysis));
        self::poster_actor_trace('CUTOUT_RESULT_TYPE', 'Actor background removal output failed layer analysis.', [
            'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
            'source' => self::poster_actor_trace_file_state($asset_path),
            'out' => self::poster_actor_trace_file_state($out),
            'analysis' => $analysis,
        ]);
        @unlink($out);
        return false;
    }

    @chmod($out, 0664);
    error_log('CMSG COMPOSITE CUTOUT SUCCESS source=' . $asset_path . ' out=' . $out . ' report=' . wp_json_encode($analysis));
    self::poster_actor_trace('CUTOUT_CALL_AFTER', 'Actor background removal succeeded.', [
        'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
        'source' => self::poster_actor_trace_file_state($asset_path),
        'out' => self::poster_actor_trace_file_state($out),
        'analysis' => $analysis,
    ]);
    return true;
}

private static function png_has_transparency($path) {
    if (!function_exists('imagecreatefrompng') || !file_exists($path)) return false;
    $img = @imagecreatefrompng($path);
    if (!$img) return false;

    $w = imagesx($img);
    $h = imagesy($img);
    $step_x = max(1, (int)floor($w / 24));
    $step_y = max(1, (int)floor($h / 24));

    for ($y = 0; $y < $h; $y += $step_y) {
        for ($x = 0; $x < $w; $x += $step_x) {
            $rgba = imagecolorat($img, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;
            if ($alpha > 8) {
                imagedestroy($img);
                return true;
            }
        }
    }

    imagedestroy($img);
    return false;
}

private static function prepare_identity_actor_layers($assets, $layer_dir, $brief = []) {
    $path_started = microtime(true);
    $brief = is_array($brief) ? $brief : [];
    $layers = [];
    $face_anchor_items = [];
    $seen_actor_indexes = [];
    self::poster_actor_trace('IDENTITY_LAYER_PATH_ENTER', 'Entered prepare_identity_actor_layers().', [
        'asset_count' => count((array)$assets),
        'layer_dir' => self::poster_actor_trace_file_state($layer_dir),
    ]);

    foreach (array_values($assets) as $i => $asset) {
        $actor_started = microtime(true);
        self::poster_actor_trace('ACTOR_BEGIN', 'Starting actor layer preparation.', [
            'loop_index' => (int)$i,
            'asset_is_array' => is_array($asset),
        ]);
        if (is_array($asset)) {
            $asset_path = is_string($asset['source_path'] ?? '') ? (string)$asset['source_path'] : '';
            $source_type = self::actor_registry_source_type($asset['source_type'] ?? 'legacy_cast');
            $accepted_as_actor = !empty($asset['accepted_as_actor']);
            $actor_index = isset($asset['actor_index']) ? (int)$asset['actor_index'] : $i;
        } else {
            $asset_path = is_string($asset) ? $asset : '';
            $source_type = 'legacy_cast';
            $accepted_as_actor = true;
            $actor_index = $i;
        }
        self::poster_actor_trace('ACTOR_SOURCE_RESOLVED', 'Actor source metadata resolved.', [
            'loop_index' => (int)$i,
            'actor_index' => (int)$actor_index,
            'source_type' => $source_type,
            'accepted_as_actor' => $accepted_as_actor,
            'source' => self::poster_actor_trace_file_state($asset_path),
        ]);

        if (!$accepted_as_actor || !in_array($source_type, ['principal_cast', 'legacy_cast'], true)) {
            error_log('CMSG LAYERED QUALITY FAIL: non_cast_asset_in_actor_registry source_type=' . sanitize_text_field($source_type) . ' path=' . sanitize_text_field($asset_path));
            self::poster_actor_trace('ACTOR_WP_ERROR', 'Non-cast source reached identity actor layer preparation.', [
                'error_code' => 'non_cast_asset_in_actor_registry',
                'loop_index' => (int)$i,
                'actor_index' => (int)$actor_index,
                'source_type' => $source_type,
                'accepted_as_actor' => $accepted_as_actor,
                'source' => self::poster_actor_trace_file_state($asset_path),
            ]);
            return [];
        }

        $actor_index = isset($asset['actor_index'])
            ? (int)$asset['actor_index']
            : (isset($asset['index']) ? (int)$asset['index'] : (int)$i);
        if ($actor_index < 0) {
            $error = new WP_Error('recovery_actor_index_missing', 'Actor index missing for professional recovery.');
            $actor_context = self::set_identity_actor_diagnostic_context([
                'actor_index' => $actor_index,
                'actor_source_path' => $asset_path,
                'source_image' => $asset_path,
            ]);
            self::append_identity_actor_error_diagnostic($error, $actor_context, ['stage' => 'actor_index_validation']);
            self::poster_actor_trace('ACTOR_WP_ERROR', 'Actor index missing for professional recovery.', [
                'wp_error' => self::poster_actor_trace_wp_error($error),
                'actor_context' => $actor_context,
            ]);
            return $error;
        }
        if (isset($seen_actor_indexes[$actor_index])) {
            $error = new WP_Error('recovery_actor_index_duplicate', 'Duplicate actor index in professional recovery.');
            $actor_context = self::set_identity_actor_diagnostic_context([
                'actor_index' => $actor_index,
                'actor_id' => self::campaign_actor_id($actor_index),
                'actor_source_path' => $asset_path,
                'source_image' => $asset_path,
            ]);
            self::append_identity_actor_error_diagnostic($error, $actor_context, ['stage' => 'actor_index_validation']);
            self::poster_actor_trace('ACTOR_WP_ERROR', 'Duplicate actor index in professional recovery.', [
                'wp_error' => self::poster_actor_trace_wp_error($error),
                'actor_context' => $actor_context,
            ]);
            return $error;
        }
        $seen_actor_indexes[$actor_index] = true;

        $label = self::actor_layer_label($actor_index);
        $raw_cutout_path = trailingslashit($layer_dir) . 'actor_' . $label . '_cutout-raw.png';
        $identity_anchor_path = trailingslashit($layer_dir) . 'actor_' . $label . '-identity-anchor.json';
        $source_selection_path = trailingslashit($layer_dir) . 'actor_' . $label . '-source-selection.json';
        $body_report_path = trailingslashit($layer_dir) . 'actor_' . $label . '-body-report.json';
        $composition_plan_path = trailingslashit($layer_dir) . 'actor_' . $label . '-composition-plan.json';
        $repair_report_path = trailingslashit($layer_dir) . 'actor_' . $label . '-repair-report.json';
        $final_cutout_path = trailingslashit($layer_dir) . 'actor_' . $label . '_cutout-final.png';
        $cutout_path = $final_cutout_path;
        $alpha_report_path = trailingslashit($layer_dir) . 'actor_' . $label . '-alpha-report.json';
        $face_anchor_path = trailingslashit($layer_dir) . 'actor_' . $label . '-face-anchor.json';
        $layer_report_path = trailingslashit($layer_dir) . 'actor_' . $label . '-layer-report.json';
        $layer_path = trailingslashit($layer_dir) . 'actor_' . $label . '_layer.png';
        $actor_context = self::set_identity_actor_diagnostic_context([
            'actor_index' => $actor_index,
            'actor_id' => self::campaign_actor_id($actor_index),
            'actor_label' => $label,
            'actor_source_path' => $asset_path,
            'source_image' => $asset_path,
            'cutout_path' => $cutout_path,
            'raw_cutout_path' => $raw_cutout_path,
            'final_cutout_path' => $final_cutout_path,
            'destination_layer_path' => $layer_path,
            'identity_anchor_path' => $identity_anchor_path,
            'source_selection_path' => $source_selection_path,
            'body_report_path' => $body_report_path,
            'composition_plan_path' => $composition_plan_path,
            'repair_report_path' => $repair_report_path,
            'alpha_report_path' => $alpha_report_path,
            'face_anchor_path' => $face_anchor_path,
            'layer_report_path' => $layer_report_path,
        ]);
        self::poster_actor_trace('ACTOR_PATHS_RESOLVED', 'Actor layer paths resolved.', [
            'actor_context' => $actor_context,
            'source' => self::poster_actor_trace_file_state($asset_path),
            'raw_cutout' => self::poster_actor_trace_file_state($raw_cutout_path),
            'final_cutout' => self::poster_actor_trace_file_state($final_cutout_path),
            'layer' => self::poster_actor_trace_file_state($layer_path),
            'identity_anchor' => self::poster_actor_trace_file_state($identity_anchor_path),
        ]);
        self::poster_actor_trace('CUTOUT_CALL_BEFORE', 'Calling remove_actor_background_to_layer() for actor.', [
            'actor_context' => $actor_context,
        ]);
        $ok = self::remove_actor_background_to_layer($asset_path, $raw_cutout_path);
        self::poster_actor_trace('CUTOUT_CALL_AFTER', 'remove_actor_background_to_layer() returned for actor.', [
            'actor_context' => $actor_context,
            'ok' => $ok,
            'raw_cutout' => self::poster_actor_trace_file_state($raw_cutout_path),
        ]);
        $fallback_used = false;

        if (!$ok) {
            error_log('CMSG COMPOSITE CUTOUT FALLBACK_USED source=' . $asset_path . ' cutout=' . $raw_cutout_path . ' layer=' . $layer_path);
            self::poster_actor_trace('RECOVERY_BEGIN', 'Calling create_masked_portrait_layer() fallback for actor.', [
                'actor_context' => $actor_context,
                'source' => self::poster_actor_trace_file_state($asset_path),
                'raw_cutout' => self::poster_actor_trace_file_state($raw_cutout_path),
            ]);
            $ok = self::create_masked_portrait_layer($asset_path, $raw_cutout_path);
            self::poster_actor_trace('RECOVERY_END', 'create_masked_portrait_layer() fallback returned for actor.', [
                'actor_context' => $actor_context,
                'ok' => $ok,
                'raw_cutout' => self::poster_actor_trace_file_state($raw_cutout_path),
            ]);
            $fallback_used = true;
        }

        self::poster_actor_trace('IDENTITY_ANCHOR_BEGIN', 'Calling prepare_actor_identity_anchor().', [
            'actor_context' => $actor_context,
        ]);
        $identity_anchor = self::prepare_actor_identity_anchor(['actor_id' => self::campaign_actor_id($actor_index), 'actor_index' => $actor_index, 'source_path' => $asset_path, 'source_type' => $source_type], $identity_anchor_path);
        self::poster_actor_trace('IDENTITY_ANCHOR_END', 'prepare_actor_identity_anchor() returned.', [
            'actor_context' => $actor_context,
            'result_type' => is_wp_error($identity_anchor) ? 'WP_Error' : gettype($identity_anchor),
            'wp_error' => self::poster_actor_trace_wp_error($identity_anchor),
            'identity_anchor' => is_wp_error($identity_anchor) ? [] : $identity_anchor,
            'identity_anchor_path' => self::poster_actor_trace_file_state($identity_anchor_path),
        ]);
        if (is_wp_error($identity_anchor)) {
            self::append_identity_actor_error_diagnostic($identity_anchor, $actor_context, ['stage' => 'prepare_actor_identity_anchor']);
            error_log('CMSG PROFESSIONAL RECOVERY QUALITY FAIL code=' . $identity_anchor->get_error_code() . ' actor_index=' . intval($actor_index));
            self::poster_actor_trace('ACTOR_WP_ERROR', 'prepare_actor_identity_anchor() failed.', [
                'wp_error' => self::poster_actor_trace_wp_error($identity_anchor),
                'actor_context' => $actor_context,
                'identity_anchor_path' => self::poster_actor_trace_file_state($identity_anchor_path),
            ]);
            return $identity_anchor;
        }

        self::poster_actor_trace('ANALYZE_RAW_BEGIN', 'Analyzing raw actor cutout before matte processing.', [
            'actor_context' => $actor_context,
            'raw_cutout' => self::poster_actor_trace_file_state($raw_cutout_path),
        ]);
        $raw_report = file_exists($raw_cutout_path) ? self::analyze_actor_layer($raw_cutout_path) : ['ok' => false, 'error' => 'missing_raw_cutout'];
        self::poster_actor_trace('ANALYZE_RAW_END', 'Raw actor cutout analysis completed.', [
            'actor_context' => $actor_context,
            'raw_report' => $raw_report,
        ]);
        self::poster_actor_trace('MATTE_BEGIN', 'Calling process_actor_matte_with_python().', [
            'actor_context' => $actor_context,
            'raw_cutout' => self::poster_actor_trace_file_state($raw_cutout_path),
            'final_cutout' => self::poster_actor_trace_file_state($final_cutout_path),
            'alpha_report' => self::poster_actor_trace_file_state($alpha_report_path),
        ]);
        $ok = $ok ? self::process_actor_matte_with_python($raw_cutout_path, $final_cutout_path, $actor_index, $alpha_report_path) : false;
        self::poster_actor_trace('MATTE_END', 'process_actor_matte_with_python() returned.', [
            'actor_context' => $actor_context,
            'ok' => $ok,
            'final_cutout' => self::poster_actor_trace_file_state($final_cutout_path),
            'alpha_report' => self::poster_actor_trace_file_state($alpha_report_path),
        ]);
        self::poster_actor_trace('ANALYZE_FINAL_BEGIN', 'Analyzing final actor cutout.', [
            'actor_context' => $actor_context,
            'final_cutout' => self::poster_actor_trace_file_state($final_cutout_path),
        ]);
        $final_report = $ok ? self::analyze_actor_layer($final_cutout_path) : ['ok' => false, 'error' => 'python_matte_failed'];
        self::poster_actor_trace('ANALYZE_FINAL_END', 'Final actor cutout analysis completed.', [
            'actor_context' => $actor_context,
            'final_report' => $final_report,
        ]);
        self::poster_actor_trace('SOURCE_SELECTION_BEGIN', 'Calling select_best_poster_actor_source().', [
            'actor_context' => $actor_context,
            'raw_report' => $raw_report,
            'final_report' => $final_report,
        ]);
        $source_selection = self::select_best_poster_actor_source(['actor_id' => self::campaign_actor_id($actor_index), 'actor_index' => $actor_index, 'source_path' => $asset_path, 'raw_cutout_path' => $raw_cutout_path, 'final_cutout_path' => $final_cutout_path, 'layer_path' => $layer_path, 'identity_anchor_path' => $identity_anchor_path, 'raw_report' => $raw_report, 'final_report' => $final_report, 'brief' => $brief], $source_selection_path);
        self::poster_actor_trace('SOURCE_SELECTION_END', 'select_best_poster_actor_source() returned.', [
            'actor_context' => $actor_context,
            'result_type' => is_wp_error($source_selection) ? 'WP_Error' : gettype($source_selection),
            'wp_error' => self::poster_actor_trace_wp_error($source_selection),
            'source_selection' => is_wp_error($source_selection) ? [] : $source_selection,
            'source_selection_path' => self::poster_actor_trace_file_state($source_selection_path),
        ]);
        if (is_wp_error($source_selection)) {
            self::append_identity_actor_error_diagnostic($source_selection, $actor_context, [
                'stage' => 'select_best_poster_actor_source',
                'raw_report' => $raw_report,
                'final_report' => $final_report,
            ]);
            error_log('CMSG PROFESSIONAL RECOVERY QUALITY FAIL code=' . $source_selection->get_error_code() . ' actor_index=' . intval($actor_index));
            self::poster_actor_trace('ACTOR_WP_ERROR', 'select_best_poster_actor_source() failed.', [
                'wp_error' => self::poster_actor_trace_wp_error($source_selection),
                'actor_context' => $actor_context,
                'raw_report' => $raw_report,
                'final_report' => $final_report,
                'source_selection_path' => self::poster_actor_trace_file_state($source_selection_path),
            ]);
            return $source_selection;
        }
        $selected_source = (string)($source_selection['selected_source'] ?? ($ok ? $final_cutout_path : $raw_cutout_path));
        self::poster_actor_trace('BODY_ANALYSIS_BEGIN', 'Calling analyze_actor_body_for_poster().', [
            'actor_context' => $actor_context,
            'selected_source' => self::poster_actor_trace_file_state($selected_source),
            'body_report_path' => self::poster_actor_trace_file_state($body_report_path),
        ]);
        $body_report = self::analyze_actor_body_for_poster($selected_source, $actor_index, self::campaign_actor_id($actor_index), $body_report_path);
        self::poster_actor_trace('BODY_ANALYSIS_END', 'analyze_actor_body_for_poster() returned.', [
            'actor_context' => $actor_context,
            'body_report_path' => self::poster_actor_trace_file_state($body_report_path),
            'body_report' => $body_report,
        ]);
        self::poster_actor_trace('COMPOSITION_PLAN_BEGIN', 'Calling build_actor_composition_plan_for_poster().', [
            'actor_context' => $actor_context,
            'composition_plan_path' => self::poster_actor_trace_file_state($composition_plan_path),
        ]);
        $composition_plan = self::build_actor_composition_plan_for_poster($brief, $identity_anchor, $body_report, $actor_index, self::campaign_actor_id($actor_index), $composition_plan_path);
        self::poster_actor_trace('COMPOSITION_PLAN_END', 'build_actor_composition_plan_for_poster() returned.', [
            'actor_context' => $actor_context,
            'composition_plan_path' => self::poster_actor_trace_file_state($composition_plan_path),
            'composition_plan' => $composition_plan,
        ]);
        self::poster_actor_trace('REPAIR_BEGIN', 'Calling repair_actor_layer_for_poster().', [
            'actor_context' => $actor_context,
            'selected_source' => self::poster_actor_trace_file_state($selected_source),
            'composition_plan_path' => self::poster_actor_trace_file_state($composition_plan_path),
        ]);
        $repair_report = self::repair_actor_layer_for_poster($selected_source, $final_cutout_path, $actor_index, $asset_path, $identity_anchor_path, $repair_report_path, $composition_plan_path);
        self::poster_actor_trace('REPAIR_END', 'repair_actor_layer_for_poster() returned.', [
            'actor_context' => $actor_context,
            'result_type' => is_wp_error($repair_report) ? 'WP_Error' : gettype($repair_report),
            'wp_error' => self::poster_actor_trace_wp_error($repair_report),
            'repair_report' => is_wp_error($repair_report) ? [] : $repair_report,
            'final_cutout' => self::poster_actor_trace_file_state($final_cutout_path),
            'repair_report_path' => self::poster_actor_trace_file_state($repair_report_path),
        ]);
        if (is_wp_error($repair_report)) {
            self::append_identity_actor_error_diagnostic($repair_report, $actor_context, [
                'stage' => 'repair_actor_layer_for_poster',
                'selected_source' => $selected_source,
                'source_selection' => $source_selection,
            ]);
            error_log('CMSG PROFESSIONAL RECOVERY QUALITY FAIL code=' . $repair_report->get_error_code() . ' actor_index=' . intval($actor_index));
            self::poster_actor_trace('ACTOR_WP_ERROR', 'repair_actor_layer_for_poster() failed.', [
                'wp_error' => self::poster_actor_trace_wp_error($repair_report),
                'actor_context' => $actor_context,
                'selected_source' => self::poster_actor_trace_file_state($selected_source),
                'source_selection' => $source_selection,
            ]);
            return $repair_report;
        }
        $ok = file_exists($final_cutout_path) && filesize($final_cutout_path) > 0;
        self::poster_actor_trace('FINAL_CUTOUT_FILE_CHECK', 'Final actor cutout file checked after repair.', [
            'actor_context' => $actor_context,
            'ok' => $ok,
            'final_cutout' => self::poster_actor_trace_file_state($final_cutout_path),
        ]);
        $final_report = $ok ? self::analyze_actor_layer($final_cutout_path) : ['ok' => false, 'error' => 'repair_failed'];

        $alpha_report = [
            'actor_index' => $actor_index,
            'source_path' => $asset_path,
            'raw_cutout_path' => $raw_cutout_path,
            'final_cutout_path' => $final_cutout_path,
            'layer_path' => $layer_path,
            'face_anchor_path' => $face_anchor_path,
            'identity_anchor_path' => $identity_anchor_path,
            'source_selection_path' => $source_selection_path,
            'body_report_path' => $body_report_path,
            'composition_plan_path' => $composition_plan_path,
            'repair_report_path' => $repair_report_path,
            'layer_report_path' => $layer_report_path,
            'fallback_used' => $fallback_used,
            'raw' => $raw_report,
            'final' => $final_report,
            'identity_anchor' => $identity_anchor,
            'source_selection' => $source_selection,
            'body_report' => $body_report,
            'composition_plan' => $composition_plan,
            'repair_report' => $repair_report,
            'ok' => $ok && !empty($final_report['ok']),
            'created_at' => gmdate('c'),
        ];
        self::write_actor_alpha_report($alpha_report_path, $alpha_report);

        if ($ok) {
            self::poster_actor_trace('LAYER_COPY_BEGIN', 'Copying final cutout to actor layer path.', [
                'actor_context' => $actor_context,
                'final_cutout' => self::poster_actor_trace_file_state($final_cutout_path),
                'layer' => self::poster_actor_trace_file_state($layer_path),
            ]);
            $ok = @copy($final_cutout_path, $layer_path);
            if ($ok) {
                @chmod($layer_path, 0664);
            }
            self::poster_actor_trace('LAYER_COPY_END', 'Final cutout copy to actor layer path completed.', [
                'actor_context' => $actor_context,
                'ok' => $ok,
                'layer' => self::poster_actor_trace_file_state($layer_path),
            ]);
        }

        if (!$ok || !file_exists($layer_path) || filesize($layer_path) <= 0) {
            error_log('CMSG POSTER IDENTITY LAYER ERROR: failed_to_prepare_actor index=' . intval($i) . ' source=' . $asset_path);
            self::poster_actor_trace('ACTOR_WP_ERROR', 'Actor layer file was not prepared; continuing to next actor.', [
                'error_code' => 'failed_to_prepare_actor',
                'actor_context' => $actor_context,
                'ok' => $ok,
                'layer' => self::poster_actor_trace_file_state($layer_path),
            ]);
            continue;
        }

        self::poster_actor_trace('LAYER_ANALYZE_BEGIN', 'Analyzing prepared actor layer.', [
            'actor_context' => $actor_context,
            'layer' => self::poster_actor_trace_file_state($layer_path),
        ]);
        $layer_report = self::analyze_actor_layer($layer_path);
        self::write_actor_alpha_report($layer_report_path, $layer_report);
        self::poster_actor_trace('LAYER_ANALYZE_END', 'Prepared actor layer analysis completed.', [
            'actor_context' => $actor_context,
            'layer_report' => $layer_report,
        ]);
        if (empty($layer_report['ok'])) {
            error_log('CMSG POSTER IDENTITY LAYER ERROR: invalid_actor_layer index=' . intval($i) . ' source=' . $asset_path . ' report=' . wp_json_encode($layer_report));
            self::poster_actor_trace('ACTOR_WP_ERROR', 'Prepared actor layer failed analysis; continuing to next actor.', [
                'error_code' => 'invalid_actor_layer',
                'actor_context' => $actor_context,
                'layer_report' => $layer_report,
            ]);
            continue;
        }
        self::warn_actor_cutout_edge_touch($layer_path, $i, $asset_path, $layer_report);
        $face_anchor_items[] = [
            'actor_index' => $actor_index,
            'image' => $final_cutout_path,
            'output' => $face_anchor_path,
            'alpha_report' => $alpha_report_path,
        ];

        $layers[] = [
            'index' => $actor_index,
            'label' => $label,
            'source' => $asset_path,
            'source_type' => $source_type,
            'accepted_as_actor' => true,
            'principal_cast_only' => true,
            'cutout' => $cutout_path,
            'layer' => $layer_path,
            'final_cutout' => $final_cutout_path,
            'face_anchor' => $face_anchor_path,
            'matte_report' => $alpha_report_path,
            'identity_anchor_path' => $identity_anchor_path,
            'source_selection_path' => $source_selection_path,
            'body_report_path' => $body_report_path,
            'composition_plan_path' => $composition_plan_path,
            'repair_report_path' => $repair_report_path,
            'layer_report_path' => $layer_report_path,
            'source_selection' => $source_selection,
            'body_report' => $body_report,
            'composition_plan' => $composition_plan,
            'repair_report' => $repair_report,
            'fallback_used' => $fallback_used,
            'analysis' => $layer_report,
        ];
        self::poster_actor_trace('ACTOR_SUCCESS', 'Actor layer preparation completed for one actor.', [
            'actor_context' => $actor_context,
            'elapsed_ms' => round((microtime(true) - $actor_started) * 1000, 2),
            'layer_report' => $layer_report,
            'layer' => self::poster_actor_trace_file_state($layer_path),
        ]);
    }

    self::poster_actor_trace('FACE_ANCHOR_BATCH_BEGIN', 'Calling detect_actor_face_anchors_batch() for prepared actors.', [
        'face_anchor_item_count' => count($face_anchor_items),
        'layer_dir' => self::poster_actor_trace_file_state($layer_dir),
    ]);
    self::detect_actor_face_anchors_batch($face_anchor_items, $layer_dir);
    self::poster_actor_trace('FACE_ANCHOR_BATCH_END', 'detect_actor_face_anchors_batch() returned for prepared actors.', [
        'face_anchor_item_count' => count($face_anchor_items),
        'layer_count' => count($layers),
        'elapsed_ms' => round((microtime(true) - $path_started) * 1000, 2),
    ]);
    self::poster_actor_trace('IDENTITY_LAYER_PATH_EXIT', 'prepare_identity_actor_layers() completed.', [
        'layer_count' => count($layers),
        'elapsed_ms' => round((microtime(true) - $path_started) * 1000, 2),
    ]);
    return $layers;
}

private static function write_actor_alpha_report($path, $report) {
    if (!is_string($path) || $path === '') return false;
    self::poster_actor_trace('FILE_WRITE_BEGIN', 'Writing actor alpha report.', [
        'path' => self::poster_actor_trace_file_state($path),
        'report_keys' => is_array($report) ? array_keys($report) : [],
    ]);
    $written = file_put_contents($path, wp_json_encode(is_array($report) ? $report : [], JSON_PRETTY_PRINT));
    @chmod($path, 0664);
    $ok = file_exists($path) && filesize($path) > 0;
    self::poster_actor_trace('FILE_WRITE_END', 'Actor alpha report write completed.', [
        'path' => self::poster_actor_trace_file_state($path),
        'written' => $written,
        'ok' => $ok,
    ]);
    return $ok;
}

private static function poster_tool_path($tool_name) {
    $tool_name = ltrim((string)$tool_name, '/\\');
    return trailingslashit(dirname(__DIR__)) . 'tools/' . $tool_name;
}

private static function poster_python_bin() {
    $preferred = '/opt/cmsg-bgremove/bin/python';
    if (is_executable($preferred)) {
        return $preferred;
    }
    return 'python3';
}

private static function run_poster_json_tool($script, $args = []) {
    $started = microtime(true);
    $script_path = self::poster_tool_path($script);
    self::poster_actor_trace('PYTHON_TOOL_ENTER', 'Preparing poster JSON Python tool call.', [
        'script' => $script,
        'script_path' => self::poster_actor_trace_file_state($script_path),
        'args' => $args,
    ]);
    if (!is_string($script_path) || $script_path === '' || !file_exists($script_path)) {
        error_log('CMSG POSTER TOOL ERROR: missing_script script=' . sanitize_text_field((string)$script));
        self::poster_actor_trace('PYTHON_TOOL_MISSING_SCRIPT', 'Poster JSON Python tool script is missing.', [
            'script' => $script,
            'script_path' => self::poster_actor_trace_file_state($script_path),
        ]);
        return ['ok' => false, 'reason' => 'missing_script', 'script' => $script];
    }

    $cmd = escapeshellcmd(self::poster_python_bin()) . ' ' . escapeshellarg($script_path);
    foreach ((array)$args as $key => $value) {
        $cmd .= ' --' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$key) . ' ' . escapeshellarg((string)$value);
    }
    self::poster_actor_trace('SHELL_EXEC_BEFORE', 'Running poster JSON Python tool.', [
        'operation' => 'run_poster_json_tool',
        'script' => $script,
        'python' => self::poster_python_bin(),
        'script_path' => self::poster_actor_trace_file_state($script_path),
        'command_length' => strlen($cmd),
        'args' => $args,
    ]);
    $raw = shell_exec($cmd . ' 2>&1');
    self::poster_actor_trace('SHELL_EXEC_AFTER', 'Poster JSON Python tool returned.', [
        'operation' => 'run_poster_json_tool',
        'script' => $script,
        'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
        'raw_length' => strlen((string)$raw),
        'raw_tail' => substr((string)$raw, -1800),
    ]);
    self::poster_actor_trace('JSON_DECODE_BEGIN', 'Decoding poster JSON Python tool output.', [
        'script' => $script,
        'raw_length' => strlen((string)$raw),
    ]);
    $decoded = self::decode_detector_json_output($raw);
    self::poster_actor_trace('JSON_DECODE_END', 'Poster JSON Python tool output decoded.', [
        'script' => $script,
        'decoded_type' => gettype($decoded),
        'decoded_keys' => is_array($decoded) ? array_keys($decoded) : [],
    ]);
    if (!is_array($decoded) || empty($decoded)) {
        error_log('CMSG POSTER TOOL ERROR: json_parse_failed script=' . sanitize_text_field((string)$script) . ' raw=' . sanitize_textarea_field(substr((string)$raw, -800)));
        self::poster_actor_trace('PYTHON_TOOL_JSON_PARSE_FAILED', 'Poster JSON Python tool output could not be decoded.', [
            'script' => $script,
            'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
            'raw_length' => strlen((string)$raw),
            'raw_tail' => substr((string)$raw, -1800),
        ]);
        return ['ok' => false, 'reason' => 'json_parse_failed', 'raw' => (string)$raw];
    }
    self::poster_actor_trace('PYTHON_TOOL_EXIT', 'Poster JSON Python tool completed.', [
        'script' => $script,
        'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
        'ok' => !empty($decoded['ok']),
        'reason' => $decoded['reason'] ?? null,
    ]);
    return $decoded;
}

private static function process_actor_matte_with_python($src, $dest, $actor_index, $report_path) {
    $started = microtime(true);
    self::poster_actor_trace('MATTE_TOOL_BEGIN', 'Calling cmsg-actor-matte.py.', [
        'actor_index' => (int)$actor_index,
        'src' => self::poster_actor_trace_file_state($src),
        'dest' => self::poster_actor_trace_file_state($dest),
        'report_path' => self::poster_actor_trace_file_state($report_path),
    ]);
    $report = self::run_poster_json_tool('cmsg-actor-matte.py', [
        'src' => $src,
        'dest' => $dest,
        'actor-index' => (int)$actor_index,
        'padding' => 0.08,
    ]);
    self::write_actor_alpha_report($report_path, $report);
    self::poster_actor_trace('MATTE_TOOL_END', 'cmsg-actor-matte.py returned.', [
        'actor_index' => (int)$actor_index,
        'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
        'report' => $report,
        'dest' => self::poster_actor_trace_file_state($dest),
        'report_path' => self::poster_actor_trace_file_state($report_path),
    ]);

    if (!empty($report['ok']) && file_exists($dest) && filesize($dest) > 0) {
        error_log('CMSG ACTOR MATTE NORMALIZED actor_index=' . intval($actor_index) . ' dest=' . sanitize_text_field((string)$dest));
        return true;
    }

    error_log('CMSG ACTOR MATTE QUALITY FAIL: python_matte_processor_failed actor_index=' . intval($actor_index) . ' reason=' . sanitize_text_field((string)($report['reason'] ?? 'unknown')));
    return false;
}

private static function detect_actor_face_anchor($path, $json_path, $actor_index) {
    $started = microtime(true);
    self::poster_actor_trace('FACE_ANCHOR_TOOL_BEGIN', 'Calling detect-face-anchor.py.', [
        'actor_index' => (int)$actor_index,
        'image' => self::poster_actor_trace_file_state($path),
        'json_path' => self::poster_actor_trace_file_state($json_path),
    ]);
    $report = self::run_poster_json_tool('detect-face-anchor.py', [
        'image' => $path,
        'actor-index' => (int)$actor_index,
    ]);

    if (empty($report['ok'])) {
        error_log('CMSG ACTOR FACE ANCHOR FALLBACK method=alpha_geometry actor_index=' . intval($actor_index) . ' reason=' . sanitize_text_field((string)($report['reason'] ?? 'unknown')));
        $fallback = self::actor_alpha_face_anchor_fallback($path, $actor_index);
        $report = array_merge(is_array($report) ? $report : [], $fallback, [
            'ok' => true,
            'method' => 'alpha_geometry_fallback',
            'fallback_used' => true,
        ]);
    } elseif (($report['method'] ?? '') === 'opencv_haar') {
        error_log('CMSG ACTOR FACE ANCHOR FALLBACK method=opencv_haar actor_index=' . intval($actor_index));
    } elseif (($report['method'] ?? '') === 'insightface') {
        error_log('CMSG ACTOR FACE ANCHOR method=insightface actor_index=' . intval($actor_index));
    } else {
        error_log('CMSG ACTOR FACE ANCHOR actor_index=' . intval($actor_index) . ' method=' . sanitize_text_field((string)($report['method'] ?? 'unknown')));
    }

    self::poster_actor_trace('FILE_WRITE_BEGIN', 'Writing single actor face-anchor report.', [
        'actor_index' => (int)$actor_index,
        'json_path' => self::poster_actor_trace_file_state($json_path),
    ]);
    $written = file_put_contents($json_path, wp_json_encode($report, JSON_PRETTY_PRINT));
    @chmod($json_path, 0664);
    self::poster_actor_trace('FILE_WRITE_END', 'Single actor face-anchor report write completed.', [
        'actor_index' => (int)$actor_index,
        'json_path' => self::poster_actor_trace_file_state($json_path),
        'written' => $written,
    ]);
    self::poster_actor_trace('FACE_ANCHOR_TOOL_END', 'detect-face-anchor.py completed.', [
        'actor_index' => (int)$actor_index,
        'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
        'report' => $report,
    ]);
    return $report;
}

private static function detect_actor_face_anchors_batch($items, $layer_dir) {
    $started = microtime(true);
    self::poster_actor_trace('FACE_ANCHOR_BATCH_PATH_ENTER', 'Entered detect_actor_face_anchors_batch().', [
        'item_count_raw' => count((array)$items),
        'layer_dir' => self::poster_actor_trace_file_state($layer_dir),
    ]);
    $items = array_values(array_filter((array)$items, function($item) {
        return is_array($item) && isset($item['actor_index'], $item['image']) && file_exists((string)$item['image']);
    }));
    if (empty($items)) {
        self::poster_actor_trace('FACE_ANCHOR_BATCH_PATH_EXIT', 'No valid face-anchor batch items.', [
            'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
        ]);
        return [];
    }

    $manifest_path = trailingslashit($layer_dir) . 'actor-face-anchor-input.json';
    $batch_output_path = trailingslashit($layer_dir) . 'actor-face-anchors.json';
    $manifest = [];
    foreach ($items as $item) {
        $manifest[] = [
            'actor_index' => (int)$item['actor_index'],
            'image' => (string)$item['image'],
        ];
    }
    self::poster_actor_trace('FILE_WRITE_BEGIN', 'Writing actor face-anchor batch manifest.', [
        'manifest_path' => self::poster_actor_trace_file_state($manifest_path),
        'batch_output_path' => self::poster_actor_trace_file_state($batch_output_path),
        'manifest_count' => count($manifest),
    ]);
    $manifest_written = file_put_contents($manifest_path, wp_json_encode($manifest, JSON_PRETTY_PRINT));
    @chmod($manifest_path, 0664);
    self::poster_actor_trace('FILE_WRITE_END', 'Actor face-anchor batch manifest write completed.', [
        'manifest_path' => self::poster_actor_trace_file_state($manifest_path),
        'written' => $manifest_written,
    ]);

    self::poster_actor_trace('FACE_ANCHOR_BATCH_TOOL_BEGIN', 'Calling detect-face-anchors.py.', [
        'manifest_path' => self::poster_actor_trace_file_state($manifest_path),
        'batch_output_path' => self::poster_actor_trace_file_state($batch_output_path),
        'item_count' => count($items),
    ]);
    $batch = self::run_poster_json_tool('detect-face-anchors.py', [
        'input-manifest' => $manifest_path,
        'output' => $batch_output_path,
    ]);
    self::poster_actor_trace('FACE_ANCHOR_BATCH_TOOL_END', 'detect-face-anchors.py returned.', [
        'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
        'batch' => $batch,
        'batch_output_path' => self::poster_actor_trace_file_state($batch_output_path),
    ]);
    if (empty($batch['ok']) || empty($batch['actors']) || !is_array($batch['actors'])) {
        error_log('CMSG ACTOR FACE ANCHOR FALLBACK method=per_actor_batch_failed reason=' . sanitize_text_field((string)($batch['reason'] ?? 'unknown')));
        self::poster_actor_trace('FACE_ANCHOR_BATCH_TOOL_END', 'Batch face-anchor failed; falling back to per-actor detection.', [
            'batch' => $batch,
            'item_count' => count($items),
        ]);
        foreach ($items as $item) {
            self::detect_actor_face_anchor((string)$item['image'], (string)$item['output'], (int)$item['actor_index']);
        }
        return [];
    }

    $reports = [];
    foreach ($items as $item) {
        $actor_index = (int)$item['actor_index'];
        $key = (string)$actor_index;
        $report = is_array($batch['actors'][$key] ?? null) ? $batch['actors'][$key] : ['ok' => false, 'reason' => 'missing_batch_actor'];
        if (empty($report['ok'])) {
            error_log('CMSG ACTOR FACE ANCHOR FALLBACK method=alpha_geometry actor_index=' . intval($actor_index) . ' reason=' . sanitize_text_field((string)($report['reason'] ?? 'unknown')));
            $fallback = self::actor_alpha_face_anchor_fallback((string)$item['image'], $actor_index);
            $report = array_merge($report, $fallback, [
                'ok' => true,
                'method' => 'alpha_geometry_fallback',
                'fallback_used' => true,
            ]);
        } elseif (($report['method'] ?? '') === 'insightface') {
            error_log('CMSG ACTOR FACE ANCHOR method=insightface actor_index=' . intval($actor_index));
        } elseif (($report['method'] ?? '') === 'opencv_haar') {
            error_log('CMSG ACTOR FACE ANCHOR FALLBACK method=opencv_haar actor_index=' . intval($actor_index));
        }

        self::poster_actor_trace('FILE_WRITE_BEGIN', 'Writing batched actor face-anchor report.', [
            'actor_index' => $actor_index,
            'output' => self::poster_actor_trace_file_state((string)$item['output']),
        ]);
        $written = file_put_contents((string)$item['output'], wp_json_encode($report, JSON_PRETTY_PRINT));
        @chmod((string)$item['output'], 0664);
        self::poster_actor_trace('FILE_WRITE_END', 'Batched actor face-anchor report write completed.', [
            'actor_index' => $actor_index,
            'output' => self::poster_actor_trace_file_state((string)$item['output']),
            'written' => $written,
        ]);
        $reports[$key] = $report;

        $alpha_report_path = (string)($item['alpha_report'] ?? '');
        if ($alpha_report_path !== '' && file_exists($alpha_report_path)) {
            self::poster_actor_trace('FILE_READ_BEGIN', 'Reading actor alpha report to merge face anchor.', [
                'actor_index' => $actor_index,
                'alpha_report_path' => self::poster_actor_trace_file_state($alpha_report_path),
            ]);
            $alpha_report = json_decode((string)file_get_contents($alpha_report_path), true);
            self::poster_actor_trace('FILE_READ_END', 'Actor alpha report read for face-anchor merge.', [
                'actor_index' => $actor_index,
                'alpha_report_path' => self::poster_actor_trace_file_state($alpha_report_path),
                'json_ok' => is_array($alpha_report),
            ]);
            if (is_array($alpha_report)) {
                $alpha_report['face_anchor'] = $report;
                self::write_actor_alpha_report($alpha_report_path, $alpha_report);
            }
        }
    }

    self::poster_actor_trace('FACE_ANCHOR_BATCH_PATH_EXIT', 'detect_actor_face_anchors_batch() completed.', [
        'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
        'report_count' => count($reports),
    ]);
    return $reports;
}

private static function actor_alpha_face_anchor_fallback($path, $actor_index) {
    $geo = self::actor_alpha_geometry($path);
    if (empty($geo['ok'])) {
        return [
            'actor_index' => (int)$actor_index,
            'image_width' => 0,
            'image_height' => 0,
            'face_bbox' => null,
            'face_center_norm' => ['x' => 0.50, 'y' => 0.22],
            'reason' => 'alpha_geometry_unavailable',
        ];
    }

    $bbox = $geo['alpha_bbox'];
    $fw = max(1, (int)round($bbox['w'] * 0.42));
    $fh = max(1, (int)round($bbox['h'] * 0.30));
    $fx = (int)round($bbox['x'] + (($bbox['w'] - $fw) / 2));
    $fy = (int)round($bbox['y'] + ($bbox['h'] * 0.08));
    return [
        'actor_index' => (int)$actor_index,
        'image_width' => (int)$geo['width'],
        'image_height' => (int)$geo['height'],
        'face_bbox' => ['x' => $fx, 'y' => $fy, 'w' => $fw, 'h' => $fh],
        'face_center' => ['x' => $fx + ($fw / 2), 'y' => $fy + ($fh / 2)],
        'face_center_norm' => [
            'x' => ($fx + ($fw / 2)) / max(1, (int)$geo['width']),
            'y' => ($fy + ($fh / 2)) / max(1, (int)$geo['height']),
        ],
        'eye_line_y' => $fy + ($fh * 0.38),
        'forehead_top_y' => $fy + ($fh * 0.08),
        'chin_y' => $fy + $fh,
        'reason' => 'fallback_estimate_from_alpha_bbox',
    ];
}

private static function actor_alpha_geometry($path) {
    if (!function_exists('imagecreatefrompng') || !file_exists((string)$path)) return ['ok' => false, 'reason' => 'missing_png'];
    $img = @imagecreatefrompng($path);
    if (!$img) return ['ok' => false, 'reason' => 'unreadable_png'];
    $w = imagesx($img);
    $h = imagesy($img);
    $min_x = $w;
    $min_y = $h;
    $max_x = -1;
    $max_y = -1;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgba = imagecolorat($img, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;
            if ($alpha < 118) {
                $min_x = min($min_x, $x);
                $min_y = min($min_y, $y);
                $max_x = max($max_x, $x);
                $max_y = max($max_y, $y);
            }
        }
    }
    imagedestroy($img);
    if ($max_x < $min_x || $max_y < $min_y) return ['ok' => false, 'reason' => 'empty_alpha_bbox'];
    return [
        'ok' => true,
        'width' => $w,
        'height' => $h,
        'alpha_bbox' => ['x' => $min_x, 'y' => $min_y, 'w' => $max_x - $min_x + 1, 'h' => $max_y - $min_y + 1],
    ];
}

private static function load_actor_face_anchor($path) {
    if (!is_string($path) || $path === '' || !file_exists($path)) return [];
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

private static function normalize_actor_alpha_matte($src, $dest, $actor_index = 0) {
    $report = [
        'ok' => false,
        'actor_index' => (int)$actor_index,
        'src' => $src,
        'dest' => $dest,
        'width' => 0,
        'height' => 0,
        'transparent_ratio' => 0.0,
        'partial_alpha_ratio' => 0.0,
        'opaque_ratio' => 0.0,
        'interior_partial_ratio' => 1.0,
        'alpha_bbox' => null,
        'edge_touch' => [
            'left' => false,
            'top' => false,
            'right' => false,
            'bottom' => false,
        ],
        'reason' => '',
    ];

    if (!function_exists('imagecreatefrompng') || !is_string($src) || !file_exists($src)) {
        $report['reason'] = 'missing_source';
        error_log('CMSG ACTOR MATTE QUALITY FAIL: missing_source actor_index=' . intval($actor_index) . ' src=' . (string)$src);
        return $report;
    }

    $img = @imagecreatefrompng($src);
    if (!$img) {
        $report['reason'] = 'unreadable_png';
        error_log('CMSG ACTOR MATTE QUALITY FAIL: unreadable_png actor_index=' . intval($actor_index) . ' src=' . (string)$src);
        return $report;
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $report['width'] = $w;
    $report['height'] = $h;
    if ($w <= 0 || $h <= 0) {
        imagedestroy($img);
        $report['reason'] = 'empty_dimensions';
        error_log('CMSG ACTOR MATTE QUALITY FAIL: empty_dimensions actor_index=' . intval($actor_index));
        return $report;
    }

    $min_x = $w;
    $min_y = $h;
    $max_x = -1;
    $max_y = -1;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgba = imagecolorat($img, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;
            $opacity = (int)round((127 - $alpha) * 255 / 127);
            if ($opacity > 20) {
                $min_x = min($min_x, $x);
                $min_y = min($min_y, $y);
                $max_x = max($max_x, $x);
                $max_y = max($max_y, $y);
            }
        }
    }

    if ($max_x < $min_x || $max_y < $min_y) {
        imagedestroy($img);
        $report['reason'] = 'no_nontransparent_bbox';
        error_log('CMSG ACTOR MATTE QUALITY FAIL: no_nontransparent_bbox actor_index=' . intval($actor_index));
        return $report;
    }

    $report['alpha_bbox'] = [
        'x' => $min_x,
        'y' => $min_y,
        'w' => $max_x - $min_x + 1,
        'h' => $max_y - $min_y + 1,
    ];
    $report['edge_touch'] = [
        'left' => $min_x <= 0,
        'top' => $min_y <= 0,
        'right' => $max_x >= $w - 1,
        'bottom' => $max_y >= $h - 1,
    ];

    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $clear = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefilledrectangle($out, 0, 0, $w, $h, $clear);

    $transparent = 0;
    $partial = 0;
    $opaque = 0;
    $interior_total = 0;
    $interior_partial = 0;
    $radius = max(3, (int)round(min($report['alpha_bbox']['w'], $report['alpha_bbox']['h']) * 0.015));

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgba = imagecolorat($img, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;
            $opacity = (int)round((127 - $alpha) * 255 / 127);
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;

            $is_interior = false;
            if ($opacity > 20 && $x - $radius >= 0 && $x + $radius < $w && $y - $radius >= 0 && $y + $radius < $h) {
                $neighbors = [
                    imagecolorat($img, $x - $radius, $y),
                    imagecolorat($img, $x + $radius, $y),
                    imagecolorat($img, $x, $y - $radius),
                    imagecolorat($img, $x, $y + $radius),
                ];
                $is_interior = true;
                foreach ($neighbors as $neighbor_rgba) {
                    $neighbor_alpha = ($neighbor_rgba >> 24) & 0x7F;
                    $neighbor_opacity = (int)round((127 - $neighbor_alpha) * 255 / 127);
                    if ($neighbor_opacity <= 20) {
                        $is_interior = false;
                        break;
                    }
                }
            }

            if ($opacity <= 20) {
                $new_alpha = 127;
            } elseif ($is_interior || $opacity >= 110) {
                $new_alpha = 0;
            } else {
                $new_alpha = max(1, min(126, (int)round(127 - ($opacity / 255) * 127)));
            }

            if ($new_alpha >= 126) {
                $transparent++;
            } elseif ($new_alpha > 0) {
                $partial++;
            } else {
                $opaque++;
            }

            if ($is_interior) {
                $interior_total++;
                if ($new_alpha > 0) {
                    $interior_partial++;
                }
            }

            $color = imagecolorallocatealpha($out, $r, $g, $b, $new_alpha);
            imagesetpixel($out, $x, $y, $color);
        }
    }

    $dir = dirname($dest);
    if (!is_dir($dir)) wp_mkdir_p($dir);
    $saved = imagepng($out, $dest, 6);
    imagedestroy($out);
    imagedestroy($img);

    $total = max(1, $w * $h);
    $report['transparent_ratio'] = $transparent / $total;
    $report['partial_alpha_ratio'] = $partial / $total;
    $report['opaque_ratio'] = $opaque / $total;
    $report['interior_partial_ratio'] = $interior_total > 0 ? $interior_partial / $interior_total : 1.0;
    $report['ok'] = $saved
        && file_exists($dest)
        && filesize($dest) > 0
        && $report['partial_alpha_ratio'] <= 0.12
        && $report['opaque_ratio'] >= 0.20
        && $report['interior_partial_ratio'] <= 0.05
        && !empty($report['alpha_bbox']);
    $report['reason'] = $report['ok'] ? 'actor_matte_normalized' : 'actor_matte_quality_failed';

    if ($report['ok']) {
        @chmod($dest, 0664);
        error_log('CMSG ACTOR MATTE NORMALIZED actor_index=' . intval($actor_index) . ' src=' . sanitize_text_field($src) . ' dest=' . sanitize_text_field($dest) . ' partial_alpha_ratio=' . $report['partial_alpha_ratio'] . ' opaque_ratio=' . $report['opaque_ratio'] . ' interior_partial_ratio=' . $report['interior_partial_ratio']);
    } else {
        $reason = $report['interior_partial_ratio'] > 0.05 ? 'translucent_subject_interior' : 'actor_matte_quality_failed';
        $report['reason'] = $reason;
        error_log('CMSG ACTOR MATTE QUALITY FAIL: ' . $reason . ' actor_index=' . intval($actor_index) . ' src=' . sanitize_text_field($src) . ' report=' . wp_json_encode($report));
    }

    return $report;
}

private static function trim_and_pad_actor_cutout($src, $dest, $padding_ratio = 0.08) {
    if (!function_exists('imagecreatefrompng') || !is_string($src) || !file_exists($src)) {
        return false;
    }

    $analysis = self::analyze_actor_layer($src);
    if (empty($analysis['bounds'])) {
        return false;
    }

    $img = @imagecreatefrompng($src);
    if (!$img) {
        return false;
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $bounds = $analysis['bounds'];
    $touches_left = (int)$bounds['x'] <= 0;
    $touches_top = (int)$bounds['y'] <= 0;
    $touches_right = ((int)$bounds['x'] + (int)$bounds['w']) >= $w;
    $touches_bottom = ((int)$bounds['y'] + (int)$bounds['h']) >= $h;
    $edge_repaired = $touches_left || $touches_top || $touches_right || $touches_bottom;

    $pad_x = max(18, (int)round($bounds['w'] * (float)$padding_ratio));
    $pad_y = max(18, (int)round($bounds['h'] * (float)$padding_ratio));
    $pad_top = max($pad_y, (int)round($bounds['h'] * 0.12));

    if ($edge_repaired) {
        $pad_x = max($pad_x, (int)round($bounds['w'] * 0.16));
        $pad_y = max($pad_y, (int)round($bounds['h'] * 0.14));
        $pad_top = max($pad_top, (int)round($bounds['h'] * 0.20));
    }

    $src_x = max(0, (int)$bounds['x']);
    $src_y = max(0, (int)$bounds['y']);
    $src_w = min($w - $src_x, (int)$bounds['w']);
    $src_h = min($h - $src_y, (int)$bounds['h']);

    $out_w = max(1, $src_w + ($pad_x * 2));
    $out_h = max(1, $src_h + $pad_top + $pad_y);
    $out = imagecreatetruecolor($out_w, $out_h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $clear = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefilledrectangle($out, 0, 0, $out_w, $out_h, $clear);
    imagecopy($out, $img, $pad_x, $pad_top, $src_x, $src_y, $src_w, $src_h);

    $dir = dirname($dest);
    if (!is_dir($dir)) wp_mkdir_p($dir);
    $saved = imagepng($out, $dest, 6);
    imagedestroy($out);
    imagedestroy($img);

    if ($saved) {
        @chmod($dest, 0664);
        if ($edge_repaired) {
            error_log('CMSG ACTOR CUTOUT EDGE REPAIRED src=' . sanitize_text_field($src) . ' dest=' . sanitize_text_field($dest) . ' bounds=' . wp_json_encode($bounds));
        }
    }

    return $saved && file_exists($dest) && filesize($dest) > 0;
}

private static function warn_actor_cutout_edge_touch($layer_path, $actor_index, $source_path = '', $analysis = null) {
    $analysis = is_array($analysis) ? $analysis : self::analyze_actor_layer($layer_path);
    if (empty($analysis['bounds']) || empty($analysis['width']) || empty($analysis['height'])) {
        return;
    }

    $bounds = $analysis['bounds'];
    $touches_left = (int)($bounds['x'] ?? 1) <= 0;
    $touches_top = (int)($bounds['y'] ?? 1) <= 0;
    $touches_right = ((int)($bounds['x'] ?? 0) + (int)($bounds['w'] ?? 0)) >= (int)$analysis['width'];
    $touches_bottom = ((int)($bounds['y'] ?? 0) + (int)($bounds['h'] ?? 0)) >= (int)$analysis['height'];

    if ($touches_left && $touches_top && $touches_right && $touches_bottom) {
        error_log('CMSG ACTOR CUTOUT EDGE TOUCH WARNING actor_index=' . intval($actor_index) . ' layer=' . sanitize_text_field($layer_path) . ' source=' . sanitize_text_field($source_path) . ' bounds=' . wp_json_encode($bounds));
    }
}

private static function analyze_actor_layer($path) {
    $started = microtime(true);
    self::poster_actor_trace('ANALYZE_ACTOR_LAYER_BEGIN', 'Entered analyze_actor_layer().', [
        'path' => self::poster_actor_trace_file_state($path),
        'gd_available' => function_exists('imagecreatefrompng'),
    ]);
    $report = [
        'ok' => false,
        'path' => $path,
        'width' => 0,
        'height' => 0,
        'has_alpha' => false,
        'transparent_pixels' => 0,
        'opaque_pixels' => 0,
        'visible_pixels' => 0,
        'edge_opaque_ratio' => 0,
        'edge_black_or_white_ratio' => 0,
        'bounds' => null,
        'error' => '',
    ];

    if (!function_exists('imagecreatefrompng') || !file_exists($path)) {
        $report['error'] = 'missing_or_unreadable';
        self::poster_actor_trace('ANALYZE_ACTOR_LAYER_END', 'analyze_actor_layer() failed before PNG load.', [
            'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
            'report' => $report,
            'path' => self::poster_actor_trace_file_state($path),
        ]);
        return $report;
    }

    self::poster_actor_trace('FILE_READ_BEGIN', 'Loading actor layer PNG for analysis.', [
        'path' => self::poster_actor_trace_file_state($path),
    ]);
    $img = @imagecreatefrompng($path);
    if (!$img) {
        $report['error'] = 'not_readable_png';
        self::poster_actor_trace('FILE_READ_END', 'Actor layer PNG load failed.', [
            'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
            'report' => $report,
            'path' => self::poster_actor_trace_file_state($path),
        ]);
        return $report;
    }
    self::poster_actor_trace('FILE_READ_END', 'Actor layer PNG loaded for analysis.', [
        'path' => self::poster_actor_trace_file_state($path),
    ]);

    $w = imagesx($img);
    $h = imagesy($img);
    $report['width'] = $w;
    $report['height'] = $h;
    if ($w <= 0 || $h <= 0) {
        imagedestroy($img);
        $report['error'] = 'empty_dimensions';
        self::poster_actor_trace('ANALYZE_ACTOR_LAYER_END', 'analyze_actor_layer() found empty dimensions.', [
            'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
            'report' => $report,
        ]);
        return $report;
    }

    $min_x = $w;
    $min_y = $h;
    $max_x = -1;
    $max_y = -1;
    $edge_total = 0;
    $edge_opaque = 0;
    $edge_matte = 0;

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgba = imagecolorat($img, $x, $y);
            $a = ($rgba >> 24) & 0x7F;
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;
            $is_edge = ($x < 8 || $y < 8 || $x >= $w - 8 || $y >= $h - 8);

            if ($a > 8) {
                $report['transparent_pixels']++;
                $report['has_alpha'] = true;
            }

            if ($a < 116) {
                $report['visible_pixels']++;
                if ($a < 16) {
                    $report['opaque_pixels']++;
                }
                $min_x = min($min_x, $x);
                $min_y = min($min_y, $y);
                $max_x = max($max_x, $x);
                $max_y = max($max_y, $y);
            }

            if ($is_edge) {
                $edge_total++;
                if ($a < 24) {
                    $edge_opaque++;
                    $is_black = ($r < 18 && $g < 18 && $b < 18);
                    $is_white = ($r > 238 && $g > 238 && $b > 238);
                    if ($is_black || $is_white) {
                        $edge_matte++;
                    }
                }
            }
        }
    }

    if ($max_x >= $min_x && $max_y >= $min_y) {
        $report['bounds'] = [
            'x' => $min_x,
            'y' => $min_y,
            'w' => $max_x - $min_x + 1,
            'h' => $max_y - $min_y + 1,
        ];
    }

    $report['edge_opaque_ratio'] = $edge_total > 0 ? $edge_opaque / $edge_total : 0;
    $report['edge_black_or_white_ratio'] = $edge_opaque > 0 ? $edge_matte / $edge_opaque : 0;

    $subject_area = !empty($report['bounds']) ? ($report['bounds']['w'] * $report['bounds']['h']) : 0;
    $image_area = max(1, $w * $h);
    $subject_ratio = $subject_area / $image_area;
    $report['ok'] = $report['has_alpha']
        && $report['transparent_pixels'] > 0
        && $report['visible_pixels'] > 100
        && !empty($report['bounds'])
        && $subject_ratio > 0.02
        && $report['edge_opaque_ratio'] < 0.72
        && $report['edge_black_or_white_ratio'] < 0.68;

    if (!$report['ok']) {
        $report['error'] = 'invalid_alpha_or_matte';
    }

    imagedestroy($img);
    self::poster_actor_trace('ANALYZE_ACTOR_LAYER_END', 'analyze_actor_layer() completed.', [
        'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
        'report' => $report,
    ]);
    return $report;
}

private static function crop_actor_layer_to_subject($src, $out, $analysis = null) {
    if (!function_exists('imagecreatefrompng') || !function_exists('imagecopy')) return false;
    if (!file_exists($src)) return false;

    $analysis = is_array($analysis) ? $analysis : self::analyze_actor_layer($src);
    if (empty($analysis['bounds'])) return false;

    $img = @imagecreatefrompng($src);
    if (!$img) return false;

    $w = imagesx($img);
    $h = imagesy($img);
    $bounds = $analysis['bounds'];
    $pad_x = max(18, (int)round($bounds['w'] * 0.08));
    $pad_top = max(28, (int)round($bounds['h'] * 0.10));
    $pad_bottom = max(20, (int)round($bounds['h'] * 0.08));
    $x = max(0, (int)$bounds['x'] - $pad_x);
    $y = max(0, (int)$bounds['y'] - $pad_top);
    $right = min($w - 1, (int)$bounds['x'] + (int)$bounds['w'] - 1 + $pad_x);
    $bottom = min($h - 1, (int)$bounds['y'] + (int)$bounds['h'] - 1 + $pad_bottom);
    $crop_w = max(1, $right - $x + 1);
    $crop_h = max(1, $bottom - $y + 1);

    $crop = imagecreatetruecolor($crop_w, $crop_h);
    imagealphablending($crop, false);
    imagesavealpha($crop, true);
    $transparent = imagecolorallocatealpha($crop, 0, 0, 0, 127);
    imagefilledrectangle($crop, 0, 0, $crop_w, $crop_h, $transparent);
    imagecopy($crop, $img, 0, 0, $x, $y, $crop_w, $crop_h);

    self::feather_layer_edges($crop, 3, 0.92);
    imagepng($crop, $out, 6);
    imagedestroy($crop);
    imagedestroy($img);
    @chmod($out, 0664);
    return file_exists($out) && filesize($out) > 0;
}

private static function feather_layer_edges($img, $edge_px = 18, $bottom_start_ratio = 0.84) {
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w <= 0 || $h <= 0) return;

    $edge_px = max(1, min(4, (int)$edge_px));
    $bottom_start = (int)round($h * (float)$bottom_start_ratio);
    for ($y = 0; $y < $h; $y++) {
        $edge_y = min($y, $h - 1 - $y);
        $bottom_mask = 1.0;
        if ($y > $bottom_start) {
            $bottom_mask = max(0.0, 1.0 - (($y - $bottom_start) / max(1, $h - $bottom_start)));
        }
        for ($x = 0; $x < $w; $x++) {
            $edge_x = min($x, $w - 1 - $x);
            $edge = min(1.0, min($edge_x / $edge_px, $edge_y / $edge_px));
            $mask = min($edge, max(0.18, $bottom_mask));
            if ($mask >= 0.995) continue;

            $rgba = imagecolorat($img, $x, $y);
            $a = ($rgba >> 24) & 0x7F;
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;
            $final_a = 127 - (int)round((127 - $a) * max(0.0, min(1.0, $mask)));
            $color = imagecolorallocatealpha($img, $r, $g, $b, max(0, min(127, $final_a)));
            imagesetpixel($img, $x, $y, $color);
        }
    }
}

private static function create_masked_portrait_layer($asset_path, $out) {
    $started = microtime(true);
    self::poster_actor_trace('RECOVERY_BEGIN', 'Entered create_masked_portrait_layer().', [
        'source' => self::poster_actor_trace_file_state($asset_path),
        'out' => self::poster_actor_trace_file_state($out),
        'gd_available' => function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled'),
    ]);
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) {
        self::poster_actor_trace('RECOVERY_END', 'GD functions unavailable for masked portrait fallback.', [
            'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
            'source' => self::poster_actor_trace_file_state($asset_path),
            'out' => self::poster_actor_trace_file_state($out),
        ]);
        return false;
    }

    self::poster_actor_trace('FILE_READ_BEGIN', 'Loading source image for masked portrait fallback.', [
        'source' => self::poster_actor_trace_file_state($asset_path),
    ]);
    $actor = self::gd_load_image($asset_path);
    if (!$actor) {
        self::poster_actor_trace('FILE_READ_END', 'Source image load failed for masked portrait fallback.', [
            'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
            'source' => self::poster_actor_trace_file_state($asset_path),
            'out' => self::poster_actor_trace_file_state($out),
        ]);
        return false;
    }
    self::poster_actor_trace('FILE_READ_END', 'Source image loaded for masked portrait fallback.', [
        'source' => self::poster_actor_trace_file_state($asset_path),
    ]);

    $layer_w = 720;
    $layer_h = 1125;
    $actor_w = imagesx($actor);
    $actor_h = imagesy($actor);
    if ($actor_w <= 0 || $actor_h <= 0) {
        imagedestroy($actor);
        self::poster_actor_trace('RECOVERY_END', 'Source image has empty dimensions for masked portrait fallback.', [
            'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
            'source' => self::poster_actor_trace_file_state($asset_path),
            'out' => self::poster_actor_trace_file_state($out),
            'actor_w' => $actor_w,
            'actor_h' => $actor_h,
        ]);
        return false;
    }

    $layer = imagecreatetruecolor($layer_w, $layer_h);
    imagealphablending($layer, false);
    imagesavealpha($layer, true);
    $transparent = imagecolorallocatealpha($layer, 0, 0, 0, 127);
    imagefilledrectangle($layer, 0, 0, $layer_w, $layer_h, $transparent);

    $target_ratio = $layer_w / $layer_h;
    $source_ratio = $actor_w / max(1, $actor_h);
    $src_x = 0;
    $src_y = 0;
    $src_w = $actor_w;
    $src_h = $actor_h;

    if ($source_ratio > $target_ratio) {
        $src_w = (int)round($actor_h * $target_ratio);
        $src_x = (int)round(($actor_w - $src_w) / 2);
    } else {
        $src_h = (int)round($actor_w / $target_ratio);
        $src_y = (int)round(($actor_h - $src_h) * 0.12);
    }

    $src_w = max(1, min($src_w, $actor_w));
    $src_h = max(1, min($src_h, $actor_h));
    $src_x = max(0, min($src_x, $actor_w - $src_w));
    $src_y = max(0, min($src_y, $actor_h - $src_h));

    self::poster_actor_trace('RECOVERY_RESAMPLE_BEGIN', 'Resampling source image into masked portrait fallback layer.', [
        'source' => self::poster_actor_trace_file_state($asset_path),
        'out' => self::poster_actor_trace_file_state($out),
        'actor_w' => $actor_w,
        'actor_h' => $actor_h,
        'src_x' => $src_x,
        'src_y' => $src_y,
        'src_w' => $src_w,
        'src_h' => $src_h,
        'layer_w' => $layer_w,
        'layer_h' => $layer_h,
    ]);
    imagecopyresampled($layer, $actor, 0, 0, $src_x, $src_y, $layer_w, $layer_h, $src_w, $src_h);
    self::poster_actor_trace('RECOVERY_RESAMPLE_END', 'Masked portrait fallback layer resampling completed.', [
        'source' => self::poster_actor_trace_file_state($asset_path),
        'out' => self::poster_actor_trace_file_state($out),
    ]);
    imagedestroy($actor);

    for ($py = 0; $py < $layer_h; $py++) {
        $edge_y = min($py, $layer_h - 1 - $py);
        $bottom = 1.0;
        $bottom_start = (int)round($layer_h * 0.74);
        if ($py > $bottom_start) {
            $bottom = max(0.0, 1.0 - (($py - $bottom_start) / max(1, $layer_h - $bottom_start)));
        }

        for ($px = 0; $px < $layer_w; $px++) {
            $edge_x = min($px, $layer_w - 1 - $px);
            $edge = min(1.0, min($edge_x / 38, $edge_y / 38));
            $radius = 76;
            $corner_alpha = 1.0;
            $corner_x = $px < $radius ? $radius : ($px > $layer_w - $radius ? $layer_w - $radius : $px);
            $corner_y = $py < $radius ? $radius : ($py > $layer_h - $radius ? $layer_h - $radius : $py);
            $dist = sqrt(pow($px - $corner_x, 2) + pow($py - $corner_y, 2));
            if ($dist > $radius) {
                $corner_alpha = 0.0;
            } elseif ($dist > $radius - 20) {
                $corner_alpha = max(0.0, ($radius - $dist) / 20);
            }
            $mask = max(0.0, min(1.0, $edge * $corner_alpha * max(0.16, $bottom)));
            $rgba = imagecolorat($layer, $px, $py);
            $a = ($rgba >> 24) & 0x7F;
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;
            $r = (int)min(255, $r * 1.03);
            $g = (int)min(255, $g * 0.99);
            $b = (int)min(255, $b * 0.92);
            $final_a = 127 - (int)round((127 - $a) * $mask);
            $color = imagecolorallocatealpha($layer, $r, $g, $b, max(0, min(127, $final_a)));
            imagesetpixel($layer, $px, $py, $color);
        }
    }

    self::poster_actor_trace('FILE_WRITE_BEGIN', 'Writing masked portrait fallback layer.', [
        'out' => self::poster_actor_trace_file_state($out),
    ]);
    $written = imagepng($layer, $out, 6);
    imagedestroy($layer);
    @chmod($out, 0664);
    $ok = file_exists($out) && filesize($out) > 0;
    self::poster_actor_trace('FILE_WRITE_END', 'Masked portrait fallback layer write completed.', [
        'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
        'out' => self::poster_actor_trace_file_state($out),
        'imagepng_result' => $written,
        'ok' => $ok,
    ]);
    self::poster_actor_trace('RECOVERY_END', 'create_masked_portrait_layer() completed.', [
        'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
        'source' => self::poster_actor_trace_file_state($asset_path),
        'out' => self::poster_actor_trace_file_state($out),
        'ok' => $ok,
    ]);
    return $ok;
}

private static function gd_copy_alpha_resampled($dst, $src, $dst_x, $dst_y, $dst_w, $dst_h) {
    $src_w = imagesx($src);
    $src_h = imagesy($src);
    if ($src_w <= 0 || $src_h <= 0 || $dst_w <= 0 || $dst_h <= 0) return false;

    $tmp = imagecreatetruecolor($dst_w, $dst_h);
    imagealphablending($tmp, false);
    imagesavealpha($tmp, true);
    $transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
    imagefilledrectangle($tmp, 0, 0, $dst_w, $dst_h, $transparent);
    imagecopyresampled($tmp, $src, 0, 0, 0, 0, $dst_w, $dst_h, $src_w, $src_h);

    imagealphablending($dst, true);
    imagecopy($dst, $tmp, $dst_x, $dst_y, 0, 0, $dst_w, $dst_h);
    imagedestroy($tmp);
    return true;
}

private static function resolve_actor_render_source($placement_info) {
    foreach (['final_cutout_path', 'cutout_path', 'layer_path'] as $key) {
        $path = (string)($placement_info[$key] ?? '');
        if ($path !== '' && file_exists($path) && filesize($path) > 0) {
            return [
                'ok' => true,
                'path' => $path,
                'source_key' => $key,
                'stage' => $key === 'final_cutout_path' ? 'final_matte_cutout' : ($key === 'cutout_path' ? 'prepared_cutout' : 'legacy_layer'),
            ];
        }
    }
    return ['ok' => false, 'path' => '', 'source_key' => '', 'stage' => 'missing'];
}

private static function average_image_luma($img, $sample_alpha = true) {
    if (!$img) return null;
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w <= 0 || $h <= 0) return null;
    $step_x = max(1, (int)floor($w / 24));
    $step_y = max(1, (int)floor($h / 24));
    $sum = 0.0;
    $count = 0;
    for ($y = 0; $y < $h; $y += $step_y) {
        for ($x = 0; $x < $w; $x += $step_x) {
            $rgba = imagecolorat($img, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;
            if ($sample_alpha && $alpha > 24) continue;
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;
            $sum += ($r * 0.2126) + ($g * 0.7152) + ($b * 0.0722);
            $count++;
        }
    }
    return $count > 0 ? ($sum / $count) : null;
}

private static function average_background_luma_near_slot($canvas, $x, $y, $w, $h) {
    if (!$canvas) return null;
    $canvas_w = imagesx($canvas);
    $canvas_h = imagesy($canvas);
    $left = max(0, min($canvas_w - 1, (int)$x));
    $top = max(0, min($canvas_h - 1, (int)$y));
    $right = max($left, min($canvas_w - 1, (int)($x + $w)));
    $bottom = max($top, min($canvas_h - 1, (int)($y + $h)));
    $step_x = max(1, (int)floor(max(1, $right - $left + 1) / 16));
    $step_y = max(1, (int)floor(max(1, $bottom - $top + 1) / 16));
    $sum = 0.0;
    $count = 0;
    for ($yy = $top; $yy <= $bottom; $yy += $step_y) {
        for ($xx = $left; $xx <= $right; $xx += $step_x) {
            $rgb = imagecolorat($canvas, $xx, $yy);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $sum += ($r * 0.2126) + ($g * 0.7152) + ($b * 0.0722);
            $count++;
        }
    }
    return $count > 0 ? ($sum / $count) : null;
}

private static function harmonize_actor_to_background($actor, $canvas, $x, $y, $w, $h) {
    $actor_luma = self::average_image_luma($actor, true);
    $bg_luma = self::average_background_luma_near_slot($canvas, $x, $y, $w, $h);
    if ($actor_luma === null || $bg_luma === null) return $actor;

    $delta = max(-14, min(14, (int)round(($bg_luma - $actor_luma) * 0.18)));
    if (abs($delta) < 3) return $actor;

    imagefilter($actor, IMG_FILTER_BRIGHTNESS, $delta);
    return $actor;
}

private static function actor_edge_feather_pixels($target_w, $target_h) {
    $min = min((int)$target_w, (int)$target_h);
    if ($min < 220) return 1;
    if ($min < 520) return 2;
    return 3;
}

private static function actor_target_face_anchor($slot, $variant, $canvas_w, $canvas_h) {
    $variant = sanitize_key($variant ?: 'vertical');
    $role = self::normalize_campaign_actor_role($slot['role'] ?? 'supporting', (int)($slot['actor_index'] ?? 0));
    $slot_x = (float)($slot['x'] ?? 0.50);
    $slot_y = (float)($slot['y'] ?? 0.20);
    $eye_y = $slot_y + 0.08;

    if ($variant === 'banner') {
        if ($role === 'lead') $eye_y = max(0.20, min(0.38, $slot_y + 0.08));
        elseif ($role === 'second_lead') $eye_y = max(0.22, min(0.42, $slot_y + 0.09));
        else $eye_y = max(0.24, min(0.48, $slot_y + 0.10));
    } else {
        if ($role === 'lead') $eye_y = max(0.22, min(0.30, $slot_y + 0.08));
        elseif ($role === 'second_lead') $eye_y = max(0.25, min(0.34, $slot_y + 0.09));
        elseif ($role === 'supporting') $eye_y = max(0.32, min(0.48, $slot_y + 0.10));
        else $eye_y = max(0.40, min(0.58, $slot_y + 0.10));
    }

    return [
        'x' => (int)round($canvas_w * $slot_x),
        'eye_y' => (int)round($canvas_h * $eye_y),
        'x_norm' => $slot_x,
        'eye_y_norm' => $eye_y,
        'role' => $role,
    ];
}

private static function composite_campaign_placement_map($background_path, $placement_map, $variant, $composite_path, $manifest = []) {
    if (!function_exists('imagecreatefrompng') || !function_exists('imagecopyresampled')) return 0;
    if (!file_exists($background_path) || empty($placement_map) || !is_array($placement_map)) return 0;

    $variant = sanitize_key($variant ?: 'vertical');
    $expected_actor_count = count($placement_map);
    $audit = self::campaign_render_audit_init([], $variant);
    $audit['expected_actor_count'] = $expected_actor_count;
    $audit['actor_ids'] = array_keys($placement_map);
    $audit['actor_indexes'] = [];
    $audit['layer_paths'] = [];
    $audit['placement_map'] = $placement_map;

    $canvas = @imagecreatefrompng($background_path);
    if (!$canvas) return 0;

    imagealphablending($canvas, true);
    imagesavealpha($canvas, true);

    $canvas_w = imagesx($canvas);
    $canvas_h = imagesy($canvas);
    $placements = array_values($placement_map);
    usort($placements, function($a, $b) {
        return (int)($a['z_index'] ?? 0) <=> (int)($b['z_index'] ?? 0);
    });

    $placed = 0;
    $rendered_actor_ids = [];
    $rendered_actor_indexes = [];
    $rendered_layer_paths = [];
    $placement_report = [];

    foreach ($placements as $placement_info) {
        $actor_id = sanitize_key($placement_info['actor_id'] ?? '');
        $actor_index = (int)($placement_info['actor_index'] ?? -1);
        $source_selection = self::resolve_actor_render_source($placement_info);
        $layer_path = !empty($source_selection['ok']) ? (string)$source_selection['path'] : '';
        $real_layer_path = ($layer_path !== '' && file_exists($layer_path)) ? realpath($layer_path) : '';
        $layer_key = $real_layer_path ?: $layer_path;

        if ($actor_id === '' || $actor_index < 0 || $layer_path === '' || !file_exists($layer_path)) {
            $audit['duplicates'][] = ['type' => 'invalid_placement_render_input', 'actor_id' => $actor_id, 'actor_index' => $actor_index, 'layer_path' => $layer_path];
            $audit['ok'] = false;
            error_log('CMSG LAYERED QUALITY FAIL: invalid_placement_render_input variant=' . $variant . ' actor_id=' . $actor_id . ' actor_index=' . $actor_index . ' layer=' . $layer_path);
            break;
        }
        if (isset($rendered_actor_ids[$actor_id])) {
            $audit['duplicates'][] = ['type' => 'duplicate_placement_actor_id', 'actor_id' => $actor_id, 'actor_index' => $actor_index, 'layer_path' => $layer_path];
            $audit['ok'] = false;
            error_log('CMSG LAYERED QUALITY FAIL: duplicate_placement_actor_id variant=' . $variant . ' actor_id=' . $actor_id);
            break;
        }
        if (isset($rendered_actor_indexes[$actor_index])) {
            $audit['duplicates'][] = ['type' => 'duplicate_placement_actor_index', 'actor_id' => $actor_id, 'actor_index' => $actor_index, 'layer_path' => $layer_path];
            $audit['ok'] = false;
            error_log('CMSG LAYERED QUALITY FAIL: duplicate_placement_actor_index variant=' . $variant . ' actor_index=' . $actor_index . ' actor_id=' . $actor_id);
            break;
        }
        if (isset($rendered_layer_paths[$layer_key])) {
            $audit['duplicates'][] = ['type' => 'duplicate_placement_layer_path', 'actor_id' => $actor_id, 'actor_index' => $actor_index, 'layer_path' => $layer_path];
            $audit['ok'] = false;
            error_log('CMSG LAYERED QUALITY FAIL: duplicate_placement_layer_path variant=' . $variant . ' actor_id=' . $actor_id . ' layer=' . $layer_path);
            break;
        }

        $actor = @imagecreatefrompng($layer_path);
        if (!$actor) {
            $audit['duplicates'][] = ['type' => 'actor_layer_load_failed', 'actor_id' => $actor_id, 'actor_index' => $actor_index, 'layer_path' => $layer_path];
            $audit['ok'] = false;
            error_log('CMSG LAYERED QUALITY FAIL: actor_layer_load_failed variant=' . $variant . ' actor_id=' . $actor_id . ' layer=' . $layer_path);
            break;
        }

        imagealphablending($actor, true);
        imagesavealpha($actor, true);

        $actor_w = imagesx($actor);
        $actor_h = imagesy($actor);
        if ($actor_w <= 0 || $actor_h <= 0) {
            imagedestroy($actor);
            $audit['duplicates'][] = ['type' => 'actor_layer_empty', 'actor_id' => $actor_id, 'actor_index' => $actor_index, 'layer_path' => $layer_path];
            $audit['ok'] = false;
            error_log('CMSG LAYERED QUALITY FAIL: actor_layer_empty variant=' . $variant . ' actor_id=' . $actor_id . ' layer=' . $layer_path);
            break;
        }

        $slot = self::clamp_actor_slot((array)($placement_info['slot'] ?? []), $variant);
        $slot_key = sanitize_text_field((string)($placement_info['slot_key'] ?? ($slot['slot_key'] ?? ($variant . ':' . $actor_id))));
        $box_w = max(1, (int)round($canvas_w * (float)($slot['w'] ?? 0.34)));
        $box_h = max(1, (int)round($canvas_h * (float)($slot['h'] ?? 0.36)));
        $scale = min($box_w / max(1, $actor_w), $box_h / max(1, $actor_h));
        $target_w = max(1, (int)round($actor_w * $scale));
        $target_h = max(1, (int)round($actor_h * $scale));

        $x = (int)round(($canvas_w * (float)($slot['x'] ?? 0.50)) - ($target_w / 2));
        if (($slot['anchor'] ?? 'top_center') === 'center') {
            $y = (int)round(($canvas_h * (float)($slot['y'] ?? 0.20)) - ($target_h / 2));
        } else {
            $y = (int)round($canvas_h * (float)($slot['y'] ?? 0.20));
        }

        $title_safe_y = (int)round($canvas_h * ($variant === 'banner' ? 0.82 : 0.78));
        if ($y + (int)round($target_h * 0.34) > $title_safe_y) {
            $y = $title_safe_y - (int)round($target_h * 0.34);
        }
        $x = max((int)round(-0.03 * $target_w), min($x, $canvas_w - (int)round($target_w * 0.97)));
        $y = max(0, min($y, $canvas_h - (int)round($target_h * 0.12)));

        $face_anchor = self::load_actor_face_anchor((string)($placement_info['face_anchor_path'] ?? ''));
        $target_anchor = [];
        $pre_anchor_x = $x;
        $pre_anchor_y = $y;
        $anchor_adjustment_x = 0;
        $anchor_adjustment_y = 0;
        $anchor_method = sanitize_text_field((string)($face_anchor['method'] ?? 'none'));

        if (!empty($face_anchor['face_bbox']) && is_array($face_anchor['face_bbox'])) {
            $fb = $face_anchor['face_bbox'];
            $sx = $target_w / max(1, $actor_w);
            $sy = $target_h / max(1, $actor_h);
            $face_center = $face_anchor['face_center'] ?? [];
            $source_face_center_x = isset($face_center['x'])
                ? (float)$face_center['x']
                : ((float)($fb['x'] ?? 0) + ((float)($fb['w'] ?? 1) / 2));
            $source_eye_line_y = isset($face_anchor['eye_line_y'])
                ? (float)$face_anchor['eye_line_y']
                : ((float)($fb['y'] ?? 0) + ((float)($fb['h'] ?? 1) * 0.38));
            $scaled_face_center_x = $source_face_center_x * $sx;
            $scaled_eye_line_y = $source_eye_line_y * $sy;
            $target_anchor = self::actor_target_face_anchor($slot, $variant, $canvas_w, $canvas_h);
            $x = (int)round((int)($target_anchor['x'] ?? $pre_anchor_x) - $scaled_face_center_x);
            $y = (int)round((int)($target_anchor['eye_y'] ?? $pre_anchor_y) - $scaled_eye_line_y);
            $x = max((int)round(-0.03 * $target_w), min($x, $canvas_w - (int)round($target_w * 0.97)));
            $y = max(0, min($y, $canvas_h - (int)round($target_h * 0.12)));
            $anchor_adjustment_x = $x - $pre_anchor_x;
            $anchor_adjustment_y = $y - $pre_anchor_y;
            error_log('CMSG ACTOR FACE ANCHOR ADJUSTED variant=' . $variant . ' actor_id=' . $actor_id . ' method=' . $anchor_method . ' dx=' . $anchor_adjustment_x . ' dy=' . $anchor_adjustment_y . ' target=' . wp_json_encode($target_anchor));
        }

        $face_box_canvas = null;
        if (!empty($face_anchor['face_bbox']) && is_array($face_anchor['face_bbox'])) {
            $fb = $face_anchor['face_bbox'];
            $sx = $target_w / max(1, $actor_w);
            $sy = $target_h / max(1, $actor_h);
            $face_box_canvas = [
                'x' => $x + (int)round((float)($fb['x'] ?? 0) * $sx),
                'y' => $y + (int)round((float)($fb['y'] ?? 0) * $sy),
                'w' => max(1, (int)round((float)($fb['w'] ?? 1) * $sx)),
                'h' => max(1, (int)round((float)($fb['h'] ?? 1) * $sy)),
                'method' => sanitize_text_field((string)($face_anchor['method'] ?? 'unknown')),
            ];
            $title_zone_y = (int)round($canvas_h * ($variant === 'banner' ? 0.62 : 0.68));
            if ($face_box_canvas['x'] < 0 || $face_box_canvas['y'] < 0 || ($face_box_canvas['x'] + $face_box_canvas['w']) > $canvas_w || ($face_box_canvas['y'] + $face_box_canvas['h']) > $canvas_h) {
                $audit['duplicates'][] = ['type' => 'clipped_face_bbox', 'actor_id' => $actor_id, 'actor_index' => $actor_index, 'layer_path' => $layer_path];
                $audit['ok'] = false;
                error_log('CMSG LAYERED QUALITY FAIL: clipped_face_bbox variant=' . $variant . ' actor_id=' . $actor_id . ' bbox=' . wp_json_encode($face_box_canvas));
                imagedestroy($actor);
                break;
            }
            if ($face_box_canvas['y'] + (int)round($face_box_canvas['h'] * 0.60) >= $title_zone_y) {
                $audit['duplicates'][] = ['type' => 'title_face_intersection', 'actor_id' => $actor_id, 'actor_index' => $actor_index, 'layer_path' => $layer_path];
                $audit['ok'] = false;
                error_log('CMSG LAYERED QUALITY FAIL: title_face_intersection variant=' . $variant . ' actor_id=' . $actor_id . ' bbox=' . wp_json_encode($face_box_canvas));
                imagedestroy($actor);
                break;
            }
            error_log('CMSG ACTOR FACE SAFE PLACEMENT variant=' . $variant . ' actor_id=' . $actor_id . ' method=' . $anchor_method . ' bbox=' . wp_json_encode($face_box_canvas));
        }

        $actor = self::harmonize_actor_to_background($actor, $canvas, $x, $y, $target_w, $target_h);
        $shadow_strength = max(0.0, min(1.0, (float)($slot['shadow'] ?? 0.62)));
        self::copy_actor_shadow($canvas, $actor, $x + (int)round($target_w * 0.035), $y + (int)round($target_h * 0.025), $target_w, $target_h, $shadow_strength);
        self::gd_copy_alpha_resampled($canvas, $actor, $x, $y, $target_w, $target_h);
        imagedestroy($actor);

        $rendered_actor_ids[$actor_id] = true;
        $rendered_actor_indexes[$actor_index] = true;
        $rendered_layer_paths[$layer_key] = true;
        $placed++;
        $audit['actor_indexes'][] = $actor_index;
        $audit['layer_paths'][] = $layer_path;

        $final_mask_path = preg_replace('/\.png$/i', '-' . sanitize_key($actor_id ?: ('actor_' . $actor_index)) . '-final-mask.png', $composite_path);
        $final_mask_transform = ['source_path' => $layer_path, 'canvas_w' => $canvas_w, 'canvas_h' => $canvas_h, 'x' => $x, 'y' => $y, 'w' => $target_w, 'h' => $target_h, 'z_index' => (int)($placement_info['z_index'] ?? ($slot['z_index'] ?? $placed))];
        $final_mask_ok = self::write_transformed_alpha_mask($layer_path, $final_mask_path, $final_mask_transform);
        $final_mask_stats = $final_mask_ok ? self::alpha_mask_stats($final_mask_path) : [];
        if (!$final_mask_ok || empty($final_mask_stats['visible_pixels'])) {
            $audit['duplicates'][] = ['type' => 'recovery_actor_final_mask_missing', 'actor_id' => $actor_id, 'actor_index' => $actor_index, 'layer_path' => $layer_path];
            $audit['ok'] = false;
            error_log('CMSG PROFESSIONAL RECOVERY QUALITY FAIL code=recovery_actor_final_mask_missing actor_id=' . $actor_id . ' variant=' . $variant);
            break;
        }
        error_log('CMSG PROFESSIONAL RECOVERY ACTOR MASK actor_id=' . $actor_id . ' path=' . sanitize_text_field((string)$final_mask_path));

        $rendered = [
            'actor' => sanitize_text_field($placement_info['label'] ?? self::actor_layer_label($actor_index)),
            'actor_id' => $actor_id,
            'index' => $actor_index,
            'actor_index' => $actor_index,
            'source' => (string)($placement_info['source_path'] ?? ''),
            'cutout' => (string)($placement_info['cutout_path'] ?? ''),
            'final_cutout' => (string)($placement_info['final_cutout_path'] ?? ''),
            'layer' => $layer_path,
            'render_source_stage' => (string)($source_selection['stage'] ?? ''),
            'face_anchor_path' => (string)($placement_info['face_anchor_path'] ?? ''),
            'detected_face_anchor' => $face_anchor,
            'target_face_anchor' => $target_anchor,
            'pre_anchor_x' => $pre_anchor_x,
            'pre_anchor_y' => $pre_anchor_y,
            'post_anchor_x' => $x,
            'post_anchor_y' => $y,
            'anchor_adjustment_x' => $anchor_adjustment_x,
            'anchor_adjustment_y' => $anchor_adjustment_y,
            'anchor_method' => $anchor_method,
            'face_box_canvas' => $face_box_canvas,
            'final_mask_path' => $final_mask_path,
            'final_mask_transform' => $final_mask_transform,
            'final_mask_pixels' => (int)($final_mask_stats['visible_pixels'] ?? 0),
            'final_mask_canvas_intersection_ratio' => (float)($final_mask_stats['canvas_intersection_ratio'] ?? 0),
            'final_mask_clipped_ratio' => (float)($final_mask_stats['clipped_ratio'] ?? 0),
            'slot_key' => $slot_key,
            'x' => $x,
            'y' => $y,
            'w' => $target_w,
            'h' => $target_h,
            'z_index' => (int)($placement_info['z_index'] ?? ($slot['z_index'] ?? $placed)),
            'role' => $slot['role'] ?? '',
            'anchor' => $slot['anchor'] ?? 'top_center',
            'opacity' => (float)($slot['opacity'] ?? 1.0),
            'shadow_strength' => $shadow_strength,
            'fallback_used' => !empty($placement_info['fallback_used']),
        ];
        $placement_report[] = $rendered;
        self::campaign_render_audit_mark(
            $audit,
            $actor_id,
            $actor_index,
            $layer_path,
            $slot_key,
            $rendered['source'],
            $rendered['cutout'],
            (int)$rendered['z_index'],
            $slot
        );
        error_log('CMSG COMPOSITE PLACED ACTOR ' . ($rendered['actor'] ?? '') . ' actor_id=' . $actor_id . ' path=' . $layer_path . ' x=' . $x . ' y=' . $y . ' w=' . $target_w . ' h=' . $target_h . ' z=' . $rendered['z_index']);
    }

    $vehicle_report = self::render_recovery_prop_layers($canvas, is_array($manifest) ? $manifest : [], $variant, $canvas_w, $canvas_h, $placement_report, $composite_path);
    $audit['vehicle_report'] = $vehicle_report;

    $audit['placed_actor_count'] = $placed;
    if ($placed !== $expected_actor_count) {
        $audit['ok'] = false;
        $audit['duplicates'][] = ['type' => 'placement_count_mismatch', 'expected' => $expected_actor_count, 'placed' => $placed];
        error_log('CMSG LAYERED QUALITY FAIL: placement_count_mismatch variant=' . $variant . ' expected=' . $expected_actor_count . ' placed=' . $placed);
    }

    $audit_ok = self::campaign_render_audit_validate($audit, $variant);
    self::write_campaign_render_audit($audit, $composite_path);

    $report_path = preg_replace('/\.png$/i', '-placement-map.json', $composite_path);
    if ($report_path) {
        file_put_contents($report_path, wp_json_encode($placement_report, JSON_PRETTY_PRINT));
        @chmod($report_path, 0664);
    }

    if ($audit_ok && $placed === $expected_actor_count) {
        imagepng($canvas, $composite_path, 6);
        @chmod($composite_path, 0664);
        $composite_quality = self::validate_recovery_composite_visual_integrity($composite_path, $background_path, $placement_report, $vehicle_report, $manifest, $variant);
        $audit['recovery_composite_quality'] = $composite_quality;
        if (empty($composite_quality['valid'])) {
            $audit_ok = false;
            error_log('CMSG PROFESSIONAL RECOVERY QUALITY FAIL code=' . sanitize_text_field((string)($composite_quality['failure_reasons'][0] ?? 'recovery_composite_quality_failed')) . ' variant=' . $variant);
            @unlink($composite_path);
        } else {
            error_log('CMSG PROFESSIONAL RECOVERY QUALITY PASS variant=' . $variant . ' composite=' . sanitize_text_field((string)$composite_path));
        }
    }

    imagedestroy($canvas);
    return ($audit_ok && $placed === $expected_actor_count) ? $placed : -1;
}

private static function composite_prepared_actor_layers($background_path, $layers, $slots, $variant, $brief, $composite_path) {
    if (!function_exists('imagecreatefrompng') || !function_exists('imagecopyresampled')) return 0;
    if (!file_exists($background_path) || empty($layers)) return 0;

    $variant = sanitize_key($variant ?: 'vertical');
    $expected_actor_count = count($layers);
    $audit = self::campaign_render_audit_init(is_array($brief) ? $brief : [], $variant);
    $audit['expected_actor_count'] = $expected_actor_count;

    $canvas = @imagecreatefrompng($background_path);
    if (!$canvas) return 0;

    imagealphablending($canvas, true);
    imagesavealpha($canvas, true);

    $canvas_w = imagesx($canvas);
    $canvas_h = imagesy($canvas);
    $placed = 0;

    usort($layers, function($a, $b) use ($slots) {
        $ai = (int)($a['index'] ?? 0);
        $bi = (int)($b['index'] ?? 0);
        $az = (int)($slots[$ai]['z_index'] ?? ($ai + 1));
        $bz = (int)($slots[$bi]['z_index'] ?? ($bi + 1));
        return $az <=> $bz;
    });

    $placement_report = [];
    $placed_indexes = [];
    $placed_actor_ids = [];
    $placed_layer_paths = [];
    $duplicate_detected = false;
    foreach (array_values($layers) as $layer_info) {
        $local_i = (int)($layer_info['actor_index'] ?? ($layer_info['index'] ?? 0));
        $actor_id = sanitize_key($layer_info['actor_id'] ?? self::campaign_actor_id($local_i));
        if (isset($placed_indexes[$local_i])) {
            error_log('CMSG DUPLICATE ACTOR CHECK: duplicate_layer_index_skipped index=' . $local_i . ' layer=' . ($layer_info['layer'] ?? ''));
            $audit['duplicates'][] = ['type' => 'duplicate_render_actor_index', 'actor_id' => $actor_id, 'actor_index' => $local_i, 'layer_path' => (string)($layer_info['layer'] ?? '')];
            $audit['ok'] = false;
            $duplicate_detected = true;
            continue;
        }
        if (isset($placed_actor_ids[$actor_id])) {
            error_log('CMSG DUPLICATE ACTOR CHECK: duplicate_actor_id_skipped actor_id=' . $actor_id . ' index=' . $local_i . ' layer=' . ($layer_info['layer'] ?? ''));
            $audit['duplicates'][] = ['type' => 'duplicate_render_actor_id', 'actor_id' => $actor_id, 'actor_index' => $local_i, 'layer_path' => (string)($layer_info['layer'] ?? '')];
            $audit['ok'] = false;
            $duplicate_detected = true;
            continue;
        }

        $layer_path = $layer_info['layer'] ?? '';
        if (!$layer_path || !file_exists($layer_path)) continue;
        $real_layer_path = realpath($layer_path);
        $layer_key = $real_layer_path ? $real_layer_path : $layer_path;
        if (isset($placed_layer_paths[$layer_key])) {
            error_log('CMSG DUPLICATE ACTOR CHECK: duplicate_layer_path_skipped actor_id=' . $actor_id . ' index=' . $local_i . ' layer=' . $layer_path);
            $audit['duplicates'][] = ['type' => 'duplicate_render_layer_path', 'actor_id' => $actor_id, 'actor_index' => $local_i, 'layer_path' => $layer_path];
            $audit['ok'] = false;
            $duplicate_detected = true;
            continue;
        }

        $actor = @imagecreatefrompng($layer_path);
        if (!$actor) continue;

        imagealphablending($actor, true);
        imagesavealpha($actor, true);

        $actor_w = imagesx($actor);
        $actor_h = imagesy($actor);
        if ($actor_w <= 0 || $actor_h <= 0) {
            imagedestroy($actor);
            continue;
        }

        $slot = $slots[$local_i] ?? ['x' => 0.50, 'y' => 0.18, 'w' => 0.36, 'h' => 0.42, 'opacity' => 1.0, 'shadow' => 0.64, 'z_index' => $local_i + 1];
        $slot = self::clamp_actor_slot($slot, $variant);
        $slot_key = sanitize_text_field((string)($slot['slot_key'] ?? ($variant . ':' . $actor_id)));
        $box_w = max(1, (int)round($canvas_w * (float)($slot['w'] ?? 0.34)));
        $box_h = max(1, (int)round($canvas_h * (float)($slot['h'] ?? 0.36)));
        $scale = min($box_w / max(1, $actor_w), $box_h / max(1, $actor_h));
        $target_w = max(1, (int)round($actor_w * $scale));
        $target_h = max(1, (int)round($actor_h * $scale));

        $x = (int)round(($canvas_w * (float)($slot['x'] ?? 0.50)) - ($target_w / 2));
        if (($slot['anchor'] ?? 'top_center') === 'center') {
            $y = (int)round(($canvas_h * (float)($slot['y'] ?? 0.20)) - ($target_h / 2));
        } else {
            $y = (int)round($canvas_h * (float)($slot['y'] ?? 0.20));
        }

        $title_safe_y = (int)round($canvas_h * ($variant === 'banner' ? 0.82 : 0.78));
        if ($y + (int)round($target_h * 0.34) > $title_safe_y) {
            $y = $title_safe_y - (int)round($target_h * 0.34);
        }
        $x = max((int)round(-0.03 * $target_w), min($x, $canvas_w - (int)round($target_w * 0.97)));
        $y = max(0, min($y, $canvas_h - (int)round($target_h * 0.12)));

        $shadow_strength = max(0.0, min(1.0, (float)($slot['shadow'] ?? 0.62)));
        self::copy_actor_shadow($canvas, $actor, $x + (int)round($target_w * 0.035), $y + (int)round($target_h * 0.025), $target_w, $target_h, $shadow_strength);

        self::gd_copy_alpha_resampled($canvas, $actor, $x, $y, $target_w, $target_h);
        imagedestroy($actor);

        $placed++;
        $placed_indexes[$local_i] = true;
        $placed_actor_ids[$actor_id] = true;
        $placed_layer_paths[$layer_key] = true;
        $placement = [
            'actor' => $slot['actor_label'] ?? self::actor_layer_label($local_i),
            'actor_id' => $actor_id,
            'index' => $local_i,
            'actor_index' => $local_i,
            'source' => $layer_info['source_path'] ?? ($layer_info['source'] ?? ''),
            'cutout' => $layer_info['cutout_path'] ?? ($layer_info['cutout'] ?? ''),
            'layer' => $layer_path,
            'slot_key' => $slot_key,
            'x' => $x,
            'y' => $y,
            'w' => $target_w,
            'h' => $target_h,
            'z_index' => (int)($slot['z_index'] ?? $placed),
            'role' => $slot['role'] ?? '',
            'anchor' => $slot['anchor'] ?? 'top_center',
            'opacity' => (float)($slot['opacity'] ?? 1.0),
            'shadow_strength' => $shadow_strength,
            'fallback_used' => !empty($layer_info['fallback_used']),
        ];
        $placement_report[] = $placement;
        self::campaign_render_audit_mark(
            $audit,
            $actor_id,
            $local_i,
            $layer_path,
            $slot_key,
            $placement['source'],
            $placement['cutout'],
            (int)$placement['z_index'],
            $slot
        );
        error_log('CMSG COMPOSITE PLACED ACTOR ' . ($placement['actor'] ?? '') . ' path=' . $layer_path . ' x=' . $x . ' y=' . $y . ' w=' . $target_w . ' h=' . $target_h . ' z=' . $placement['z_index']);
    }

    if ($duplicate_detected) {
        $audit['ok'] = false;
    }

    $audit['placed_actor_count'] = $placed;
    $audit_ok = self::campaign_render_audit_validate($audit, $variant);
    self::write_campaign_render_audit($audit, $composite_path);

    if ($audit_ok && $placed > 0) {
        imagepng($canvas, $composite_path, 6);
        @chmod($composite_path, 0664);
    }

    imagedestroy($canvas);
    $report_path = preg_replace('/composite_preview\.png$/', 'placement_runtime.json', $composite_path);
    if ($report_path && !empty($placement_report)) {
        file_put_contents($report_path, wp_json_encode($placement_report, JSON_PRETTY_PRINT));
        @chmod($report_path, 0664);
    }
    if (!$audit_ok) {
        return -1;
    }
    return $placed;
}

private static function write_composite_quality_report($layer_dir, $passed, $expected, $placed, $reason, $layers = []) {
    $report = [
        'ok' => (bool)$passed,
        'expected_count' => (int)$expected,
        'placed_count' => (int)$placed,
        'reason' => sanitize_text_field((string)$reason),
        'layers' => [],
        'created_at' => gmdate('c'),
    ];

    foreach ((array)$layers as $layer) {
        $report['layers'][] = [
            'actor' => sanitize_text_field($layer['label'] ?? ''),
            'source' => sanitize_text_field($layer['source'] ?? ''),
            'cutout' => sanitize_text_field($layer['cutout'] ?? ''),
            'layer' => sanitize_text_field($layer['layer'] ?? ''),
            'fallback_used' => !empty($layer['fallback_used']),
            'analysis' => $layer['analysis'] ?? null,
        ];
    }

    $path = trailingslashit($layer_dir) . 'quality_report.json';
    file_put_contents($path, wp_json_encode($report, JSON_PRETTY_PRINT));
    @chmod($path, 0664);
    if ($passed) {
        error_log('CMSG COMPOSITE QUALITY PASS report=' . $path);
    } else {
        error_log('CMSG COMPOSITE QUALITY FAIL reason=' . sanitize_text_field((string)$reason) . ' report=' . $path);
    }
}

private static function copy_actor_shadow($canvas, $actor, $dst_x, $dst_y, $dst_w, $dst_h, $strength = 0.62) {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) return false;

    $src_w = imagesx($actor);
    $src_h = imagesy($actor);
    if ($src_w <= 0 || $src_h <= 0 || $dst_w <= 0 || $dst_h <= 0) return false;

    $shadow = imagecreatetruecolor($dst_w, $dst_h);
    imagealphablending($shadow, false);
    imagesavealpha($shadow, true);
    $clear = imagecolorallocatealpha($shadow, 0, 0, 0, 127);
    imagefilledrectangle($shadow, 0, 0, $dst_w, $dst_h, $clear);
    $scaled = imagecreatetruecolor($dst_w, $dst_h);
    imagealphablending($scaled, false);
    imagesavealpha($scaled, true);
    imagefilledrectangle($scaled, 0, 0, $dst_w, $dst_h, $clear);
    imagecopyresampled($scaled, $actor, 0, 0, 0, 0, $dst_w, $dst_h, $src_w, $src_h);

    for ($y = 0; $y < $dst_h; $y++) {
        for ($x = 0; $x < $dst_w; $x++) {
            $rgba = imagecolorat($scaled, $x, $y);
            $a = ($rgba >> 24) & 0x7F;
            if ($a >= 126) continue;
            $alpha = min(126, 127 - (int)round((127 - $a) * max(0.0, min(1.0, $strength)) * 0.72));
            $color = imagecolorallocatealpha($shadow, 0, 0, 0, $alpha);
            imagesetpixel($shadow, $x, $y, $color);
        }
    }

    imagefilter($shadow, IMG_FILTER_GAUSSIAN_BLUR);
    imagefilter($shadow, IMG_FILTER_GAUSSIAN_BLUR);
    imagefilter($shadow, IMG_FILTER_GAUSSIAN_BLUR);
    imagealphablending($canvas, true);
    imagecopy($canvas, $shadow, $dst_x, $dst_y, 0, 0, $dst_w, $dst_h);
    imagedestroy($scaled);
    imagedestroy($shadow);
    return true;
}

private static function gd_copy_soft_portrait($canvas, $actor, $x, $y, $target_w, $target_h, $actor_w, $actor_h) {
    $layer = imagecreatetruecolor($target_w, $target_h);
    imagealphablending($layer, false);
    imagesavealpha($layer, true);
    $transparent = imagecolorallocatealpha($layer, 0, 0, 0, 127);
    imagefilledrectangle($layer, 0, 0, $target_w, $target_h, $transparent);

    $target_ratio = $target_w / max(1, $target_h);
    $source_ratio = $actor_w / max(1, $actor_h);
    $src_x = 0;
    $src_y = 0;
    $src_w = $actor_w;
    $src_h = $actor_h;

    if ($source_ratio > $target_ratio) {
        $src_w = (int)round($actor_h * $target_ratio);
        $src_x = (int)round(($actor_w - $src_w) / 2);
    } else {
        $src_h = (int)round($actor_w / $target_ratio);
        $src_y = (int)round(($actor_h - $src_h) * 0.16);
    }

    $src_w = max(1, min($src_w, $actor_w));
    $src_h = max(1, min($src_h, $actor_h));
    $src_x = max(0, min($src_x, $actor_w - $src_w));
    $src_y = max(0, min($src_y, $actor_h - $src_h));

    imagecopyresampled($layer, $actor, 0, 0, $src_x, $src_y, $target_w, $target_h, $src_w, $src_h);

    for ($py = 0; $py < $target_h; $py++) {
        $ny = ($py / max(1, $target_h)) - 0.46;
        for ($px = 0; $px < $target_w; $px++) {
            $nx = ($px / max(1, $target_w)) - 0.50;
            $ellipse = sqrt(($nx * $nx) / (0.52 * 0.52) + ($ny * $ny) / (0.62 * 0.62));
            $edge = 1.0;
            if ($ellipse > 0.82) {
                $edge = max(0.0, min(1.0, (1.0 - $ellipse) / 0.18));
            }

            $bottom = 1.0;
            $bottom_start = (int)round($target_h * 0.76);
            if ($py > $bottom_start) {
                $bottom = max(0.0, 1.0 - (($py - $bottom_start) / max(1, $target_h - $bottom_start)));
            }

            $mask = $edge * max(0.18, $bottom);
            $rgba = imagecolorat($layer, $px, $py);
            $a = ($rgba >> 24) & 0x7F;
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;
            $final_a = 127 - (int)round((127 - $a) * $mask);
            $color = imagecolorallocatealpha($layer, $r, $g, $b, max(0, min(127, $final_a)));
            imagesetpixel($layer, $px, $py, $color);
        }
    }

    imagealphablending($canvas, true);
    imagecopy($canvas, $layer, $x, $y, 0, 0, $target_w, $target_h);
    imagedestroy($layer);
}

/*
 * V3.1.1 Face Replacement Engine
 */
private static function blend_actor_faces_only($poster_path, $assets, $variant = '') {

if (!file_exists($poster_path) || empty($assets) || !is_array($assets)) {
    return false;
}

$script = plugin_dir_path(dirname(__FILE__)) . 'tools/blend-faces.py';

if (!file_exists($script)) {
    error_log('CMSG FACE BLEND ERROR: blend-faces.py missing at ' . $script);
    return false;
}

$current = $poster_path;

foreach ($assets as $i => $asset_path) {
    if (!is_string($asset_path) || !file_exists($asset_path)) {
        continue;
    }

    $tmp = $poster_path . '.faceblend-' . intval($i) . '.png';

    $cmd = escapeshellcmd($script) . ' ' .
        escapeshellarg($current) . ' ' .
        escapeshellarg($asset_path) . ' ' .
        escapeshellarg($tmp) . ' 2>&1';

    error_log('CMSG FACE BLEND CMD: ' . $cmd);

    $result = shell_exec($cmd);

    error_log('CMSG FACE BLEND RESULT: ' . print_r($result, true));

    if (file_exists($tmp) && filesize($tmp) > 0) {
        @copy($tmp, $poster_path);
        @unlink($tmp);
        $current = $poster_path;
    }
}

return true;

}
public static function blend_selected_actor_face($poster_path, $actor_path, $face_box) {
    if (!file_exists($poster_path) || !file_exists($actor_path)) {
        return false;
    }

    error_log('CMSG MANUAL FACE BLEND: poster=' . $poster_path . ' actor=' . $actor_path . ' box=' . wp_json_encode($face_box));

    return true;
}

     private static function call_image_generation($api_key, $prompt, $size = '1024x1024', $background = null, $diagnostics = []) {
        if (self::$in_final_generation) {
            self::$final_openai_calls++;
            error_log('CMSG POSTER FINAL TRACE: OPENAI_IMAGE_GENERATION_CALLED_DURING_FINALIZATION size=' . $size);
        }

        $endpoint = 'https://api.openai.com/v1/images/generations';
        $body = [
            'model'  => 'gpt-image-1',
            'prompt' => $prompt,
            'size'   => $size,
        ];
        if (is_string($background) && in_array($background, ['opaque', 'transparent', 'auto'], true)) {
            $body['background'] = $background;
        }

        $request_json = wp_json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $attempt = is_array($diagnostics) ? max(1, (int)($diagnostics['attempt'] ?? 1)) : 1;

        if (is_array($diagnostics) && !empty($diagnostics['request_path'])) {
            self::write_background_openai_diagnostic($diagnostics['request_path'], [
                'created_at' => gmdate('c'),
                'attempt' => $attempt,
                'retry_reason' => is_string($diagnostics['retry_reason'] ?? '') ? $diagnostics['retry_reason'] : '',
                'previous_error' => is_array($diagnostics['previous_error'] ?? null) ? $diagnostics['previous_error'] : [],
                'endpoint' => $endpoint,
                'http_method' => 'POST',
                'model' => $body['model'],
                'size' => $size,
                'quality' => is_string($body['quality'] ?? '') ? $body['quality'] : '',
                'background' => is_string($body['background'] ?? '') ? $body['background'] : '',
                'output_format' => is_string($body['output_format'] ?? '') ? $body['output_format'] : '',
                'response_format' => is_string($body['response_format'] ?? '') ? $body['response_format'] : '',
                'encoding' => 'application/json',
                'request_headers_redacted' => [
                    'Authorization' => 'Bearer [redacted]',
                    'Content-Type' => 'application/json',
                ],
                'request_body' => $body,
                'request_body_json' => $request_json,
                'request_body_sha256' => hash('sha256', (string)$request_json),
                'prompt_length' => strlen((string)$prompt),
                'prompt_preview' => substr((string)$prompt, 0, 1000),
                'input_image_count' => 0,
                'mask_present' => false,
            ]);
            error_log('CMSG BACKGROUND OPENAI REQUEST DIAGNOSTIC path=' . $diagnostics['request_path'] . ' attempt=' . intval($attempt));
        }

        $started = microtime(true);
        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => $request_json,
            'timeout' => 180,
        ]);
        $duration_ms = (int)round((microtime(true) - $started) * 1000);

        if (is_array($diagnostics) && !empty($diagnostics['response_path'])) {
            $response_body = is_wp_error($response) ? '' : wp_remote_retrieve_body($response);
            $response_code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
            $headers = is_wp_error($response) ? [] : wp_remote_retrieve_headers($response);
            if (is_object($headers) && method_exists($headers, 'getAll')) {
                $headers = $headers->getAll();
            } elseif (!is_array($headers)) {
                $headers = [];
            }

            $decoded = is_string($response_body) && $response_body !== '' ? json_decode($response_body, true) : null;
            $error = self::openai_response_error_summary($response, $response_body);
            $diagnostic_body = ((int)$response_code >= 200 && (int)$response_code < 300)
                ? '[omitted_success_body_use_decoded_response_json]'
                : (string)$response_body;
            $diagnostic_decoded = is_array($decoded) ? self::sanitize_openai_response_for_diagnostics($decoded) : null;

            self::write_background_openai_diagnostic($diagnostics['response_path'], [
                'created_at' => gmdate('c'),
                'attempt' => $attempt,
                'retry_reason' => is_string($diagnostics['retry_reason'] ?? '') ? $diagnostics['retry_reason'] : '',
                'previous_error' => is_array($diagnostics['previous_error'] ?? null) ? $diagnostics['previous_error'] : [],
                'endpoint' => $endpoint,
                'model' => $body['model'],
                'http_status' => (int)$response_code,
                'response_headers' => $headers,
                'wp_error' => is_wp_error($response),
                'wp_error_code' => is_wp_error($response) ? $response->get_error_code() : '',
                'wp_error_message' => is_wp_error($response) ? $response->get_error_message() : '',
                'raw_response_body' => $diagnostic_body,
                'wp_remote_retrieve_body' => $diagnostic_body,
                'wp_remote_retrieve_response_code' => (int)$response_code,
                'decoded_response_json' => $diagnostic_decoded,
                'openai_error' => $error,
                'request_duration_ms' => $duration_ms,
            ]);
            error_log('CMSG BACKGROUND OPENAI RESPONSE DIAGNOSTIC path=' . $diagnostics['response_path'] . ' attempt=' . intval($attempt) . ' status=' . intval($response_code));

            self::$last_background_openai_error = self::background_openai_error_trace_fields([
                'http_status' => $response_code,
                'openai_error' => $error,
                'response_path' => (string)$diagnostics['response_path'],
            ]);

            if (is_wp_error($response)) {
                error_log('CMSG BACKGROUND OPENAI WP ERROR code=' . $response->get_error_code() . ' message=' . $response->get_error_message());
            } elseif ((int)$response_code >= 400) {
                error_log('CMSG BACKGROUND OPENAI HTTP ERROR status=' . intval($response_code) . ' message=' . ($error['message'] ?? '') . ' type=' . ($error['type'] ?? '') . ' code=' . ($error['code'] ?? '') . ' param=' . ($error['param'] ?? ''));
            }
        }

        return $response;
    }

private static function normalize_reference_image_for_openai($path) {
    if (empty($path) || !file_exists($path) || !is_readable($path)) return '';

    $info = @getimagesize($path);
    if (!$info || empty($info['mime'])) {
        error_log('CMSG POSTER AI INVALID IMAGE SIZE/MIME: ' . $path);
        return '';
    }

    $mime = $info['mime'];
    $allowed = ['image/png', 'image/jpeg', 'image/webp'];

    if (!in_array($mime, $allowed, true)) {
        error_log('CMSG POSTER AI UNSUPPORTED IMAGE MIME: ' . $path . ' MIME=' . $mime);
        return '';
    }

    $uploads = wp_upload_dir();
    $dir = trailingslashit($uploads['basedir']) . 'poster-openai-normalized';
    if (!is_dir($dir)) wp_mkdir_p($dir);

    $out = trailingslashit($dir) . sanitize_file_name(pathinfo($path, PATHINFO_FILENAME)) . '-' . md5($path . filemtime($path)) . '.png';

    if (file_exists($out)) return $out;

    if ($mime === 'image/jpeg') {
        $src = @imagecreatefromjpeg($path);
    } elseif ($mime === 'image/png') {
        $src = @imagecreatefrompng($path);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $src = @imagecreatefromwebp($path);
    } else {
        return '';
    }

    if (!$src) {
        error_log('CMSG POSTER AI FAILED TO LOAD IMAGE: ' . $path);
        return '';
    }

    $w = imagesx($src);
    $h = imagesy($src);

    $canvas = imagecreatetruecolor($w, $h);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
    imagefilledrectangle($canvas, 0, 0, $w, $h, $transparent);
    imagecopy($canvas, $src, 0, 0, 0, 0, $w, $h);

    imagepng($canvas, $out, 6);

    imagedestroy($src);
    imagedestroy($canvas);

    @chmod($out, 0664);
    return $out;
}

     private static function call_image_edit($api_key, $prompt, $brief, $size = '1024x1024') {
        if (self::$in_final_generation) {
            self::$final_openai_calls++;
            error_log('CMSG POSTER FINAL TRACE: OPENAI_IMAGE_EDIT_CALLED_DURING_FINALIZATION size=' . $size);
        }

        $boundary = wp_generate_password(24, false);
        $eol = "\r\n";
        $body = '';
        $add_field = function($name, $value) use (&$body, $boundary, $eol) {
            $body .= "--{$boundary}{$eol}";
            $body .= "Content-Disposition: form-data; name=\"{$name}\"{$eol}{$eol}";
            $body .= $value . $eol;
        };
$add_file = function($field, $path) use (&$body, $boundary, $eol) {
    if (!file_exists($path) || !is_readable($path)) return;

    $mime = function_exists('mime_content_type') ? mime_content_type($path) : '';
    $allowed = ['image/png', 'image/jpeg', 'image/webp'];

    if (!in_array($mime, $allowed, true)) {
        error_log('CMSG POSTER AI SKIP INVALID IMAGE: ' . $path . ' MIME=' . $mime);
        return;
    }

            $body .= "--{$boundary}{$eol}";
            $body .= "Content-Disposition: form-data; name=\"{$field}\"; filename=\"" . basename($path) . "\"{$eol}";
            $body .= "Content-Type: {$mime}{$eol}{$eol}";
            $body .= file_get_contents($path) . $eol;
        };

        $add_field('model', 'gpt-image-1');
        $add_field('prompt', $prompt);
        $add_field('size', $size);
if (!empty($brief['style_reference'])) {
    $normalized = self::normalize_reference_image_for_openai($brief['style_reference']);
    if ($normalized) $add_file('image[]', $normalized);
}
error_log('CMSG POSTER STYLE REF: ' . (!empty($brief['style_reference']) ? $brief['style_reference'] : 'none'));
$cast_reference_assets = self::should_use_cast_references($brief) ? self::cast_actor_assets($brief) : [];
foreach ($cast_reference_assets as $cast_asset) {
    $normalized = self::normalize_reference_image_for_openai($cast_asset);
    if ($normalized) $add_file('image[]', $normalized);
}
error_log('CMSG POSTER CAST REF COUNT: ' . count($cast_reference_assets));
error_log('CMSG POSTER ASSET COUNT: ' . count($brief['poster_assets'] ?? []));

        $visual_reference_assets = [];
        if (!empty($brief['poster_assets']) && is_array($brief['poster_assets'])) {
            foreach ($brief['poster_assets'] as $asset) {
                if (is_string($asset) && $asset !== '') {
                    $visual_reference_assets[] = $asset;
                }
            }
        }
        foreach (self::normalized_poster_asset_references($brief) as $reference) {
            if (!empty($reference['image']) && is_string($reference['image'])) {
                $visual_reference_assets[] = $reference['image'];
            }
        }
        $visual_reference_assets = array_values(array_unique($visual_reference_assets));

foreach ($visual_reference_assets as $asset) {
    $normalized = self::normalize_reference_image_for_openai($asset);
    if ($normalized) $add_file('image[]', $normalized);
}
        $body .= "--{$boundary}--{$eol}";

        return wp_remote_post('https://api.openai.com/v1/images/edits', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $body,
            'timeout' => 180,
        ]);
    }

private static function is_valid_openai_image($path) {
    if (empty($path) || !file_exists($path) || !is_readable($path)) return false;

    $mime = function_exists('mime_content_type') ? mime_content_type($path) : '';
    $allowed = ['image/png', 'image/jpeg', 'image/webp'];

    if (!in_array($mime, $allowed, true)) {
        error_log('CMSG POSTER AI INVALID REFERENCE IMAGE: ' . $path . ' MIME=' . $mime);
        return false;
    }

    return true;
}

private static function has_reference_images($brief) {
    if (!empty($brief['style_reference']) && self::is_valid_openai_image($brief['style_reference'])) return true;

    if (self::should_use_cast_references($brief)) {
        foreach (self::cast_actor_assets($brief) as $cast_asset) {
            if (self::is_valid_openai_image($cast_asset)) return true;
        }
    }

    $visual_reference_assets = [];
    if (!empty($brief['poster_assets']) && is_array($brief['poster_assets'])) {
        foreach ($brief['poster_assets'] as $asset) {
            if (is_string($asset) && $asset !== '') {
                $visual_reference_assets[] = $asset;
            }
        }
    }
    foreach (self::normalized_poster_asset_references($brief) as $reference) {
        if (!empty($reference['image']) && is_string($reference['image'])) {
            $visual_reference_assets[] = $reference['image'];
        }
    }
    foreach (array_unique($visual_reference_assets) as $asset) {
            if (self::is_valid_openai_image($asset)) return true;
    }

    return false;
}

    private static function apply_watermark($path) {
        if (!file_exists($path) || !function_exists('imagecreatefrompng')) return;
        $img = imagecreatefrompng($path); if (!$img) return;
        imagealphablending($img, true); imagesavealpha($img, true);
        $width = imagesx($img); $height = imagesy($img);
        $text = CMSG_Plugin::settings()['poster_preview_watermark_text'] ?: 'CROSSMARKET PREVIEW';
        $white = imagecolorallocatealpha($img, 255, 255, 255, 68);
        for ($y = (int)($height * .25); $y < $height; $y += 170) {
            imagestring($img, 5, (int)($width * .20), $y, $text, $white);
            imagestring($img, 5, (int)($width * .55), $y + 75, $text, $white);
        }
        imagepng($img, $path); imagedestroy($img);
    }

private static function resize_canvas_png($src, $dest, $target_w, $target_h, $brief, $cfg = []) {
    if (!function_exists('imagecreatefrompng') || !file_exists($src)) return false;

    $img = imagecreatefrompng($src);
    if (!$img) return false;

    $src_w = imagesx($img);
    $src_h = imagesy($img);

    $canvas = imagecreatetruecolor($target_w, $target_h);

    // Clean dark poster-safe background. No duplicated/layered image.
    $bg = imagecolorallocate($canvas, 12, 10, 8);
    imagefill($canvas, 0, 0, $bg);

    // Fit selected preview fully inside output frame. No actor cropping.
    $fit_scale = min(
    ($target_w * 0.96) / $src_w,
    ($target_h * 0.96) / $src_h
    );
    $fit_w = (int) floor($src_w * $fit_scale);
    $fit_h = (int) floor($src_h * $fit_scale);

    $fit_x = (int) floor(($target_w - $fit_w) / 2);
    $fit_y = (int) floor(($target_h - $fit_h) * 0.18);

    imagecopyresampled($canvas, $img, $fit_x, $fit_y, 0, 0, $fit_w, $fit_h, $src_w, $src_h);

    imagepng($canvas, $dest, 9);

    imagedestroy($img);
    imagedestroy($canvas);

    self::overlay_title_and_tagline($dest, $brief, $target_w, $target_h, $cfg);

    return true;
}

private static function resize_final_native_png($src, $dest, $target_w, $target_h, $brief, $cfg = []) {
    if (!function_exists('imagecreatefrompng') || !file_exists($src)) return false;

    $img = imagecreatefrompng($src);
    if (!$img) return false;

    $src_w = imagesx($img);
    $src_h = imagesy($img);
    $canvas = imagecreatetruecolor($target_w, $target_h);
        $canvas = imagecreatetruecolor($target_w, $target_h);

        // Native final should cover the frame exactly.
        $scale = max($target_w / $src_w, $target_h / $src_h);
        $new_w = (int) ceil($src_w * $scale);
        $new_h = (int) ceil($src_h * $scale);

        $dst_x = (int) floor(($target_w - $new_w) / 2);
        $dst_y = (int) floor(($target_h - $new_h) / 2);

        imagecopyresampled($canvas, $img, $dst_x, $dst_y, 0, 0, $new_w, $new_h, $src_w, $src_h);
    imagepng($canvas, $dest, 9);

    imagedestroy($img);
    imagedestroy($canvas);

    self::overlay_title_and_tagline($dest, $brief, $target_w, $target_h, $cfg);
    return true;
}

private static function poster_fit_blur_canvas($img, $src_w, $src_h, $target_w, $target_h) {
    if (!$img || $src_w <= 0 || $src_h <= 0 || $target_w <= 0 || $target_h <= 0) {
        return false;
    }

    $canvas = imagecreatetruecolor($target_w, $target_h);

    $bg_scale = max($target_w / $src_w, $target_h / $src_h);
    $bg_w = (int)ceil($src_w * $bg_scale);
    $bg_h = (int)ceil($src_h * $bg_scale);
    $bg_x = (int)floor(($target_w - $bg_w) / 2);
    $bg_y = (int)floor(($target_h - $bg_h) / 2);

    imagecopyresampled($canvas, $img, $bg_x, $bg_y, 0, 0, $bg_w, $bg_h, $src_w, $src_h);
    if (function_exists('imagefilter')) {
        for ($i = 0; $i < 12; $i++) {
            imagefilter($canvas, IMG_FILTER_GAUSSIAN_BLUR);
        }
        imagefilter($canvas, IMG_FILTER_BRIGHTNESS, -18);
        imagefilter($canvas, IMG_FILTER_CONTRAST, 8);
    }

    $shade = imagecolorallocatealpha($canvas, 0, 0, 0, 58);
    imagefilledrectangle($canvas, 0, 0, $target_w, $target_h, $shade);

    $fg_scale = min(($target_w * 0.96) / $src_w, ($target_h * 0.96) / $src_h);
    $fg_w = (int)floor($src_w * $fg_scale);
    $fg_h = (int)floor($src_h * $fg_scale);
    $fg_x = (int)floor(($target_w - $fg_w) / 2);
    $fg_y = (int)floor(($target_h - $fg_h) / 2);

    $shadow = imagecolorallocatealpha($canvas, 0, 0, 0, 62);
    imagefilledrectangle($canvas, $fg_x - 8, $fg_y + 8, $fg_x + $fg_w + 8, $fg_y + $fg_h + 10, $shadow);
    imagecopyresampled($canvas, $img, $fg_x, $fg_y, 0, 0, $fg_w, $fg_h, $src_w, $src_h);

    return $canvas;
}

private static function resize_png_fit_blur_no_overlay($src, $dest, $target_w, $target_h) {
    if (!function_exists('imagecreatefrompng') || !file_exists($src)) return false;

    $img = imagecreatefrompng($src);
    if (!$img) return false;

    $src_w = imagesx($img);
    $src_h = imagesy($img);
    $canvas = self::poster_fit_blur_canvas($img, $src_w, $src_h, $target_w, $target_h);
    imagedestroy($img);

    if (!$canvas) return false;

    $tmp = $dest . '.tmp-' . wp_generate_password(8, false) . '.png';
    $ok = imagepng($canvas, $tmp, 9);
    imagedestroy($canvas);

    if (!$ok || !file_exists($tmp)) {
        @unlink($tmp);
        return false;
    }

    @rename($tmp, $dest);
    @chmod($dest, 0664);
    return file_exists($dest) && filesize($dest) > 0;
}

private static function resize_png_cover_no_overlay($src, $dest, $target_w, $target_h) {
    if (!function_exists('imagecreatefrompng') || !file_exists($src)) return false;

    $img = imagecreatefrompng($src);
    if (!$img) return false;

    $src_w = imagesx($img);
    $src_h = imagesy($img);
    if ($src_w <= 0 || $src_h <= 0 || $target_w <= 0 || $target_h <= 0) {
        imagedestroy($img);
        return false;
    }

    $canvas = imagecreatetruecolor($target_w, $target_h);
    $scale = max($target_w / $src_w, $target_h / $src_h);
    $new_w = (int)ceil($src_w * $scale);
    $new_h = (int)ceil($src_h * $scale);
    $dst_x = (int)floor(($target_w - $new_w) / 2);
    $dst_y = (int)floor(($target_h - $new_h) / 2);

    imagecopyresampled($canvas, $img, $dst_x, $dst_y, 0, 0, $new_w, $new_h, $src_w, $src_h);

    $tmp = $dest . '.tmp-' . wp_generate_password(8, false) . '.png';
    $ok = imagepng($canvas, $tmp, 9);

    imagedestroy($img);
    imagedestroy($canvas);

    if (!$ok || !file_exists($tmp)) {
        @unlink($tmp);
        return false;
    }

    @rename($tmp, $dest);
    @chmod($dest, 0664);
    return file_exists($dest) && filesize($dest) > 0;
}
    private static function poster_font_path($style = 'cinematic_bold') {
        $map = [
            'cinematic_bold' => [
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/ubuntu/Ubuntu-M.ttf',
            ],
            'luxury_serif' => [
                '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf',
                '/usr/share/fonts/truetype/freefont/FreeSerifBold.ttf',
            ],
            'modern_sans' => [
                '/usr/share/fonts/truetype/ubuntu/Ubuntu-M.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            ],
            'horror_bold' => [
                '/usr/share/fonts/truetype/freefont/FreeSerifBold.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSerifCondensed-Bold.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf',
            ],
            'action_block' => [
                '/usr/share/fonts/truetype/dejavu/DejaVuSansCondensed-Bold.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            ],
            'clean_sans' => [
                '/usr/share/fonts/truetype/ubuntu/Ubuntu-R.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            ],
            'elegant_serif' => [
                '/usr/share/fonts/truetype/freefont/FreeSerif.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf',
            ],
            'condensed' => [
                '/usr/share/fonts/truetype/dejavu/DejaVuSansCondensed.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            ],
        ];

        $candidates = $map[$style] ?? $map['cinematic_bold'];
        foreach ($candidates as $font) {
            if (file_exists($font)) return $font;
        }
        return file_exists('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf') ? '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf' : '';
    }

private static function wrap_text_to_width($text, $font, $font_size, $max_width) {
    $manual_lines = preg_split('/\R/', trim((string)$text));
    $lines = [];

    foreach ($manual_lines as $manual_line) {
        $words = preg_split('/\s+/', trim($manual_line));
        $line = '';

        foreach ($words as $word) {
            if ($word === '') continue;

            $test = trim($line . ' ' . $word);
            $box = imagettfbbox($font_size, 0, $font, $test);
            $width = abs($box[2] - $box[0]);

            if ($width > $max_width && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $test;
            }
        }

        if ($line !== '') $lines[] = $line;
    }

    return $lines;
}

    private static function draw_centered_text_block($img, $text, $font, $max_width, $center_x, $bottom_y, $max_font_size, $min_font_size, $max_height, $color, $shadow) {
        $text = trim((string)$text);
        if ($text === '' || empty($font) || !file_exists($font) || !function_exists('imagettfbbox')) return;
        $font_size = max((int)$min_font_size, (int)$max_font_size);
        $lines = [];
        while ($font_size >= $min_font_size) {
            $lines = self::wrap_text_to_width($text, $font, $font_size, $max_width);
            $line_height = (int)round($font_size * 1.15);
            $total_height = count($lines) * $line_height;
            if ($total_height <= $max_height) break;
            $font_size -= 2;
        }
        if (empty($lines)) return;
        $line_height = (int)round($font_size * 1.15);
        $total_height = count($lines) * $line_height;
        $start_y = (int)round($bottom_y - $total_height);
// Prevent wrapped title/tagline from cutting off at the top
$safe_top_padding = max(50, (int)round(imagesy($img) * 0.06));

if ($start_y < $safe_top_padding) {
    $start_y = $safe_top_padding;
}
        foreach ($lines as $i => $line) {
            $box = imagettfbbox($font_size, 0, $font, $line);
            $line_width = abs($box[2] - $box[0]);
            $x = (int)round($center_x - ($line_width / 2));
            $y = (int)round($start_y + (($i + 1) * $line_height));
            imagettftext($img, $font_size, 0, $x + 3, $y + 3, $shadow, $font, $line);
            imagettftext($img, $font_size, 0, $x, $y, $color, $font, $line);
        }
    }

     private static function overlay_title_and_tagline($path, $brief, $target_w = 0, $target_h = 0, $cfg = []) {
        if (empty($path) || !file_exists($path) || !function_exists('imagecreatefrompng')) return false;
        $img = imagecreatefrompng($path);
        if (!$img) return false;

        $target_w = $target_w > 0 ? (int)$target_w : imagesx($img);
        $target_h = $target_h > 0 ? (int)$target_h : imagesy($img);
        $title = strtoupper(trim((string)($brief['title'] ?? '')));
        $title = preg_replace('/\s+/', ' ', $title);

if (strlen($title) > 14 && strpos($title, ' ') !== false) {
    $words = explode(' ', $title);
    if (count($words) === 2) {
        $title = $words[0] . "\n" . $words[1];
    }
}
$tagline = trim((string)($brief['tagline'] ?? ''));
        if ($title === '' && $tagline === '') { imagedestroy($img); return false; }

        $title_font = self::poster_font_path($brief['title_font_style'] ?? 'cinematic_bold');
        $tagline_font = self::poster_font_path($brief['tagline_font_style'] ?? 'clean_sans');
        if (empty($title_font) || empty($tagline_font)) { imagedestroy($img); return false; }

        $white = imagecolorallocate($img, 255, 244, 214);
        $tag_color = imagecolorallocate($img, 235, 235, 235);
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, 45);

$safe_w = !empty($cfg['safe_w']) ? (int)$cfg['safe_w'] : (int)($target_w * 0.90);
$safe_h = !empty($cfg['safe_h']) ? (int)$cfg['safe_h'] : (int)($target_h * 0.90);

$safe_x = (int)(($target_w - $safe_w) / 2);
$safe_y = (int)(($target_h - $safe_h) / 2);

$center_x = (int)($safe_x + ($safe_w / 2));
$max_width = (int)($safe_w * 0.72);
$aspect = $target_w / max(1, $target_h);
$position = sanitize_text_field($brief['title_position'] ?? 'bottom_cinematic');
// landscape banner size section below
if ($aspect > 1.3) {
    $title_max = (int)($target_h * 0.075);
    $title_min = 18;

    if ($position === 'top_minimal') {
        $title_bottom_y = (int)($target_h * 0.16);
        $tag_bottom_y = (int)($target_h * 0.24);
        $title_max = (int)($title_max * 0.42);
        $title_min = 18;
        $max_width = (int)($target_w * 0.72);
    } elseif ($position === 'lower_third') {
        $title_bottom_y = (int)($target_h * 0.72);
        $tag_bottom_y = (int)($target_h * 0.44);
    } elseif ($position === 'streaming_style') {
        $title_bottom_y = (int)($target_h * 0.73);
        $tag_bottom_y = (int)($target_h * 0.84);
    } else {
        $title_bottom_y = (int)($target_h * 0.88);
        $tag_bottom_y = (int)($target_h * 0.95);
    }
//vertical size section below
} elseif ($aspect < 0.8) {
    $title_max = (int)($target_w * 0.075);
    $title_min = 30;

    if ($position === 'top_minimal') {
        $title_bottom_y = (int)($target_h * 0.16);
        $tag_bottom_y = (int)($target_h * 0.24);
        $title_max = (int)($title_max * 0.42);
        $title_min = 18;
        $max_width = (int)($target_w * 0.55);
    } elseif ($position === 'lower_third') {
        $title_bottom_y = (int)($target_h * 0.70);
        $tag_bottom_y = (int)($target_h * 0.44);
    } elseif ($position === 'streaming_style') {
        $title_bottom_y = (int)($target_h * 0.80);
        $tag_bottom_y = (int)($target_h * 0.50);
} else {
    $title_bottom_y = (int)($safe_y + ($safe_h * 0.96));
    $tag_bottom_y = (int)($safe_y + ($safe_h * 0.99));
}
} else {
    $title_max = (int)($target_h * 0.095);
    $title_min = 28;

    if ($position === 'top_minimal') {
        $title_bottom_y = (int)($target_h * 0.16);
        $tag_bottom_y = (int)($target_h * 0.24);
        $title_max = (int)($title_max * 0.42);
        $title_min = 18;
        $max_width = (int)($target_w * 0.72);
    } elseif ($position === 'lower_third') {
        $title_bottom_y = (int)($target_h * 0.72);
        $tag_bottom_y = (int)($target_h * 0.44);
    } elseif ($position === 'streaming_style') {
        $title_bottom_y = (int)($target_h * 0.82);
        $tag_bottom_y = (int)($target_h * 0.50);
    } else {
        $title_bottom_y = (int)($target_h * 0.90);
        $tag_bottom_y = (int)($target_h * 0.58);
    }
}

        self::draw_centered_text_block($img, $title, $title_font, $max_width, $center_x, $title_bottom_y, $title_max, $title_min, (int)($target_h * 0.16), $white, $shadow);
        if ($tagline !== '') {
            self::draw_centered_text_block($img, $tagline, $tagline_font, (int)($target_w * 0.62), $center_x, $tag_bottom_y, max(14, (int)($title_max * 0.22)), 12, (int)($target_h * 0.07), $tag_color, $shadow);
        }
        imagepng($img, $path, 9);
        imagedestroy($img);
        return true;
    }

    private static function generate_svg_fallback_previews($brief, $draft_id) {
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . 'poster-previews';
        if (!is_dir($dir)) wp_mkdir_p($dir);
        $files = [];
        for ($i = 1; $i <= 3; $i++) {
            $path = $dir . '/' . sanitize_title(($brief['title'] ?? 'poster')) . '-fallback-preview-' . intval($draft_id) . '-' . $i . '.svg';
            file_put_contents($path, self::svg_preview($brief, $i));
            $files[] = CMSG_Jobs::path_to_url($path);
        }
        return $files;
    }

    private static function svg_preview($brief, $seed) {
        $title = esc_html($brief['title'] ?? 'Untitled Film');
        $tagline = esc_html($brief['tagline'] ?? 'A cinematic poster concept');
        $genre = esc_html($brief['genre'] ?? 'Genre');
        $mood = esc_html($brief['mood'] ?? 'Mood');
        $wm = esc_html(CMSG_Plugin::settings()['poster_preview_watermark_text'] ?: 'CROSSMARKET PREVIEW');
        return '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" width="1200" height="1800" viewBox="0 0 1200 1800"><rect width="1200" height="1800" fill="#10141c"/><text x="90" y="180" font-size="34" fill="#ffd84c" font-family="Arial" letter-spacing="4">' . $genre . ' • ' . $mood . '</text><text x="90" y="980" font-size="132" fill="#fff" font-family="Arial Black">' . $title . '</text><text x="90" y="1060" font-size="40" fill="#d8deea" font-family="Arial">' . $tagline . '</text><text x="50%" y="56%" text-anchor="middle" transform="rotate(-18 600 1000)" font-size="86" fill="rgba(255,255,255,0.16)" font-family="Arial Black">' . $wm . '</text></svg>';
    }
}
