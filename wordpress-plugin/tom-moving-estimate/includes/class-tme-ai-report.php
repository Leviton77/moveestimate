<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TME_AI_Report
{
    private const MAX_JSON_BYTES = 1048576;
    private const MAX_DEPTH = 10;
    private const MAX_ITEMS = 1000;
    private const MAX_CHANGES = 500;

    public static function decode(?string $json): ?array
    {
        if (!$json) {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function sanitize_json(string $json): ?array
    {
        if ($json === '' || strlen($json) > self::MAX_JSON_BYTES) {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !is_array($decoded['rooms'] ?? null)) {
            return null;
        }
        $clean = self::sanitize_value($decoded, 0);
        if (!is_array($clean)) {
            return null;
        }
        $clean['schema_version'] = mb_substr(sanitize_text_field((string) ($clean['schema_version'] ?? '1.0')), 0, 20);
        return $clean;
    }

    public static function synthetic_example(int $session_id)
    {
        $path = TME_DIR . 'assets/data/phase2-ai-report-example-v1.json';
        if (!is_readable($path)) {
            return new WP_Error('tme_ai_sample_missing', __('The synthetic report file is missing.', 'tom-moving-estimate'));
        }
        $report = self::sanitize_json((string) file_get_contents($path));
        if (!$report) {
            return new WP_Error('tme_ai_sample_invalid', __('The synthetic report file is invalid.', 'tom-moving-estimate'));
        }

        $generated_at = gmdate('c');
        $report['analysis']['analysis_id'] = 'synthetic-session-' . $session_id;
        $report['analysis']['session_id'] = $session_id;
        $report['analysis']['status'] = 'needs_review';
        $report['analysis']['generated_at'] = $generated_at;
        $report['analysis']['model'] = 'synthetic-example';
        $report['analysis']['warnings'] = array(
            __('Synthetic example only. No customer media was analyzed.', 'tom-moving-estimate'),
        );
        $report['review'] = array(
            'status'              => 'pending',
            'reviewed_by_user_id' => null,
            'reviewed_at'         => null,
            'approval_notes'      => '',
            'changes'             => array(),
        );
        return self::recalculate($report);
    }

    public static function prepare_for_storage(
        array $submitted,
        ?array $previous,
        int $user_id,
        bool $approve,
        bool $was_approved
    ): array {
        $submitted = self::recalculate($submitted);
        $previous_content = self::content_without_review($previous ?: array());
        $submitted_content = self::content_without_review($submitted);
        $changed = wp_json_encode($previous_content) !== wp_json_encode($submitted_content);
        $now = gmdate('c');

        $previous_review = is_array($previous['review'] ?? null) ? $previous['review'] : array();
        $changes = is_array($previous_review['changes'] ?? null) ? $previous_review['changes'] : array();
        if ($changed && $previous) {
            $changes = array_merge($changes, self::diff($previous_content, $submitted_content, '', $user_id, $now));
            $changes = array_slice($changes, -self::MAX_CHANGES);
        }

        if ($approve) {
            $status = 'approved';
            $submitted['review'] = array(
                'status'              => 'approved',
                'reviewed_by_user_id' => $user_id,
                'reviewed_at'         => $now,
                'approval_notes'      => sanitize_textarea_field((string) ($submitted['review']['approval_notes'] ?? '')),
                'changes'             => $changes,
            );
        } elseif ($was_approved && !$changed) {
            $status = 'approved';
            $submitted['review'] = array(
                'status'              => 'approved',
                'reviewed_by_user_id' => absint($previous_review['reviewed_by_user_id'] ?? 0) ?: null,
                'reviewed_at'         => sanitize_text_field((string) ($previous_review['reviewed_at'] ?? '')) ?: null,
                'approval_notes'      => sanitize_textarea_field((string) ($previous_review['approval_notes'] ?? '')),
                'changes'             => $changes,
            );
        } else {
            $status = 'needs_review';
            $submitted['review'] = array(
                'status'              => 'pending',
                'reviewed_by_user_id' => null,
                'reviewed_at'         => null,
                'approval_notes'      => sanitize_textarea_field((string) ($submitted['review']['approval_notes'] ?? '')),
                'changes'             => $changes,
            );
        }

        if (!is_array($submitted['analysis'] ?? null)) {
            $submitted['analysis'] = array();
        }
        $submitted['analysis']['status'] = $status;
        return array(
            'report'  => $submitted,
            'status'  => $status,
            'changed' => $changed,
        );
    }

    private static function recalculate(array $report): array
    {
        $rooms = is_array($report['rooms'] ?? null) ? $report['rooms'] : array();
        $moving = 0;
        $not_moving = 0;
        $uncertain = 0;
        $special = 0;
        $boxes = array('low' => 0, 'likely' => 0, 'high' => 0);

        foreach ($rooms as $room) {
            foreach (is_array($room['inventory'] ?? null) ? $room['inventory'] : array() as $item) {
                $boxable = !empty($item['boxable']);
                $move_status = (string) ($item['move_status'] ?? 'uncertain');
                if (!$boxable) {
                    if ($move_status === 'moving') {
                        $moving += max(1, absint($item['quantity'] ?? 1));
                    } elseif ($move_status === 'not_moving') {
                        $not_moving += max(1, absint($item['quantity'] ?? 1));
                    } else {
                        $uncertain += max(1, absint($item['quantity'] ?? 1));
                    }
                }
                if (!empty($item['handling_tags']) && is_array($item['handling_tags'])) {
                    $special++;
                }
                if ($move_status === 'moving' && $boxable) {
                    foreach (array_keys($boxes) as $key) {
                        $boxes[$key] += max(0, (int) ($item['box_equivalents'][$key] ?? 0));
                    }
                }
            }
        }

        $disassembly = array('likely' => 0, 'may_be_needed' => 0, 'required' => 0);
        foreach (is_array($report['disassembly_plan']['items'] ?? null) ? $report['disassembly_plan']['items'] : array() as $item) {
            $likelihood = (string) ($item['likelihood'] ?? '');
            if (isset($disassembly[$likelihood])) {
                $disassembly[$likelihood]++;
            }
        }
        $report['disassembly_plan']['totals'] = $disassembly;

        $bag_sizes = array();
        $bag_total = 0;
        foreach (is_array($report['mattress_bags']['by_size'] ?? null) ? $report['mattress_bags']['by_size'] : array() as &$entry) {
            $entry_total = max(0, absint($entry['mattress_bags'] ?? 0)) + max(0, absint($entry['foundation_or_box_spring_bags'] ?? 0));
            $entry['total_bags'] = $entry_total;
            $size = sanitize_key((string) ($entry['size'] ?? 'unknown')) ?: 'unknown';
            $bag_sizes[$size] = ($bag_sizes[$size] ?? 0) + $entry_total;
            $bag_total += $entry_total;
        }
        unset($entry);
        $report['mattress_bags']['total_bags'] = $bag_total;

        $questions = is_array($report['questions'] ?? null) ? $report['questions'] : array();
        $open_questions = count(array_filter($questions, static fn($question): bool => is_array($question) && ($question['status'] ?? 'open') === 'open'));

        $report['summary'] = array_merge(is_array($report['summary'] ?? null) ? $report['summary'] : array(), array(
            'rooms_observed'              => count($rooms),
            'moving_furniture_pieces'     => $moving,
            'not_moving_furniture_pieces' => $not_moving,
            'uncertain_furniture_pieces'  => $uncertain,
            'box_equivalents'             => $boxes,
            'special_handling_items'      => $special,
            'disassembly'                 => $disassembly,
            'mattress_bags_total'         => $bag_total,
            'mattress_bag_sizes'          => $bag_sizes,
            'unresolved_questions'        => $open_questions,
        ));
        $report['box_estimate']['total'] = $boxes;
        return $report;
    }

    private static function sanitize_value($value, int $depth)
    {
        if ($depth > self::MAX_DEPTH || is_resource($value) || is_object($value)) {
            return null;
        }
        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            return mb_substr(sanitize_textarea_field($value), 0, 5000);
        }
        if (!is_array($value)) {
            return null;
        }

        $clean = array();
        if (array_is_list($value)) {
            foreach (array_slice($value, 0, self::MAX_ITEMS) as $item) {
                $clean[] = self::sanitize_value($item, $depth + 1);
            }
            return $clean;
        }

        $count = 0;
        foreach ($value as $key => $item) {
            if ($count++ >= self::MAX_ITEMS) {
                break;
            }
            $clean_key = mb_substr(sanitize_key((string) $key), 0, 64);
            if ($clean_key !== '') {
                $clean[$clean_key] = self::sanitize_value($item, $depth + 1);
            }
        }
        return $clean;
    }

    private static function content_without_review(array $report): array
    {
        unset($report['review']);
        if (isset($report['analysis']) && is_array($report['analysis'])) {
            unset($report['analysis']['status']);
        }
        return $report;
    }

    private static function diff($before, $after, string $path, int $user_id, string $changed_at): array
    {
        if (wp_json_encode($before) === wp_json_encode($after)) {
            return array();
        }
        if (!is_array($before) || !is_array($after) || array_is_list($before) || array_is_list($after)) {
            return array(array(
                'path'               => $path ?: 'report',
                'previous'           => self::display_value($before),
                'new'                => self::display_value($after),
                'changed_by_user_id' => $user_id,
                'changed_at'         => $changed_at,
            ));
        }

        $changes = array();
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $next_path = $path === '' ? (string) $key : $path . '.' . $key;
            $changes = array_merge($changes, self::diff($before[$key] ?? null, $after[$key] ?? null, $next_path, $user_id, $changed_at));
            if (count($changes) >= self::MAX_CHANGES) {
                break;
            }
        }
        return array_slice($changes, 0, self::MAX_CHANGES);
    }

    private static function display_value($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_null($value)) {
            return '';
        }
        if (is_scalar($value)) {
            return mb_substr((string) $value, 0, 500);
        }
        return mb_substr((string) wp_json_encode($value), 0, 500);
    }
}
