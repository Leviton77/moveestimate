<?php

define('ABSPATH', __DIR__ . '/');
define('TME_DIR', dirname(__DIR__) . '/work/phase2a-rc2-source/tom-moving-estimate-rc2/');

if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $start, ?int $length = null): string
    {
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}

function sanitize_key(string $value): string
{
    return preg_replace('/[^a-z0-9_-]/', '', strtolower($value));
}

function sanitize_text_field(string $value): string
{
    return trim(strip_tags(preg_replace('/[\r\n\t]+/', ' ', $value)));
}

function sanitize_textarea_field(string $value): string
{
    return trim(strip_tags($value));
}

function absint($value): int
{
    return abs((int) $value);
}

function wp_json_encode($value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
}

function __($value): string
{
    return $value;
}

final class WP_Error
{
    public function __construct(public string $code, public string $message)
    {
    }
}

function is_wp_error($value): bool
{
    return $value instanceof WP_Error;
}

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

require TME_DIR . 'includes/class-tme-ai-report.php';

$report = TME_AI_Report::synthetic_example(99);
test_assert(!is_wp_error($report), 'Synthetic report did not load.');
test_assert($report['analysis']['session_id'] === 99, 'Synthetic report session ID was not replaced.');
test_assert($report['analysis']['model'] === 'synthetic-example', 'Synthetic model marker is missing.');
test_assert($report['mattress_bags']['total_bags'] === 2, 'Mattress-bag total is incorrect.');
test_assert($report['disassembly_plan']['totals']['likely'] === 3, 'Disassembly total is incorrect.');
test_assert($report['summary']['unresolved_questions'] === 4, 'Question total is incorrect.');

$edited = $report;
$edited['mattress_bags']['by_size'][0]['mattress_bags'] = 2;
$edited['rooms'][0]['inventory'][0]['quantity'] = 2;
$prepared = TME_AI_Report::prepare_for_storage($edited, $report, 42, false, false);
test_assert($prepared['status'] === 'needs_review', 'Edited report should need review.');
test_assert($prepared['changed'] === true, 'Edits were not detected.');
test_assert($prepared['report']['mattress_bags']['total_bags'] === 3, 'Mattress bags were not recalculated.');
test_assert($prepared['report']['summary']['moving_furniture_pieces'] === 5, 'Moving inventory was not recalculated.');
test_assert(count($prepared['report']['review']['changes']) >= 1, 'Change history was not created.');

$approved = TME_AI_Report::prepare_for_storage($prepared['report'], $prepared['report'], 42, true, false);
test_assert($approved['status'] === 'approved', 'Approval status was not stored.');
test_assert($approved['report']['review']['reviewed_by_user_id'] === 42, 'Reviewer was not stored.');
test_assert(!empty($approved['report']['review']['reviewed_at']), 'Approval time was not stored.');

echo "Phase 2A AI report behavior checks passed.\n";
