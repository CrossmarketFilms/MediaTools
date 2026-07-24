<?php
if (!defined('ABSPATH')) { exit; }

final class CMSG_Poster_Story_Analyzer {
    public static function analyze($brief, $actor_registry) {
        $brief = is_array($brief) ? $brief : [];
        $canonical_registry = self::canonical_actor_registry($actor_registry);
        $description = self::field($brief, ['poster_description', 'description', 'scene_direction', 'cast_scene_instruction']);
        $synopsis = self::field($brief, ['synopsis', 'movie_synopsis', 'logline']);
        $all_text = trim(implode("\n", array_filter([
            self::field($brief, ['title', 'movie_title', 'poster_title']),
            self::field($brief, ['tagline']),
            $synopsis,
            $description,
            self::field($brief, ['genre']),
            self::field($brief, ['tone', 'mood']),
            self::field($brief, ['setting']),
            self::field($brief, ['period']),
            self::field($brief, ['target_audience', 'audience']),
        ])));

        $actors = self::actors($brief, $canonical_registry);
        return [
            'source_inputs' => [
                'title' => self::field($brief, ['title', 'movie_title', 'poster_title']),
                'tagline' => self::field($brief, ['tagline']),
                'synopsis' => $synopsis,
                'poster_description' => $description,
                'genre' => self::list_field($brief, ['genre']),
                'tone' => self::list_field($brief, ['tone']),
                'mood' => self::list_field($brief, ['mood']),
                'setting' => self::setting_terms($brief, $all_text),
                'period' => self::field($brief, ['period']),
                'audience' => self::list_field($brief, ['target_audience', 'audience']),
                'visual_references' => self::visual_references($brief),
                'props' => self::props($brief, $description),
                'title_directions' => self::title_directions($brief, $all_text),
                'color_directions' => self::color_terms($brief, $all_text),
            ],
            'actors' => $actors,
            'actor_registry' => $canonical_registry,
            'relationships' => self::relationship_terms($description),
            'keywords' => self::keywords($all_text),
            'story_signals' => self::story_signals($all_text),
            'unresolved' => self::unresolved($description),
            'conflicts' => self::conflicts($description, $actors),
        ];
    }

    public static function canonical_actor_registry($actor_registry) {
        $rows = [];
        $seen = [];
        foreach ((array)$actor_registry as $row) {
            if (!is_array($row)) continue;
            if (array_key_exists('accepted_as_actor', $row) && empty($row['accepted_as_actor'])) continue;
            $actor_id = self::text($row['actor_id'] ?? '');
            $actor_index = isset($row['actor_index']) ? (int)$row['actor_index'] : count($rows);
            if ($actor_id === '') {
                $actor_id = self::actor_id($actor_index);
            }
            $source_path = self::text($row['source_path'] ?? '');
            $source_basename = self::text($row['source_basename'] ?? '');
            $source_key = self::text($row['source_key'] ?? '');
            $source_identifier = $source_path !== '' ? $source_path : $source_basename;
            $registry_key = self::registry_key($actor_id, $actor_index, $source_key, $source_identifier);
            if (isset($seen[$registry_key])) continue;
            $seen[$registry_key] = true;
            $rows[] = [
                'actor_id' => $actor_id,
                'actor_index' => $actor_index,
                'registry_index' => $actor_index,
                'source_key' => $source_key,
                'source_path' => self::redacted_source_identifier($source_identifier),
                'source_hash' => self::hash_value($source_identifier),
                'source_type' => self::text($row['source_type'] ?? 'principal_cast'),
                'registry_key' => $registry_key,
            ];
        }
        usort($rows, function($a, $b) {
            return ((int)$a['actor_index']) <=> ((int)$b['actor_index']);
        });
        return $rows;
    }

    private static function actors($brief, $canonical_registry) {
        $members = self::cast_members($brief);
        $actors = [];

        foreach ((array)$canonical_registry as $registry_index => $record) {
            $match = self::match_cast_member($members, $record, $registry_index);
            $member = $match['member'];
            $role = self::role($member['role'] ?? ($registry_index < 2 ? 'lead' : 'supporting'));
            $instruction = self::text($member['instruction'] ?? '');
            $actors[] = [
                'actor_id' => $record['actor_id'],
                'registry_index' => (int)$record['registry_index'],
                'actor_index' => (int)$record['actor_index'],
                'name' => self::text($member['name'] ?? ''),
                'role' => $role,
                'visual_priority' => $role === 'lead' ? ($registry_index === 0 ? 1 : 2) : min(10, $registry_index + 1),
                'source_instruction' => $instruction,
                'source_instruction_hash' => self::hash_value($instruction),
                'source_scope' => $record['actor_id'],
                'target_actor_id' => $record['actor_id'],
                'source_key' => $record['source_key'],
                'source_path' => $record['source_path'],
                'source_type' => $record['source_type'],
                'registry_match_method' => $match['method'],
                'registry_key' => $record['registry_key'],
                'parsed_directives' => self::parse_actor_directives($instruction, $record['actor_id']),
            ];
        }

        return $actors;
    }

    private static function match_cast_member($members, $record, $fallback_index) {
        $source_key = (string)($record['source_key'] ?? '');
        if (preg_match('/cast_members_(\d+)_image/', $source_key, $m)) {
            $index = (int)$m[1];
            if (isset($members[$index])) {
                return ['member' => $members[$index], 'method' => 'source_key_index'];
            }
        }
        if (preg_match('/cast_actor_(\d+)/', $source_key, $m)) {
            $index = (int)$m[1] - 1;
            if (isset($members[$index])) {
                return ['member' => $members[$index], 'method' => 'legacy_source_key_index'];
            }
        }
        if (isset($members[$fallback_index])) {
            return ['member' => $members[$fallback_index], 'method' => 'registry_index_fallback'];
        }
        return ['member' => [], 'method' => 'registry_only'];
    }

    private static function cast_members($brief) {
        $members = [];
        if (!empty($brief['cast_members']) && is_array($brief['cast_members'])) {
            foreach ($brief['cast_members'] as $index => $member) {
                if (!is_array($member)) continue;
                $row = [
                    'name' => self::text($member['name'] ?? ''),
                    'role' => self::role($member['role'] ?? ((int)$index < 2 ? 'lead' : 'supporting')),
                    'instruction' => self::text($member['instruction'] ?? ''),
                    'image' => self::text($member['image'] ?? ''),
                    'source_type' => self::text($member['source_type'] ?? 'principal_cast'),
                ];
                if ($row['name'] !== '' || $row['instruction'] !== '' || $row['image'] !== '') {
                    $members[] = $row;
                }
            }
        }
        if (!empty($members)) return array_slice($members, 0, 10);

        for ($i = 1; $i <= 10; $i++) {
            $instruction = self::field($brief, ['cast_actor_' . $i . '_instruction']);
            $image = self::field($brief, ['cast_actor_' . $i]);
            if ($instruction === '' && $image === '') continue;
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

    private static function props($brief, $text) {
        $props = [];
        foreach ((array)($brief['poster_asset_references'] ?? []) as $ref) {
            if (!is_array($ref)) continue;
            $label = self::text($ref['description'] ?? ($ref['name'] ?? ($ref['type'] ?? 'visual reference')));
            if ($label !== '') $props[] = $label;
        }
        foreach ((array)($brief['poster_assets'] ?? []) as $asset) {
            if (is_array($asset)) {
                $label = self::text($asset['description'] ?? ($asset['name'] ?? ($asset['type'] ?? 'poster asset')));
                if ($label !== '') $props[] = $label;
            }
        }
        $known = ['car', 'vehicle', 'rose', 'weapon', 'gun', 'church', 'building', 'logo', 'product', 'heart', 'skyline', 'village', 'forest', 'city'];
        $lower = strtolower($text);
        foreach ($known as $word) {
            if (strpos($lower, $word) !== false) $props[] = $word;
        }
        return array_values(array_unique(array_filter($props)));
    }

    private static function setting_terms($brief, $text) {
        $terms = self::list_field($brief, ['setting']);
        $lower = strtolower($text);
        $map = [
            'church' => 'church',
            'village' => 'period village',
            'lagos' => 'Lagos',
            'city' => 'city',
            'skyline' => 'skyline',
            'forest' => 'forest',
            'apartment' => 'apartment',
            'road' => 'road',
            'rooftop' => 'rooftop',
            'market' => 'market',
            'school' => 'school',
        ];
        foreach ($map as $needle => $label) {
            if (strpos($lower, $needle) !== false) $terms[] = $label;
        }
        return array_values(array_unique(array_filter($terms)));
    }

    private static function color_terms($brief, $text) {
        $terms = self::list_field($brief, ['color_direction', 'color_directions', 'palette']);
        $lower = strtolower($text);
        foreach (['gold', 'blue', 'red', 'black', 'white', 'green', 'orange', 'warm', 'cool', 'noir', 'monochrome'] as $word) {
            if (strpos($lower, $word) !== false) $terms[] = $word;
        }
        return array_values(array_unique(array_filter($terms)));
    }

    private static function title_directions($brief, $text) {
        $terms = self::list_field($brief, ['title_placement', 'title_direction', 'title_directions']);
        $lower = strtolower($text);
        foreach (['bottom', 'top', 'center', 'large title', 'small title', 'title safe'] as $word) {
            if (strpos($lower, $word) !== false) $terms[] = $word;
        }
        return array_values(array_unique(array_filter($terms)));
    }

    private static function visual_references($brief) {
        $refs = [];
        foreach ((array)($brief['poster_asset_references'] ?? []) as $ref) {
            if (!is_array($ref)) continue;
            $refs[] = [
                'type' => self::text($ref['type'] ?? 'visual_reference'),
                'description' => self::text($ref['description'] ?? ''),
                'image' => self::redacted_source_identifier(self::text($ref['image'] ?? '')),
            ];
        }
        if (!empty($brief['style_reference'])) {
            $refs[] = [
                'type' => 'style_reference',
                'description' => 'style reference',
                'image' => self::redacted_source_identifier(self::text($brief['style_reference'])),
            ];
        }
        return $refs;
    }

    private static function story_signals($text) {
        $lower = strtolower($text);
        $signals = [];
        $map = [
            'love' => 'romance',
            'betray' => 'betrayal',
            'fight' => 'conflict',
            'chase' => 'pursuit',
            'sad' => 'grief',
            'disappointed' => 'disappointment',
            'angry' => 'anger',
            'family' => 'family',
            'secret' => 'secret',
            'god' => 'faith',
        ];
        foreach ($map as $needle => $signal) {
            if (strpos($lower, $needle) !== false) $signals[] = $signal;
        }
        return array_values(array_unique($signals));
    }

    private static function relationship_terms($text) {
        $relationships = [];
        if (preg_match_all('/Actor\s+(\d+)\s+([^.\n]+)/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $relationships[] = trim('Actor ' . $match[1] . ' ' . $match[2]);
            }
        }
        return array_values(array_unique($relationships));
    }

    private static function parse_actor_directives($instruction, $actor_id) {
        if ($instruction === '') return [];
        $directives = [];
        foreach (preg_split('/[.;\n]+/', $instruction) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $directives[] = [
                'text' => $part,
                'source_scope' => $actor_id,
                'target_actor_id' => $actor_id,
                'source_instruction_hash' => self::hash_value($part),
            ];
        }
        return $directives;
    }

    private static function unresolved($description) {
        $items = [];
        if (preg_match_all('/\b(something|somehow|maybe|unsure|not sure|etc)\b/i', $description, $matches)) {
            foreach ($matches[0] as $match) $items[] = 'Ambiguous wording: ' . $match;
        }
        return array_values(array_unique($items));
    }

    private static function conflicts($description, $actors) {
        $conflicts = [];
        $lower = strtolower($description);
        if (strpos($lower, 'no actors') !== false && count($actors) > 0) $conflicts[] = 'Brief requests no actors but principal cast references are present.';
        if (strpos($lower, 'background only') !== false && count($actors) > 0) $conflicts[] = 'Brief requests background only but principal cast references are present.';
        return $conflicts;
    }

    private static function keywords($text) {
        $words = preg_split('/[^a-z0-9\']+/i', strtolower($text));
        $stop = array_flip(['the','and','with','that','this','from','into','onto','actor','actors','poster','movie','film','between','should','where','they','their','there']);
        $counts = [];
        foreach ($words as $word) {
            if (strlen($word) < 4 || isset($stop[$word])) continue;
            $counts[$word] = ($counts[$word] ?? 0) + 1;
        }
        arsort($counts);
        return array_slice(array_keys($counts), 0, 20);
    }

    private static function list_field($brief, $keys) {
        foreach ($keys as $key) {
            if (!isset($brief[$key])) continue;
            $value = $brief[$key];
            if (is_array($value)) return array_values(array_filter(array_map([self::class, 'text'], $value)));
            $text = self::text($value);
            if ($text !== '') return array_values(array_filter(array_map('trim', preg_split('/[,|]+/', $text))));
        }
        return [];
    }

    private static function field($brief, $keys) {
        foreach ($keys as $key) {
            if (isset($brief[$key])) {
                $text = self::text($brief[$key]);
                if ($text !== '') return $text;
            }
        }
        return '';
    }

    private static function role($role) {
        $role = strtolower(self::text($role));
        return in_array($role, ['lead', 'principal', 'lead_character'], true) ? 'lead' : 'supporting';
    }

    private static function registry_key($actor_id, $actor_index, $source_key, $source_path) {
        if ($actor_id !== '') return 'actor_id:' . $actor_id;
        if ($source_key !== '') return 'index_source:' . (int)$actor_index . ':' . $source_key;
        return 'source_hash:' . self::hash_value($source_path);
    }

    private static function redacted_source_identifier($path) {
        $path = self::text($path);
        if ($path === '') return '';
        return basename(str_replace('\\', '/', $path));
    }

    private static function hash_value($value) {
        return substr(hash('sha256', (string)$value), 0, 16);
    }

    private static function actor_id($index) {
        return 'actor_' . chr(65 + (int)$index);
    }

    private static function text($value) {
        if (is_array($value) || is_object($value)) return '';
        $value = trim((string)$value);
        if (function_exists('sanitize_text_field')) return sanitize_text_field($value);
        return preg_replace('/[\x00-\x1F\x7F]/', '', $value);
    }
}
