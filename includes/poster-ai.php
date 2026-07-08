<?php
if (!defined('ABSPATH')) { exit; }

final class CMSG_Poster_AI {
    private static $in_final_generation = false;
    private static $final_openai_calls = 0;
    private static $last_preview_quality_message = '';
    private static $last_preview_quality_failures = [];
    private static $enable_preview_quality_check = true;
    private static $duplicate_face_similarity_threshold = 0.62;
    private static $duplicate_face_detector_method = 'embedding_with_heuristic_fallback';
    private const SINGLE_PASS_MAX_CAST_REFERENCES = 4;
    private const LAYERED_CAMPAIGN_BACKGROUND_RETRIES = 3;

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
            ];
        }

        return $members;
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
    $decoded = json_decode(trim((string)$raw), true);

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

private static function generate_background_only_file($brief, $out, $variant, $openai_size) {
    $api_key = trim((string) CMSG_Plugin::settings()['openai_api_key']);
    if (!$api_key) {
        error_log('CMSG POSTER IDENTITY COMPOSITE ERROR: OpenAI API key is missing for background generation.');
        return false;
    }

    $prompt = self::build_background_only_prompt($brief, $variant);
    $response = self::call_image_generation($api_key, $prompt, $openai_size);
    if (is_wp_error($response)) {
        error_log('CMSG POSTER IDENTITY COMPOSITE BACKGROUND ERROR: ' . $response->get_error_message());
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($code < 200 || $code >= 300 || empty($data['data'][0]['b64_json'])) {
        error_log('CMSG POSTER IDENTITY COMPOSITE BACKGROUND ERROR CODE: ' . $code . ' BODY: ' . $body);
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

private static function build_background_only_prompt($brief, $variant = '') {
    $title = sanitize_text_field($brief['title'] ?? ($brief['movie_title'] ?? 'Untitled Film'));
    $genre = sanitize_text_field($brief['genre'] ?? '');
    $mood = sanitize_text_field($brief['mood'] ?? '');
    $style = sanitize_text_field($brief['style_preset'] ?? '');
    $scene = sanitize_textarea_field($brief['poster_description'] ?? '');
    $variant_label = sanitize_text_field($variant ?: 'vertical');

    $prompt = "Create a cinematic movie poster BACKGROUND PLATE ONLY.\n\n";
    $prompt .= "PROJECT: {$title}\n";
    if ($genre !== '') $prompt .= "GENRE: {$genre}\n";
    if ($mood !== '') $prompt .= "MOOD: {$mood}\n";
    if ($style !== '') $prompt .= "STYLE PRESET: {$style}\n";
    $prompt .= "FORMAT: {$variant_label}\n\n";
    $prompt .= "BACKGROUND SCENE DIRECTION:\n";
    $prompt .= ($scene !== '' ? $scene : 'Create a polished cinematic environment with dramatic lighting and title-safe lower space.') . "\n\n";
    $prompt .= "Use the scene direction only to infer environment, era, props, weather, lighting, symbolism, and empty placement zones. Ignore any instruction to draw actors or people; actor layers will be composited later from uploaded references only.\n\n";
    $prompt .= "STRICT BACKGROUND-ONLY RULES:\n";
    $prompt .= "- NO people, NO faces, NO human silhouettes, NO characters, NO actors, NO bodies.\n";
    $prompt .= "- Do not create people, actors, faces, bodies, silhouettes, crowds, reflections of people, portraits, statues, masks, mannequins, ghosts, or human-like figures.\n";
    $prompt .= "- Do not create floating heads, bust portraits, background cast, shadow people, crowd texture, human-shaped smoke, or human-like reflections.\n";
    $prompt .= "- If the scene direction mentions Actor 1, Actor 2, cast, father, mother, daughter, lead, supporting, or any character placement, convert that into EMPTY reserved space only.\n";
    $prompt .= "- The background plate will be rejected if any human face, head, body, figure, silhouette, statue, portrait, or person-like form appears.\n";
    $prompt .= "- Do not render the movie title, tagline, credits, logos, captions, signs, readable text, or typography.\n";
    $prompt .= "- Leave clean visual space for actor layers and final title typography that will be composited later by the plugin.\n";
    $prompt .= "- The result must be a people-free cinematic background plate only.\n";
    $prompt .= "- Use atmospheric lighting, depth, props, vehicles, buildings, symbols, and environment elements only.\n";

    return $prompt;
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
    if (self::should_use_identity_composite($brief)) {
        $composite_path = self::create_layered_campaign_master($brief, $draft_id, $variant, $index);
        if ($composite_path && file_exists($composite_path)) {
            return $composite_path;
        }

        error_log('CMSG LAYERED QUALITY FAIL: layered_campaign_failed_closed draft_id=' . intval($draft_id) . ' variant=' . sanitize_key($variant));
        return '';
    }

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
    $variant = sanitize_key($variant ?: 'vertical');
    $out_path = self::preview_clean_file_path($draft_id, $variant, $index);
    $campaign_id = sanitize_key('draft-' . intval($draft_id) . '-' . $variant . '-' . intval($index) . '-' . substr(md5($out_path), 0, 10));
    $cast_assets = self::cast_actor_assets($brief);

    error_log('CMSG LAYERED CAMPAIGN START: campaign_id=' . $campaign_id . ' draft_id=' . intval($draft_id) . ' variant=' . $variant . ' cast_count=' . count($cast_assets));

    if (empty($cast_assets)) {
        error_log('CMSG LAYERED QUALITY FAIL: no_cast_assets campaign_id=' . $campaign_id);
        return '';
    }

    $layer_dir = self::identity_composite_layer_dir($out_path);
    $background_path = trailingslashit($layer_dir) . 'background_base.png';
    $manifest_path = self::campaign_manifest_path($out_path);

    $background = self::generate_background_plate_only($brief, $variant, '1024x1536', $background_path);
    if (!$background) {
        error_log('CMSG LAYERED QUALITY FAIL: background_generation_failed campaign_id=' . $campaign_id);
        return '';
    }

    self::resize_png_cover_no_overlay($background, $background, 900, 1285);

    $actor_layers = self::prepare_campaign_actor_cutouts($brief, $layer_dir);
    if (count($actor_layers) !== count($cast_assets)) {
        error_log('CMSG LAYERED QUALITY FAIL: actor_layer_count_mismatch campaign_id=' . $campaign_id . ' expected=' . count($cast_assets) . ' prepared=' . count($actor_layers));
        return '';
    }

    $manifest = self::build_campaign_layer_manifest($brief, $background, $actor_layers, $variant);
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

    if (!self::write_campaign_manifest_json($manifest, $manifest_path)) {
        error_log('CMSG LAYERED QUALITY FAIL: manifest_write_failed campaign_id=' . $campaign_id . ' path=' . $manifest_path);
        return '';
    }

    if (!self::composite_layered_campaign($manifest, $out_path)) {
        error_log('CMSG LAYERED QUALITY FAIL: composite_failed campaign_id=' . $campaign_id);
        return '';
    }

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

private static function generate_background_plate_only($brief, $variant, $openai_size, $out_path) {
    $variant = sanitize_key($variant ?: 'vertical');
    $background_brief = self::background_only_brief($brief);

    for ($attempt = 1; $attempt <= self::LAYERED_CAMPAIGN_BACKGROUND_RETRIES; $attempt++) {
        error_log('CMSG LAYERED BACKGROUND START: variant=' . $variant . ' attempt=' . intval($attempt) . ' out=' . $out_path);
        if (!self::generate_background_only_file($background_brief, $out_path, $variant, $openai_size)) {
            error_log('CMSG LAYERED BACKGROUND REJECTED: generation_failed variant=' . $variant . ' attempt=' . intval($attempt));
            continue;
        }

        error_log('CMSG LAYERED BACKGROUND GENERATED: variant=' . $variant . ' attempt=' . intval($attempt) . ' path=' . $out_path);
        $validation = self::validate_background_has_no_people($out_path, $brief);
        if (!empty($validation['ok'])) {
            return $out_path;
        }

        error_log('CMSG LAYERED BACKGROUND HUMAN CONTENT DETECTED variant=' . $variant . ' attempt=' . intval($attempt) . ' result=' . wp_json_encode($validation));
        error_log('CMSG LAYERED BACKGROUND REJECTED: human_detected variant=' . $variant . ' attempt=' . intval($attempt) . ' result=' . wp_json_encode($validation));
        @unlink($out_path);
    }

    return '';
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

private static function validate_background_has_no_people($background_path, $brief) {
    if (!is_string($background_path) || !file_exists($background_path)) {
        return self::write_background_validation_report($background_path, [
            'ok' => false,
            'reason' => 'missing_background',
        ]);
    }

    $script = plugin_dir_path(dirname(__FILE__)) . 'tools/detect-duplicate-faces.py';
    if (!file_exists($script)) {
        error_log('CMSG LAYERED BACKGROUND VALIDATION FAILED CLOSED: face_detector_missing path=' . $script);
        return self::write_background_validation_report($background_path, [
            'ok' => false,
            'reason' => 'face_detector_missing',
            'detector_path' => $script,
        ]);
    }

    $python = file_exists('/opt/cmsg-bgremove/bin/python') ? '/opt/cmsg-bgremove/bin/python' : 'python3';
    $cmd = escapeshellcmd($python)
        . ' ' . escapeshellarg($script)
        . ' ' . escapeshellarg($background_path)
        . ' 0'
        . ' ' . escapeshellarg((string)self::$duplicate_face_similarity_threshold)
        . ' 2>&1';
    $raw = shell_exec($cmd);
    $decoded = json_decode(trim((string)$raw), true);

    if (!is_array($decoded) || empty($decoded['ok'])) {
        error_log('CMSG LAYERED BACKGROUND VALIDATION FAILED CLOSED: face_detector_unavailable raw=' . (is_string($raw) ? substr($raw, 0, 300) : ''));
        return self::write_background_validation_report($background_path, [
            'ok' => false,
            'reason' => 'face_detector_unavailable',
            'detector_path' => $script,
            'raw' => is_string($raw) ? substr($raw, 0, 1000) : '',
            'decoded' => is_array($decoded) ? $decoded : null,
        ]);
    }

    $face_count = (int)($decoded['face_count'] ?? ($decoded['detected_face_count'] ?? 0));
    if ($face_count > 0) {
        error_log('CMSG LAYERED BACKGROUND HUMAN CONTENT DETECTED faces=' . $face_count . ' path=' . $background_path);
        return self::write_background_validation_report($background_path, [
            'ok' => false,
            'reason' => 'face_detected',
            'faces_detected' => $face_count,
            'people_detected' => 0,
            'silhouettes_detected' => 0,
            'detector' => $decoded['method'] ?? 'unknown',
            'face_detector' => $decoded,
        ]);
    }

    $person_script = plugin_dir_path(dirname(__FILE__)) . 'tools/detect-people.py';
    if (!file_exists($person_script)) {
        error_log('CMSG LAYERED BACKGROUND VALIDATION FAILED CLOSED: people_detector_missing path=' . $person_script);
        return self::write_background_validation_report($background_path, [
            'ok' => false,
            'reason' => 'people_detector_missing',
            'faces_detected' => 0,
            'people_detected' => 0,
            'silhouettes_detected' => 0,
            'face_detector' => $decoded,
            'detector_path' => $person_script,
        ]);
    }

    $person_cmd = escapeshellcmd($python)
        . ' ' . escapeshellarg($person_script)
        . ' ' . escapeshellarg($background_path)
        . ' 2>&1';
    $person_raw = shell_exec($person_cmd);
    $person_decoded = json_decode(trim((string)$person_raw), true);
    if (!is_array($person_decoded) || empty($person_decoded['ok'])) {
        error_log('CMSG LAYERED BACKGROUND VALIDATION FAILED CLOSED: people_detector_unavailable raw=' . (is_string($person_raw) ? substr($person_raw, 0, 300) : ''));
        return self::write_background_validation_report($background_path, [
            'ok' => false,
            'reason' => 'people_detector_unavailable',
            'faces_detected' => 0,
            'people_detected' => 0,
            'silhouettes_detected' => 0,
            'face_detector' => $decoded,
            'raw' => is_string($person_raw) ? substr($person_raw, 0, 1000) : '',
            'decoded' => is_array($person_decoded) ? $person_decoded : null,
        ]);
    }

    $person_count = (int)($person_decoded['person_count'] ?? ($person_decoded['people_detected'] ?? 0));
    $silhouette_count = (int)($person_decoded['silhouette_count'] ?? ($person_decoded['silhouettes_detected'] ?? 0));
    if ($person_count > 0 || $silhouette_count > 0) {
        error_log('CMSG LAYERED BACKGROUND HUMAN CONTENT DETECTED people=' . $person_count . ' silhouettes=' . $silhouette_count . ' path=' . $background_path);
        return self::write_background_validation_report($background_path, [
            'ok' => false,
            'reason' => 'person_or_silhouette_detected',
            'faces_detected' => 0,
            'people_detected' => $person_count,
            'silhouettes_detected' => $silhouette_count,
            'face_detector' => $decoded,
            'people_detector' => $person_decoded,
        ]);
    }

    return self::write_background_validation_report($background_path, [
        'ok' => true,
        'reason' => 'no_human_content_detected',
        'faces_detected' => 0,
        'people_detected' => 0,
        'silhouettes_detected' => 0,
        'face_detector' => $decoded,
        'people_detector' => $person_decoded,
        'detector' => $decoded['method'] ?? 'unknown',
    ]);
}

private static function prepare_campaign_actor_cutouts($brief, $layer_dir) {
    $assets = self::cast_actor_assets($brief);
    $layers = self::prepare_identity_actor_layers($assets, $layer_dir);
    foreach ($layers as $layer) {
        error_log('CMSG LAYERED ACTOR CUTOUT: actor=' . sanitize_text_field($layer['label'] ?? '') . ' source=' . sanitize_text_field($layer['source'] ?? '') . ' layer=' . sanitize_text_field($layer['layer'] ?? '') . ' fallback=' . (!empty($layer['fallback_used']) ? 'yes' : 'no'));
    }
    return $layers;
}

private static function build_campaign_layer_manifest($brief, $background_path, $actor_layers, $variant) {
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
    foreach (array_values($actor_layers) as $i => $layer) {
        $actor_index = (int)($layer['index'] ?? $i);
        if (isset($seen_indexes[$actor_index])) {
            error_log('CMSG LAYERED DUPLICATE ACTOR SKIPPED: actor_index=' . $actor_index . ' layer=' . sanitize_text_field($layer['layer'] ?? ''));
            continue;
        }
        $seen_indexes[$actor_index] = true;
        $actor_id = self::campaign_actor_id($actor_index);
        $slot = $slots[$actor_id] ?? ['x' => 0.50, 'y' => 0.15, 'w' => 0.36, 'h' => 0.46, 'z_index' => $i + 10];
        $manifest['layers'][] = [
            'type' => 'actor',
            'actor_id' => $actor_id,
            'actor_index' => $actor_index,
            'actor_label' => self::actor_layer_label($actor_index),
            'source_path' => $layer['source'] ?? '',
            'source_hash' => self::campaign_file_hash($layer['source'] ?? ''),
            'cutout_path' => $layer['cutout'] ?? '',
            'layer_path' => $layer['layer'] ?? '',
            'fallback_used' => !empty($layer['fallback_used']),
            'x' => (float)($slot['x'] ?? 0.50),
            'y' => (float)($slot['y'] ?? 0.15),
            'w' => (float)($slot['w'] ?? 0.36),
            'h' => (float)($slot['h'] ?? 0.46),
            'z' => (int)($slot['z_index'] ?? ($i + 10)),
            'role' => sanitize_key($slot['role'] ?? ''),
            'locked_identity' => true,
            'placement_locked' => true,
            'scale_locked' => true,
            'depth_locked' => true,
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

private static function campaign_slots_from_manifest($manifest, $variant) {
    $variant = sanitize_key($variant ?: 'vertical');
    $slots = [];
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
        $layer_path = (string)($layer['layer_path'] ?? '');
        $cutout_path = (string)($layer['cutout_path'] ?? '');
        $render_path = ($layer_path !== '' && file_exists($layer_path)) ? $layer_path : $cutout_path;

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
            'cutout' => $cutout_path,
            'cutout_path' => $cutout_path,
            'layer' => $render_path,
            'layer_path' => $render_path,
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

    $slots = self::unique_campaign_slots_for_variant($manifest, $variant, $actor_layers);
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
            'cutout_path' => (string)($layer['cutout_path'] ?? ($layer['cutout'] ?? '')),
            'layer_path' => $layer_path,
            'layer_key' => $layer_key,
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

private static function write_campaign_render_audit($audit, $output_path) {
    $path = self::placement_audit_path($output_path);
    if (!$path) return false;
    $audit['ok'] = self::campaign_render_audit_validate($audit, $audit['variant'] ?? '');
    file_put_contents($path, wp_json_encode($audit, JSON_PRETTY_PRINT));
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
            'instruction' => '',
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
    $seen = [];
    $add_asset = function($path) use (&$assets, &$seen) {
        if (!is_string($path) || $path === '' || !file_exists($path)) {
            return;
        }

        $real = realpath($path);
        $key = $real ? $real : $path;

        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $assets[] = $path;
    };

    foreach (self::normalized_cast_members($brief) as $member) {
        $add_asset($member['image'] ?? '');
    }

    foreach (['cast_actor_1', 'cast_actor_2', 'cast_actor_3'] as $key) {
        $add_asset($brief[$key] ?? '');
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

        $layers = self::prepare_identity_actor_layers($valid_assets, $layer_dir);
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
    if (!is_string($asset_path) || !file_exists($asset_path)) {
        return false;
    }

    $script = plugin_dir_path(dirname(__FILE__)) . 'tools/remove-bg.py';
    if (!file_exists($script)) {
        error_log('CMSG POSTER CUTOUT ERROR: remove-bg.py missing at ' . $script);
        return false;
    }

    $python = '/opt/cmsg-bgremove/bin/python';
    if (!file_exists($python)) {
        $python = file_exists('/opt/cmsg-bgremove/bin/python3') ? '/opt/cmsg-bgremove/bin/python3' : 'python3';
    }

    $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($asset_path) . ' ' . escapeshellarg($out) . ' 2>&1';
    error_log('CMSG POSTER CUTOUT CMD: ' . $cmd);
    $result = shell_exec($cmd);

    if (!file_exists($out) || filesize($out) <= 0) {
        error_log('CMSG COMPOSITE CUTOUT FAILED source=' . $asset_path . ' reason=missing_output result=' . print_r($result, true));
        return false;
    }

    $analysis = self::analyze_actor_layer($out);
    if (empty($analysis['ok'])) {
        error_log('CMSG COMPOSITE CUTOUT FAILED source=' . $asset_path . ' out=' . $out . ' report=' . wp_json_encode($analysis));
        @unlink($out);
        return false;
    }

    @chmod($out, 0664);
    error_log('CMSG COMPOSITE CUTOUT SUCCESS source=' . $asset_path . ' out=' . $out . ' report=' . wp_json_encode($analysis));
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

private static function prepare_identity_actor_layers($assets, $layer_dir) {
    $layers = [];

    foreach (array_values($assets) as $i => $asset_path) {
        $label = self::actor_layer_label($i);
        $cutout_path = trailingslashit($layer_dir) . 'actor_' . $label . '_cutout.png';
        $layer_path = trailingslashit($layer_dir) . 'actor_' . $label . '_layer.png';
        $ok = self::remove_actor_background_to_layer($asset_path, $cutout_path);
        $fallback_used = false;

        if (!$ok) {
            error_log('CMSG COMPOSITE CUTOUT FALLBACK_USED source=' . $asset_path . ' cutout=' . $cutout_path . ' layer=' . $layer_path);
            $ok = self::create_masked_portrait_layer($asset_path, $layer_path);
            $fallback_used = true;
            if ($ok) {
                @copy($layer_path, $cutout_path);
                @chmod($cutout_path, 0664);
            }
        } else {
            $analysis = self::analyze_actor_layer($cutout_path);
            $ok = self::crop_actor_layer_to_subject($cutout_path, $layer_path, $analysis);
        }

        if (!$ok || !file_exists($layer_path) || filesize($layer_path) <= 0) {
            error_log('CMSG POSTER IDENTITY LAYER ERROR: failed_to_prepare_actor index=' . intval($i) . ' source=' . $asset_path);
            continue;
        }

        $layer_report = self::analyze_actor_layer($layer_path);
        if (empty($layer_report['ok'])) {
            error_log('CMSG POSTER IDENTITY LAYER ERROR: invalid_actor_layer index=' . intval($i) . ' source=' . $asset_path . ' report=' . wp_json_encode($layer_report));
            continue;
        }

        $layers[] = [
            'index' => $i,
            'label' => $label,
            'source' => $asset_path,
            'cutout' => $cutout_path,
            'layer' => $layer_path,
            'fallback_used' => $fallback_used,
            'analysis' => $layer_report,
        ];
    }

    return $layers;
}

private static function analyze_actor_layer($path) {
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
        return $report;
    }

    $img = @imagecreatefrompng($path);
    if (!$img) {
        $report['error'] = 'not_readable_png';
        return $report;
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $report['width'] = $w;
    $report['height'] = $h;
    if ($w <= 0 || $h <= 0) {
        imagedestroy($img);
        $report['error'] = 'empty_dimensions';
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

    self::feather_layer_edges($crop, 20, 0.82);
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

    $edge_px = max(1, (int)$edge_px);
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
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) return false;

    $actor = self::gd_load_image($asset_path);
    if (!$actor) return false;

    $layer_w = 720;
    $layer_h = 1125;
    $actor_w = imagesx($actor);
    $actor_h = imagesy($actor);
    if ($actor_w <= 0 || $actor_h <= 0) {
        imagedestroy($actor);
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

    imagecopyresampled($layer, $actor, 0, 0, $src_x, $src_y, $layer_w, $layer_h, $src_w, $src_h);
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

    imagepng($layer, $out, 6);
    imagedestroy($layer);
    @chmod($out, 0664);
    return file_exists($out) && filesize($out) > 0;
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

private static function composite_campaign_placement_map($background_path, $placement_map, $variant, $composite_path) {
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
        $layer_path = (string)($placement_info['layer_path'] ?? '');
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

        $rendered = [
            'actor' => sanitize_text_field($placement_info['label'] ?? self::actor_layer_label($actor_index)),
            'actor_id' => $actor_id,
            'index' => $actor_index,
            'actor_index' => $actor_index,
            'source' => (string)($placement_info['source_path'] ?? ''),
            'cutout' => (string)($placement_info['cutout_path'] ?? ''),
            'layer' => $layer_path,
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
        imagefilter($canvas, IMG_FILTER_COLORIZE, 10, 4, -8, 0);
        imagepng($canvas, $composite_path, 6);
        @chmod($composite_path, 0664);
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
        imagefilter($canvas, IMG_FILTER_COLORIZE, 10, 4, -8, 0);
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

     private static function call_image_generation($api_key, $prompt, $size = '1024x1024') {
        if (self::$in_final_generation) {
            self::$final_openai_calls++;
            error_log('CMSG POSTER FINAL TRACE: OPENAI_IMAGE_GENERATION_CALLED_DURING_FINALIZATION size=' . $size);
        }

        return wp_remote_post('https://api.openai.com/v1/images/generations', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'  => 'gpt-image-1',
                'prompt' => $prompt,
                'size'   => $size,
            ]),
            'timeout' => 180,
        ]);
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
