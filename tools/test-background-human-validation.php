<?php
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once dirname(__DIR__) . '/includes/poster-ai.php';

$people_method = new ReflectionMethod('CMSG_Poster_AI', 'background_people_detection_decision');
$people_method->setAccessible(true);
$face_method = new ReflectionMethod('CMSG_Poster_AI', 'background_face_detection_decision');
$face_method->setAccessible(true);

$failures = [];

$assert_case = function($name, $method, $payload, $expected) use (&$failures) {
    $result = $method->invoke(null, $payload);
    foreach ($expected as $key => $expected_value) {
        $actual_value = $result[$key] ?? null;
        if ($actual_value !== $expected_value) {
            $failures[] = [
                'case' => $name,
                'field' => $key,
                'expected' => $expected_value,
                'actual' => $actual_value,
                'result' => $result,
            ];
            return;
        }
    }
    echo 'PASS ' . $name . PHP_EOL;
};

$assert_case('low_confidence_person_allowed', $people_method, [
    'ok' => true,
    'person_count' => 1,
    'silhouette_count' => 0,
    'people' => [
        ['bbox' => [10, 20, 80, 180], 'score' => 0.6099],
    ],
], [
    'ok' => true,
    'decision' => 'ALLOW',
    'reason' => 'low_confidence_person_detection',
    'retry_required' => false,
    'false_positive' => true,
    'highest_person_score' => 0.6099,
]);

$assert_case('strong_person_rejected', $people_method, [
    'ok' => true,
    'person_count' => 1,
    'silhouette_count' => 0,
    'people' => [
        ['bbox' => [10, 20, 80, 180], 'score' => 0.89],
    ],
], [
    'ok' => false,
    'decision' => 'REJECT',
    'reason' => 'strong_person_detected',
    'retry_required' => true,
    'false_positive' => false,
    'highest_person_score' => 0.89,
]);

$assert_case('silhouette_rejected', $people_method, [
    'ok' => true,
    'person_count' => 0,
    'silhouette_count' => 1,
    'people' => [],
], [
    'ok' => false,
    'decision' => 'REJECT',
    'reason' => 'silhouette_detected',
]);

$assert_case('face_detected_rejected', $face_method, [
    'ok' => true,
    'face_count' => 1,
], [
    'ok' => false,
    'decision' => 'REJECT',
    'reason' => 'face_detected',
]);

$assert_case('detector_unavailable_fails_closed', $people_method, [
    'ok' => false,
    'error' => 'missing_dependency',
], [
    'ok' => false,
    'decision' => 'REJECT',
    'reason' => 'people_detector_unavailable',
    'retry_required' => true,
]);

$assert_case('mixed_scores_rejects_any_strong_person', $people_method, [
    'ok' => true,
    'person_count' => 2,
    'silhouette_count' => 0,
    'people' => [
        ['bbox' => [10, 20, 80, 180], 'score' => 0.58],
        ['bbox' => [120, 30, 190, 220], 'score' => 0.81],
    ],
], [
    'ok' => false,
    'decision' => 'REJECT',
    'reason' => 'strong_person_detected',
    'highest_person_score' => 0.81,
]);

$assert_case('no_people_allowed', $people_method, [
    'ok' => true,
    'person_count' => 0,
    'silhouette_count' => 0,
    'people' => [],
], [
    'ok' => true,
    'decision' => 'ALLOW',
    'reason' => 'no_human_content_detected',
    'highest_person_score' => null,
    'all_scores' => [],
]);

$assert_case('multiple_low_confidence_people_allowed', $people_method, [
    'ok' => true,
    'person_count' => 2,
    'silhouette_count' => 0,
    'people' => [
        ['bbox' => [10, 20, 80, 180], 'score' => 0.58],
        ['bbox' => [120, 30, 190, 220], 'score' => 0.61],
    ],
], [
    'ok' => true,
    'decision' => 'ALLOW',
    'reason' => 'low_confidence_person_detection',
    'retry_required' => false,
    'false_positive' => true,
    'highest_person_score' => 0.61,
]);

$assert_case('malformed_people_entries_do_not_hide_valid_strong_detection', $people_method, [
    'ok' => true,
    'person_count' => 3,
    'silhouette_count' => 0,
    'people' => [
        ['bbox' => [1, 1, 3, 3]],
        'not-an-array',
        ['bbox' => [120, 30, 190, 220], 'score' => 0.82],
    ],
], [
    'ok' => false,
    'decision' => 'REJECT',
    'reason' => 'strong_person_detected',
    'highest_person_score' => 0.82,
]);

if ($failures) {
    fwrite(STDERR, json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
