<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds a full plain-text "lead report" for one estimate: every fact a rep
 * has captured about the lead, plus a readable summary of the AI moving
 * report when one has been saved. Used both for the on-screen report view
 * and as the body of the "send by email" action.
 */
final class TME_Lead_Report
{
    public static function subject(object $session): string
    {
        $name = trim((string) ($session->client_name ?? ''));
        return sprintf(
            /* translators: %s: customer name */
            __('Lead report — %s', 'tom-moving-estimate'),
            $name !== '' ? $name : __('Unnamed customer', 'tom-moving-estimate')
        );
    }

    /**
     * Comma-separated emails of everyone who can manage estimates (the
     * "reps' emails" the send-by-email field is prepopulated with).
     */
    public static function default_recipients(): string
    {
        $emails = array();
        foreach (get_users(array('capability' => 'tme_manage_estimates', 'fields' => array('user_email'))) as $user) {
            $email = is_object($user) ? (string) $user->user_email : '';
            if ($email !== '' && is_email($email)) {
                $emails[] = $email;
            }
        }
        return implode(', ', array_unique($emails));
    }

    public static function build_text(object $session, ?array $ai_report): string
    {
        $lines = array();
        $lines[] = strtoupper(__('Lead report', 'tom-moving-estimate'));
        $lines[] = trim((string) $session->client_name) !== '' ? (string) $session->client_name : __('Unnamed customer', 'tom-moving-estimate');
        $lines[] = sprintf(__('Generated %s', 'tom-moving-estimate'), wp_date('F j, Y, g:i a'));
        $lines[] = str_repeat('=', 60);
        $lines[] = '';

        $lines[] = strtoupper(__('Client details', 'tom-moving-estimate'));
        $lines[] = self::kv(__('Name', 'tom-moving-estimate'), (string) $session->client_name);
        $lines[] = self::kv(__('Email', 'tom-moving-estimate'), (string) $session->email);
        $lines[] = self::kv(__('Phone', 'tom-moving-estimate'), (string) $session->phone);
        $lines[] = self::kv(__('Move date', 'tom-moving-estimate'), self::date_only((string) $session->move_date));
        $lines[] = self::kv(__('Home size', 'tom-moving-estimate'), (string) $session->estimated_size);
        $lines[] = self::kv(__('Current address', 'tom-moving-estimate'), (string) $session->current_address);
        $lines[] = self::kv(__('Destination address', 'tom-moving-estimate'), (string) $session->destination_address);
        $lines[] = '';

        $lines[] = strtoupper(__('Submission', 'tom-moving-estimate'));
        $lines[] = self::kv(__('Type', 'tom-moving-estimate'), TME_Admin::submission_label($session));
        $lines[] = self::kv(__('Status', 'tom-moving-estimate'), ucfirst((string) $session->status));
        $lines[] = self::kv(__('Received', 'tom-moving-estimate'), self::date_time((string) $session->created_at . ' UTC'));
        if ((string) $session->submission_type === 'live' && trim((string) $session->live_rep) !== '') {
            $lines[] = self::kv(__('Live walkthrough by', 'tom-moving-estimate'), (string) $session->live_rep);
            if (!empty($session->live_started_at)) {
                $lines[] = self::kv(__('Call started', 'tom-moving-estimate'), self::date_time((string) $session->live_started_at . ' UTC'));
            }
        }
        $lines[] = self::kv(__('Media', 'tom-moving-estimate'), self::media_summary($session));
        $lines[] = '';

        if (trim((string) $session->special_items) !== '') {
            $lines[] = strtoupper(__('From the customer', 'tom-moving-estimate'));
            $lines[] = trim((string) $session->special_items);
            $lines[] = '';
        }

        if (trim((string) $session->rep_notes) !== '') {
            $lines[] = strtoupper(__('Rep notes', 'tom-moving-estimate'));
            $lines[] = trim((string) $session->rep_notes);
            $lines[] = '';
        }

        if ($ai_report) {
            $lines = array_merge($lines, self::ai_report_lines($session, $ai_report));
        } else {
            $lines[] = strtoupper(__('AI moving report', 'tom-moving-estimate'));
            $lines[] = __('No AI moving report has been saved for this estimate yet.', 'tom-moving-estimate');
            $lines[] = '';
        }

        $lines[] = str_repeat('-', 60);
        $lines[] = __('This is an internal planning report and not a customer quotation.', 'tom-moving-estimate');

        return trim(implode("\n", $lines)) . "\n";
    }

    private static function ai_report_lines(object $session, array $report): array
    {
        $lines = array();
        $summary = self::arr($report['summary'] ?? array());
        $box = self::arr($report['box_estimate']['total'] ?? array());
        $mattress = self::arr($report['mattress_bags'] ?? array());
        $access = self::arr($report['access'] ?? array());
        $layout = self::arr($report['home_layout'] ?? array());
        $status = class_exists('TME_AI_Export')
            ? TME_AI_Export::status_label($session, $report)
            : (($session->ai_status ?? '') === 'approved' ? 'APPROVED' : 'DRAFT');

        $lines[] = strtoupper(__('AI moving report', 'tom-moving-estimate')) . ' — ' . $status;
        $lines[] = self::kv(__('Rooms observed', 'tom-moving-estimate'), (string) absint($summary['rooms_observed'] ?? count(self::arr($report['rooms'] ?? array()))));
        $lines[] = self::kv(
            __('Furniture moving / not moving / uncertain', 'tom-moving-estimate'),
            absint($summary['moving_furniture_pieces'] ?? 0) . ' / ' . absint($summary['not_moving_furniture_pieces'] ?? 0) . ' / ' . absint($summary['uncertain_furniture_pieces'] ?? 0)
        );
        $lines[] = self::kv(__('Boxes (low / likely / high)', 'tom-moving-estimate'), self::range_text($box));
        $lines[] = self::kv(__('Mattress bags', 'tom-moving-estimate'), (string) absint($mattress['total_bags'] ?? 0));
        $lines[] = self::kv(__('Access complexity', 'tom-moving-estimate'), self::label((string) ($access['origin']['carry_complexity'] ?? '')));
        $lines[] = self::kv(__('Home distribution', 'tom-moving-estimate'), self::label((string) ($layout['distribution_type'] ?? '')));
        $lines[] = self::kv(__('Open questions', 'tom-moving-estimate'), (string) absint($summary['unresolved_questions'] ?? 0));
        $lines[] = '';

        $rooms = self::arr($report['rooms'] ?? array());
        if ($rooms) {
            $lines[] = __('Inventory by room:', 'tom-moving-estimate');
            foreach ($rooms as $room) {
                if (!is_array($room)) {
                    continue;
                }
                $lines[] = '  ' . (string) ($room['name'] ?? __('Room', 'tom-moving-estimate')) . ' (' . self::label((string) ($room['floor'] ?? '')) . ')';
                foreach (self::arr($room['inventory'] ?? array()) as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $boxes = self::arr($item['box_equivalents'] ?? array());
                    $box_text = (int) ($boxes['likely'] ?? 0) > 0 ? ', ~' . (int) $boxes['likely'] . ' ' . __('boxes', 'tom-moving-estimate') : '';
                    $notes = trim((string) ($item['notes'] ?? ''));
                    $lines[] = sprintf(
                        '    - %s x%d — %s%s%s',
                        (string) ($item['name'] ?? ''),
                        max(1, absint($item['quantity'] ?? 1)),
                        self::label((string) ($item['move_status'] ?? 'uncertain')),
                        $box_text,
                        $notes !== '' ? ' (' . $notes . ')' : ''
                    );
                }
            }
            $lines[] = '';
        }

        $disassembly = self::arr($report['disassembly_plan']['items'] ?? array());
        if ($disassembly) {
            $lines[] = __('Disassembly and reassembly:', 'tom-moving-estimate');
            foreach ($disassembly as $task) {
                if (!is_array($task)) {
                    continue;
                }
                $lines[] = '  - ' . (string) ($task['item'] ?? '') . ' — ' . self::label((string) ($task['likelihood'] ?? '')) . ': ' . (string) ($task['expected_work'] ?? '');
            }
            $lines[] = '';
        }

        $by_size = self::arr($mattress['by_size'] ?? array());
        if ($by_size) {
            $lines[] = __('Mattress bags by size:', 'tom-moving-estimate');
            foreach ($by_size as $bags) {
                if (!is_array($bags)) {
                    continue;
                }
                $lines[] = '  - ' . self::label((string) ($bags['size'] ?? 'unknown')) . ': ' . absint($bags['total_bags'] ?? 0) . ' ' . __('bags', 'tom-moving-estimate');
            }
            $lines[] = '';
        }

        $open_questions = array_values(array_filter(
            self::arr($report['questions'] ?? array()),
            static fn($question): bool => is_array($question) && ($question['status'] ?? 'open') === 'open'
        ));
        if ($open_questions) {
            $lines[] = __('Questions requiring confirmation:', 'tom-moving-estimate');
            foreach ($open_questions as $question) {
                $lines[] = '  - [' . self::label((string) ($question['priority'] ?? 'normal')) . '] ' . (string) ($question['question'] ?? '');
            }
            $lines[] = '';
        }

        return $lines;
    }

    private static function media_summary(object $session): string
    {
        if ((string) $session->submission_type === 'photos') {
            $photos = array_filter(
                TME_DB::photos($session),
                static fn(array $photo): bool => empty($photo['deleted_at']) && !empty($photo['key'])
            );
            if ($photos) {
                return sprintf(
                    /* translators: %d: number of photos */
                    _n('%d photo attached — see the estimate in Move Estimates.', '%d photos attached — see the estimate in Move Estimates.', count($photos), 'tom-moving-estimate'),
                    count($photos)
                );
            }
            return $session->media_deleted_at
                ? __('Photos were deleted after the retention period.', 'tom-moving-estimate')
                : __('No photos received yet.', 'tom-moving-estimate');
        }
        if ((string) $session->submission_type === 'info') {
            return __('None — information only.', 'tom-moving-estimate');
        }
        if (!empty($session->video_key) && empty($session->video_deleted_at)) {
            return __('Video walkthrough attached — see the estimate in Move Estimates.', 'tom-moving-estimate');
        }
        return !empty($session->video_deleted_at)
            ? __('The video was deleted after the retention period.', 'tom-moving-estimate')
            : __('No video received yet.', 'tom-moving-estimate');
    }

    private static function kv(string $label, string $value): string
    {
        $value = trim($value);
        return $label . ': ' . ($value !== '' ? $value : '—');
    }

    private static function label(string $value): string
    {
        return ucwords(str_replace(array('_', '-'), ' ', trim($value)));
    }

    private static function range_text(array $range): string
    {
        $values = array();
        foreach (array('low', 'likely', 'high') as $key) {
            $values[] = array_key_exists($key, $range) && $range[$key] !== null && $range[$key] !== '' ? (string) $range[$key] : '—';
        }
        return implode(' / ', $values);
    }

    private static function date_only(string $date): string
    {
        if ($date === '') {
            return '';
        }
        $time = strtotime($date . (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? ' 12:00:00' : ''));
        return $time ? wp_date('F j, Y', $time) : '';
    }

    private static function date_time(string $date): string
    {
        $time = strtotime($date);
        return $time ? wp_date('F j, Y, g:i a', $time) : '';
    }

    private static function arr($value): array
    {
        return is_array($value) ? $value : array();
    }
}
