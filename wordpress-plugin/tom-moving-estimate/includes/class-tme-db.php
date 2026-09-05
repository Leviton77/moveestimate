<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TME_DB
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tme_sessions';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_token char(64) NOT NULL,
            client_name varchar(120) NOT NULL,
            email varchar(190) NOT NULL,
            phone varchar(40) NOT NULL,
            move_date date NOT NULL,
            current_address varchar(255) NOT NULL,
            destination_address varchar(255) NOT NULL,
            estimated_size varchar(32) NOT NULL,
            special_items text NULL,
            submission_type varchar(20) NOT NULL DEFAULT 'video',
            submitted_at datetime NULL,
            photos longtext NULL,
            pending_photos longtext NULL,
            media_expires_at datetime NULL,
            media_warning_sent_at datetime NULL,
            media_deleted_at datetime NULL,
            media_deletion_error text NULL,
            status varchar(20) NOT NULL DEFAULT 'new',
            video_key varchar(512) NULL,
            pending_video_key varchar(512) NULL,
            video_content_type varchar(100) NULL,
            video_size bigint(20) unsigned NULL,
            video_uploaded_at datetime NULL,
            video_expires_at datetime NULL,
            warning_sent_at datetime NULL,
            video_deleted_at datetime NULL,
            deletion_error text NULL,
            last_downloaded_at datetime NULL,
            live_call_id varchar(64) NULL,
            live_rep varchar(190) NULL,
            live_started_at datetime NULL,
            rep_notes longtext NULL,
            annotations longtext NULL,
            ai_status varchar(20) NOT NULL DEFAULT 'not_started',
            ai_report_original longtext NULL,
            ai_report_current longtext NULL,
            ai_schema_version varchar(20) NULL,
            ai_model varchar(100) NULL,
            ai_requested_at datetime NULL,
            ai_completed_at datetime NULL,
            ai_reviewed_at datetime NULL,
            ai_reviewed_by bigint(20) unsigned NULL,
            ai_error text NULL,
            consent_version varchar(20) NOT NULL DEFAULT '2026-07',
            consent_at datetime NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_token (public_token),
            KEY status (status),
            KEY video_expires_at (video_expires_at),
            KEY submission_type (submission_type),
            KEY media_expires_at (media_expires_at),
            KEY ai_status (ai_status),
            KEY live_call_id (live_call_id)
        ) {$charset};";

        dbDelta($sql);
        update_option('tme_db_version', TME_VERSION, false);
    }

    public static function create(array $data)
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $token = bin2hex(random_bytes(32));
        $inserted = $wpdb->insert(
            self::table(),
            array(
                'public_token'        => $token,
                'client_name'         => $data['client_name'],
                'email'               => $data['email'],
                'phone'               => $data['phone'],
                'move_date'           => $data['move_date'],
                'current_address'     => $data['current_address'],
                'destination_address' => $data['destination_address'],
                'estimated_size'      => $data['estimated_size'],
                'special_items'       => $data['special_items'],
                'submission_type'     => $data['submission_type'],
                'submitted_at'        => $data['submitted_at'] ?? null,
                'photos'              => '[]',
                'pending_photos'      => '[]',
                'consent_version'     => '2026-07-media',
                'consent_at'          => $now,
                'created_at'          => $now,
                'updated_at'          => $now,
                'annotations'         => '[]',
                'rep_notes'           => '',
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if (!$inserted) {
            return new WP_Error('tme_database', __('The estimate could not be created.', 'tom-moving-estimate'));
        }
        return self::get((int) $wpdb->insert_id);
    }

    public static function get(int $id): ?object
    {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id)) ?: null;
    }

    public static function get_by_token(string $token): ?object
    {
        global $wpdb;
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $table = self::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE public_token = %s LIMIT 1", $token)) ?: null;
    }

    public static function update(int $id, array $data, array $formats = array()): bool
    {
        global $wpdb;
        $data['updated_at'] = current_time('mysql', true);
        $formats[] = '%s';
        return $wpdb->update(self::table(), $data, array('id' => $id), $formats, array('%d')) !== false;
    }

    public static function delete(int $id): bool
    {
        global $wpdb;
        return $wpdb->delete(self::table(), array('id' => $id), array('%d')) !== false;
    }

    public static function photos(object $session, bool $pending = false): array
    {
        $field = $pending ? 'pending_photos' : 'photos';
        $decoded = json_decode((string) ($session->{$field} ?? '[]'), true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : array();
    }

    public static function encode_photos(array $photos): string
    {
        return (string) wp_json_encode(array_values($photos));
    }

    public static function list(array $args = array()): array
    {
        global $wpdb;
        $table = self::table();
        $limit = min(200, max(1, (int) ($args['limit'] ?? 100)));
        $where = array('1=1');
        $params = array();

        if (!empty($args['status']) && in_array($args['status'], array('new', 'reviewed', 'quoted'), true)) {
            $where[] = 'status = %s';
            $params[] = $args['status'];
        }
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(client_name LIKE %s OR email LIKE %s OR phone LIKE %s OR current_address LIKE %s)';
            array_push($params, $like, $like, $like, $like);
        }

        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT {$limit}";
        if ($params) {
            $sql = $wpdb->prepare($sql, ...$params);
        }
        return $wpdb->get_results($sql) ?: array();
    }

    public static function retention_candidates(): array
    {
        global $wpdb;
        $table = self::table();
        $settings = TME_Plugin::settings();
        $warning_cutoff = gmdate('Y-m-d H:i:s', time() + ((int) $settings['warning_days'] * DAY_IN_SECONDS));
        $now = current_time('mysql', true);

        $active_media = "(
            (submission_type = 'photos' AND submitted_at IS NOT NULL AND media_deleted_at IS NULL)
            OR
            (submission_type <> 'photos' AND video_key IS NOT NULL AND video_deleted_at IS NULL)
        )";
        $expires_at = 'COALESCE(media_expires_at, video_expires_at)';
        $warning_sent_at = "CASE
            WHEN submission_type = 'photos' THEN media_warning_sent_at
            ELSE COALESCE(media_warning_sent_at, warning_sent_at)
        END";

        $warnings = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE {$active_media} AND {$warning_sent_at} IS NULL
             AND {$expires_at} IS NOT NULL AND {$expires_at} <= %s AND {$expires_at} > %s",
            $warning_cutoff,
            $now
        )) ?: array();

        $deletions = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE {$active_media}
             AND {$expires_at} IS NOT NULL AND {$expires_at} <= %s",
            $now
        )) ?: array();

        return array('warnings' => $warnings, 'deletions' => $deletions);
    }
}
