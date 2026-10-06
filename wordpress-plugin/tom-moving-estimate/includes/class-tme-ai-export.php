<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TME_AI_Export
{
    public static function filename(object $session, string $extension): string
    {
        $customer = sanitize_title((string) ($session->client_name ?? ''));
        $base = $customer !== '' ? $customer : 'moving-estimate';
        return $base . '-estimate-' . absint($session->id ?? 0) . '.' . sanitize_key($extension);
    }

    public static function status_label(object $session, array $report): string
    {
        $review = self::array_value($report['review'] ?? array());
        return ($session->ai_status ?? '') === 'approved' && ($review['status'] ?? '') === 'approved'
            ? 'APPROVED'
            : 'DRAFT';
    }

    public static function csv_rows(object $session, array $report): array
    {
        $headers = array(
            'Estimate ID',
            'Customer',
            'Move date',
            'Current address',
            'Destination address',
            'Report status',
            'Room',
            'Floor',
            'Item',
            'Category',
            'Quantity',
            'Move status',
            'Box low',
            'Box likely',
            'Box high',
            'Handling tags',
            'Disassembly likelihood',
            'Disassembly work',
            'Destination reassembly',
            'Mattress size',
            'Mattress bags',
            'Foundation/box-spring bags',
            'Confidence',
            'Evidence timestamps',
            'Notes',
        );
        $rows = array(self::csv_safe_row($headers));
        $disassembly = self::disassembly_map($report);
        $mattresses = self::mattress_map($report);

        foreach (self::array_value($report['rooms'] ?? array()) as $room) {
            if (!is_array($room)) {
                continue;
            }
            foreach (self::array_value($room['inventory'] ?? array()) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $item_id = (string) ($item['id'] ?? '');
                $box = self::array_value($item['box_equivalents'] ?? array());
                $task = $disassembly[$item_id] ?? array();
                $bags = $mattresses[$item_id] ?? array();
                $rows[] = self::csv_safe_row(array(
                    absint($session->id ?? 0),
                    (string) ($session->client_name ?? ''),
                    (string) ($session->move_date ?? ''),
                    (string) ($session->current_address ?? ''),
                    (string) ($session->destination_address ?? ''),
                    self::status_label($session, $report),
                    (string) ($room['name'] ?? ''),
                    self::label((string) ($room['floor'] ?? '')),
                    (string) ($item['name'] ?? ''),
                    self::label((string) ($item['category'] ?? '')),
                    max(0, absint($item['quantity'] ?? 0)),
                    self::label((string) ($item['move_status'] ?? 'uncertain')),
                    max(0, (int) ($box['low'] ?? 0)),
                    max(0, (int) ($box['likely'] ?? 0)),
                    max(0, (int) ($box['high'] ?? 0)),
                    self::joined_labels($item['handling_tags'] ?? array()),
                    self::label((string) ($task['likelihood'] ?? '')),
                    (string) ($task['expected_work'] ?? ''),
                    self::label((string) ($task['reassembly_at_destination'] ?? '')),
                    implode('; ', $bags['sizes'] ?? array()),
                    (int) ($bags['mattress_bags'] ?? 0),
                    (int) ($bags['foundation_bags'] ?? 0),
                    self::confidence($item['confidence'] ?? null),
                    self::evidence_text($item['evidence'] ?? array()),
                    (string) ($item['notes'] ?? ''),
                ));
            }
        }

        return $rows;
    }

    public static function render_print(object $session, array $report): void
    {
        $rooms = self::array_value($report['rooms'] ?? array());
        $summary = self::array_value($report['summary'] ?? array());
        $boxes = self::array_value($report['box_estimate'] ?? array());
        $box_total = self::array_value($boxes['total'] ?? array());
        $disassembly = self::array_value($report['disassembly_plan']['items'] ?? array());
        $mattress_bags = self::array_value($report['mattress_bags'] ?? array());
        $access = self::array_value($report['access'] ?? array());
        $layout = self::array_value($report['home_layout'] ?? array());
        $questions = self::array_value($report['questions'] ?? array());
        $review = self::array_value($report['review'] ?? array());
        $status = self::status_label($session, $report);
        $reviewer = '';
        if (!empty($review['reviewed_by_user_id'])) {
            $user = get_userdata(absint($review['reviewed_by_user_id']));
            $reviewer = $user ? (string) $user->display_name : '';
        }
        $reviewed_at = self::date_time((string) ($review['reviewed_at'] ?? ''));
        $move_date = self::date_only((string) ($session->move_date ?? ''));
        $generated_at = self::date_time((string) ($report['analysis']['generated_at'] ?? ''));
        ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html(sprintf('Moving estimate report — %s', (string) ($session->client_name ?? 'Customer'))); ?></title>
    <style>
        :root{--navy:#16324f;--blue:#1663a7;--ink:#16202a;--muted:#5d6975;--line:#d9e1e8;--soft:#f4f7fa;--warn:#9a5b00;--good:#176b43}
        *{box-sizing:border-box}
        body{margin:0;background:#eef2f5;color:var(--ink);font:14px/1.45 Arial,Helvetica,sans-serif}
        main{max-width:1040px;margin:30px auto;background:#fff;padding:38px 44px;box-shadow:0 8px 28px rgba(22,50,79,.12)}
        h1,h2,h3{color:var(--navy);line-height:1.2;margin:0}
        h1{font-size:28px} h2{font-size:19px;margin-bottom:14px} h3{font-size:15px;margin-bottom:8px}
        p{margin:4px 0 10px}.muted{color:var(--muted)}.small{font-size:12px}
        .toolbar{max-width:1040px;margin:20px auto 0;display:flex;justify-content:flex-end}
        .print-button{border:0;border-radius:5px;background:var(--blue);color:#fff;font-weight:700;padding:11px 18px;cursor:pointer}
        .report-head{display:flex;justify-content:space-between;gap:28px;padding-bottom:22px;border-bottom:3px solid var(--navy)}
        .brand{font-size:13px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--blue);margin-bottom:6px}
        .status{text-align:right}.badge{display:inline-block;border:2px solid currentColor;border-radius:999px;padding:5px 11px;font-size:12px;font-weight:800;letter-spacing:.08em;color:<?php echo $status === 'APPROVED' ? 'var(--good)' : 'var(--warn)'; ?>}
        .section{padding:23px 0;border-bottom:1px solid var(--line);break-inside:avoid}
        .grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
        .card{border:1px solid var(--line);border-radius:7px;padding:12px;background:#fff}
        .label{display:block;color:var(--muted);font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;margin-bottom:3px}
        .value{font-size:15px;font-weight:700}
        .summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
        .summary-grid .card{text-align:center;background:var(--soft)}
        .summary-grid .value{font-size:20px;color:var(--navy)}
        table{width:100%;border-collapse:collapse;margin-top:8px;font-size:12px}
        th,td{border:1px solid var(--line);padding:7px 8px;text-align:left;vertical-align:top}
        th{background:var(--soft);color:var(--navy);font-weight:700}
        .room{margin-top:18px;break-inside:avoid}.room:first-of-type{margin-top:0}
        .pill{display:inline-block;border-radius:999px;background:var(--soft);padding:2px 7px;margin:1px 3px 1px 0;font-size:11px}
        .two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        ul{margin:7px 0 0;padding-left:20px}li{margin:4px 0}
        .question{border-left:4px solid var(--blue);padding:8px 12px;margin:8px 0;background:var(--soft)}
        .footer{padding-top:18px;color:var(--muted);font-size:11px}
        @media(max-width:720px){main{margin:0;padding:24px}.toolbar{margin:12px}.grid,.summary-grid,.two-col{grid-template-columns:1fr}.report-head{display:block}.status{text-align:left;margin-top:14px}table{font-size:11px}}
        @page{size:letter;margin:.5in}
        @media print{body{background:#fff;font-size:11px}.toolbar{display:none}main{max-width:none;margin:0;padding:0;box-shadow:none}a{color:inherit;text-decoration:none}.section{padding:15px 0}h1{font-size:23px}h2{font-size:16px}.summary-grid .value{font-size:16px}}
    </style>
</head>
<body>
    <div class="toolbar"><button class="print-button" type="button" onclick="window.print()">Print / Save as PDF</button></div>
    <main>
        <header class="report-head">
            <div>
                <div class="brand">Tom Moving</div>
                <h1>Moving Estimate Report</h1>
                <p class="muted">Internal representative-reviewed planning report</p>
            </div>
            <div class="status">
                <span class="badge"><?php echo esc_html($status); ?></span>
                <p class="small">Estimate #<?php echo esc_html((string) absint($session->id ?? 0)); ?></p>
            </div>
        </header>

        <section class="section">
            <h2>Customer and move</h2>
            <div class="grid">
                <?php self::print_card('Customer', (string) ($session->client_name ?? '')); ?>
                <?php self::print_card('Move date', $move_date); ?>
                <?php self::print_card('Home size given', (string) ($session->estimated_size ?? '')); ?>
                <?php self::print_card('Current address', (string) ($session->current_address ?? '')); ?>
                <?php self::print_card('Destination address', (string) ($session->destination_address ?? '')); ?>
                <?php self::print_card('Report generated', $generated_at ?: 'Not recorded'); ?>
            </div>
        </section>

        <section class="section">
            <h2>Report summary</h2>
            <div class="summary-grid">
                <?php self::print_card('Rooms observed', (string) absint($summary['rooms_observed'] ?? count($rooms))); ?>
                <?php self::print_card('Moving furniture', (string) absint($summary['moving_furniture_pieces'] ?? 0)); ?>
                <?php self::print_card('Boxes — likely', (string) max(0, (int) ($box_total['likely'] ?? 0))); ?>
                <?php self::print_card('Mattress bags', (string) absint($mattress_bags['total_bags'] ?? 0)); ?>
                <?php self::print_card('Not moving', (string) absint($summary['not_moving_furniture_pieces'] ?? 0)); ?>
                <?php self::print_card('Uncertain items', (string) absint($summary['uncertain_furniture_pieces'] ?? 0)); ?>
                <?php self::print_card('Special handling', (string) absint($summary['special_handling_items'] ?? 0)); ?>
                <?php self::print_card('Open questions', (string) absint($summary['unresolved_questions'] ?? 0)); ?>
            </div>
        </section>

        <section class="section">
            <h2>Inventory by room</h2>
            <?php foreach ($rooms as $room) :
                if (!is_array($room)) {
                    continue;
                }
                $inventory = self::array_value($room['inventory'] ?? array());
                ?>
                <div class="room">
                    <h3><?php echo esc_html((string) ($room['name'] ?? 'Room')); ?> <span class="muted">— <?php echo esc_html(self::label((string) ($room['floor'] ?? ''))); ?></span></h3>
                    <table>
                        <thead><tr><th>Qty</th><th>Item</th><th>Move status</th><th>Boxes L / M / H</th><th>Handling / notes</th></tr></thead>
                        <tbody>
                        <?php foreach ($inventory as $item) :
                            if (!is_array($item)) {
                                continue;
                            }
                            $item_boxes = self::array_value($item['box_equivalents'] ?? array());
                            $handling = self::joined_labels($item['handling_tags'] ?? array());
                            $notes = (string) ($item['notes'] ?? '');
                            ?>
                            <tr>
                                <td><?php echo esc_html((string) max(0, absint($item['quantity'] ?? 0))); ?></td>
                                <td><strong><?php echo esc_html((string) ($item['name'] ?? '')); ?></strong><br><span class="muted"><?php echo esc_html(self::label((string) ($item['category'] ?? ''))); ?></span></td>
                                <td><?php echo esc_html(self::label((string) ($item['move_status'] ?? 'uncertain'))); ?></td>
                                <td><?php echo esc_html(self::range_text($item_boxes)); ?></td>
                                <td><?php echo esc_html(trim($handling . ($handling && $notes ? ' — ' : '') . $notes)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$inventory) : ?><tr><td colspan="5">No inventory recorded.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="section">
            <h2>Box estimate</h2>
            <div class="grid">
                <?php self::print_card('Low', (string) max(0, (int) ($box_total['low'] ?? 0))); ?>
                <?php self::print_card('Likely', (string) max(0, (int) ($box_total['likely'] ?? 0))); ?>
                <?php self::print_card('High', (string) max(0, (int) ($box_total['high'] ?? 0))); ?>
            </div>
            <?php $mix = self::array_value($boxes['suggested_mix'] ?? array()); if ($mix) : ?>
                <p><strong>Suggested mix:</strong> <?php echo esc_html(self::key_value_text($mix)); ?></p>
            <?php endif; ?>
            <?php self::print_list('Assumptions', $boxes['assumptions'] ?? array()); ?>
        </section>

        <section class="section">
            <h2>Likely disassembly and reassembly</h2>
            <table>
                <thead><tr><th>Item</th><th>Likelihood</th><th>Expected work</th><th>Reassemble</th><th>Confidence</th></tr></thead>
                <tbody>
                <?php foreach ($disassembly as $task) :
                    if (!is_array($task)) {
                        continue;
                    }
                    ?>
                    <tr>
                        <td><?php echo esc_html((string) ($task['item'] ?? '')); ?></td>
                        <td><?php echo esc_html(self::label((string) ($task['likelihood'] ?? ''))); ?></td>
                        <td><?php echo esc_html((string) ($task['expected_work'] ?? '')); ?></td>
                        <td><?php echo esc_html(self::label((string) ($task['reassembly_at_destination'] ?? ''))); ?></td>
                        <td><?php echo esc_html(self::confidence($task['confidence'] ?? null)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$disassembly) : ?><tr><td colspan="5">No disassembly work identified.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="section">
            <h2>Mattress bags to prepare</h2>
            <table>
                <thead><tr><th>Size</th><th>Mattress bags</th><th>Foundation / box-spring bags</th><th>Total</th><th>Confidence</th></tr></thead>
                <tbody>
                <?php foreach (self::array_value($mattress_bags['by_size'] ?? array()) as $bags) :
                    if (!is_array($bags)) {
                        continue;
                    }
                    ?>
                    <tr>
                        <td><?php echo esc_html(self::label((string) ($bags['size'] ?? 'unknown'))); ?></td>
                        <td><?php echo esc_html((string) absint($bags['mattress_bags'] ?? 0)); ?></td>
                        <td><?php echo esc_html((string) absint($bags['foundation_or_box_spring_bags'] ?? 0)); ?></td>
                        <td><?php echo esc_html((string) absint($bags['total_bags'] ?? 0)); ?></td>
                        <td><?php echo esc_html(self::confidence($bags['confidence'] ?? null)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($mattress_bags['by_size'])) : ?><tr><td colspan="5">No mattress bags identified.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="section">
            <h2>Truck access and carrying route</h2>
            <div class="two-col">
                <?php self::print_access('Origin', self::array_value($access['origin'] ?? array())); ?>
                <?php self::print_access('Destination', self::array_value($access['destination'] ?? array())); ?>
            </div>
        </section>

        <section class="section">
            <h2>Home layout and carrying-speed factors</h2>
            <?php $area = self::array_value($layout['estimated_total_area_sqft'] ?? array()); ?>
            <div class="grid">
                <?php self::print_card('Estimated area (sq. ft.)', self::range_text($area)); ?>
                <?php self::print_card('Distribution', self::label((string) ($layout['distribution_type'] ?? ''))); ?>
                <?php self::print_card('Carrying speed', self::label((string) ($layout['carrying_speed_factor'] ?? ''))); ?>
                <?php self::print_card('Levels with items', self::joined_labels($layout['levels_with_moving_items'] ?? array())); ?>
                <?php self::print_card('Item density', self::label((string) ($layout['item_density'] ?? ''))); ?>
                <?php self::print_card('Confidence', self::confidence($layout['confidence'] ?? null)); ?>
            </div>
            <?php if (!empty($layout['notes'])) : ?><p><?php echo esc_html((string) $layout['notes']); ?></p><?php endif; ?>
        </section>

        <section class="section">
            <h2>Questions requiring confirmation</h2>
            <?php foreach ($questions as $question) :
                if (!is_array($question) || ($question['status'] ?? 'open') !== 'open') {
                    continue;
                }
                ?>
                <div class="question"><strong><?php echo esc_html(self::label((string) ($question['priority'] ?? 'normal'))); ?>:</strong> <?php echo esc_html((string) ($question['question'] ?? '')); ?></div>
            <?php endforeach; ?>
            <?php if (!$questions) : ?><p>No unresolved questions.</p><?php endif; ?>
        </section>

        <section class="section">
            <h2>Representative review</h2>
            <div class="grid">
                <?php self::print_card('Status', $status); ?>
                <?php self::print_card('Reviewed by', $reviewer ?: 'Not approved'); ?>
                <?php self::print_card('Reviewed at', $reviewed_at ?: 'Not approved'); ?>
            </div>
            <?php if (!empty($review['approval_notes'])) : ?><p><strong>Approval notes:</strong> <?php echo nl2br(esc_html((string) $review['approval_notes'])); ?></p><?php endif; ?>
        </section>

        <footer class="footer">
            This is an internal planning report and not a customer quotation. Counts, access conditions, disassembly work and time factors must be confirmed by a Tom Moving representative before pricing or scheduling.
        </footer>
    </main>
</body>
</html>
        <?php
    }

    private static function disassembly_map(array $report): array
    {
        $map = array();
        foreach (self::array_value($report['disassembly_plan']['items'] ?? array()) as $item) {
            if (is_array($item) && !empty($item['item_id'])) {
                $map[(string) $item['item_id']] = $item;
            }
        }
        return $map;
    }

    private static function mattress_map(array $report): array
    {
        $map = array();
        foreach (self::array_value($report['mattress_bags']['by_size'] ?? array()) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            foreach (self::array_value($entry['item_ids'] ?? array()) as $item_id) {
                $key = (string) $item_id;
                if ($key === '') {
                    continue;
                }
                if (!isset($map[$key])) {
                    $map[$key] = array('sizes' => array(), 'mattress_bags' => 0, 'foundation_bags' => 0);
                }
                $map[$key]['sizes'][] = self::label((string) ($entry['size'] ?? 'unknown'));
                $map[$key]['mattress_bags'] += absint($entry['mattress_bags'] ?? 0);
                $map[$key]['foundation_bags'] += absint($entry['foundation_or_box_spring_bags'] ?? 0);
            }
        }
        return $map;
    }

    private static function csv_safe_row(array $row): array
    {
        return array_map(static function ($value): string {
            if (is_bool($value)) {
                $text = $value ? 'Yes' : 'No';
            } elseif (is_scalar($value) || $value === null) {
                $text = (string) $value;
            } else {
                $text = (string) wp_json_encode($value);
            }
            return preg_match('/^[\x00-\x20]*[=+\-@]/u', $text) ? "'" . $text : $text;
        }, $row);
    }

    private static function evidence_text($evidence): string
    {
        $labels = array();
        foreach (self::array_value($evidence) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['type'] ?? '') === 'video_timestamp') {
                $start = self::timestamp($entry['start_seconds'] ?? null);
                $end = self::timestamp($entry['end_seconds'] ?? null);
                $labels[] = $start . ($end && $end !== $start ? '–' . $end : '');
            } elseif (!empty($entry['type'])) {
                $labels[] = self::label((string) $entry['type']);
            }
        }
        return implode('; ', array_filter($labels));
    }

    private static function timestamp($seconds): string
    {
        if (!is_numeric($seconds)) {
            return '';
        }
        $seconds = max(0, (int) $seconds);
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    private static function print_card(string $label, string $value): void
    {
        echo '<div class="card"><span class="label">' . esc_html($label) . '</span><span class="value">' . esc_html($value !== '' ? $value : 'Not recorded') . '</span></div>';
    }

    private static function print_list(string $heading, $values): void
    {
        $values = self::array_value($values);
        if (!$values) {
            return;
        }
        echo '<h3>' . esc_html($heading) . '</h3><ul>';
        foreach ($values as $value) {
            if (is_scalar($value)) {
                echo '<li>' . esc_html((string) $value) . '</li>';
            }
        }
        echo '</ul>';
    }

    private static function print_access(string $heading, array $data): void
    {
        $distance = self::array_value($data['outdoor_carry_distance_meters'] ?? array());
        $stairs = self::array_value($data['stairs'] ?? array());
        $corridors = self::array_value($data['corridors'] ?? array());
        $elevator = self::array_value($data['elevator'] ?? array());
        echo '<div class="card"><h3>' . esc_html($heading) . '</h3><p><span class="label">Observed</span>' . esc_html(self::yes_no_unknown($data['observed'] ?? null)) . '</p>';
        echo '<p><span class="label">Truck position</span>' . esc_html((string) ($data['truck_position'] ?? 'Unknown')) . '</p>';
        echo '<p><span class="label">Outdoor carry (meters, low / likely / high)</span>' . esc_html(self::range_text($distance)) . '</p>';
        echo '<p><span class="label">Carry complexity</span>' . esc_html(self::label((string) ($data['carry_complexity'] ?? 'unknown'))) . '</p>';
        echo '<p><span class="label">Stairs</span>' . esc_html(self::key_value_text($stairs)) . '</p>';
        echo '<p><span class="label">Corridors</span>' . esc_html(self::label((string) ($corridors['complexity'] ?? 'unknown')) . (!empty($corridors['notes']) ? ' — ' . (string) $corridors['notes'] : '')) . '</p>';
        echo '<p><span class="label">Elevator</span>' . esc_html(self::yes_no_unknown($elevator['present'] ?? null)) . '</p>';
        echo '<p><span class="label">Parking</span>' . esc_html((string) ($data['parking_restrictions'] ?? 'Unknown')) . '</p></div>';
    }

    private static function range_text(array $range): string
    {
        $values = array();
        foreach (array('low', 'likely', 'high') as $key) {
            if (array_key_exists($key, $range) && $range[$key] !== null && $range[$key] !== '') {
                $values[] = (string) $range[$key];
            } else {
                $values[] = '—';
            }
        }
        return implode(' / ', $values);
    }

    private static function key_value_text(array $values): string
    {
        $parts = array();
        foreach ($values as $key => $value) {
            if (is_scalar($value) && $value !== '') {
                $parts[] = self::label((string) $key) . ': ' . self::label((string) $value);
            }
        }
        return implode('; ', $parts);
    }

    private static function joined_labels($values): string
    {
        $labels = array();
        foreach (self::array_value($values) as $value) {
            if (is_scalar($value)) {
                $labels[] = self::label((string) $value);
            }
        }
        return implode(', ', $labels);
    }

    private static function label(string $value): string
    {
        return ucwords(str_replace(array('_', '-'), ' ', trim($value)));
    }

    private static function confidence($value): string
    {
        return is_numeric($value) ? (string) round(max(0, min(1, (float) $value)) * 100) . '%' : '';
    }

    private static function yes_no_unknown($value): string
    {
        if ($value === true || $value === 1 || $value === '1') {
            return 'Yes';
        }
        if ($value === false || $value === 0 || $value === '0') {
            return 'No';
        }
        return 'Unknown';
    }

    private static function date_only(string $date): string
    {
        $time = strtotime($date . (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? ' 12:00:00' : ''));
        return $time ? wp_date('F j, Y', $time) : '';
    }

    private static function date_time(string $date): string
    {
        $time = strtotime($date);
        return $time ? wp_date('F j, Y, g:i a', $time) : '';
    }

    private static function array_value($value): array
    {
        return is_array($value) ? $value : array();
    }
}
