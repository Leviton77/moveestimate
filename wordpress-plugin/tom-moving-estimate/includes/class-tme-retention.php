<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TME_Retention
{
    public static function init(): void
    {
        add_action('tme_retention_sweep', array(__CLASS__, 'sweep'));
        add_action('tme_retention_warning', array(__CLASS__, 'send_warning'));
        add_action('tme_retention_delete', array(__CLASS__, 'delete_media'));
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        self::schedule_sweep();
    }

    public static function cron_schedules(array $schedules): array
    {
        if (!isset($schedules['tme_hourly'])) {
            $schedules['tme_hourly'] = array(
                'interval' => HOUR_IN_SECONDS,
                'display'  => __('Every hour (Move Estimate)', 'tom-moving-estimate'),
            );
        }
        return $schedules;
    }

    public static function schedule_sweep(): void
    {
        if (!wp_next_scheduled('tme_retention_sweep')) {
            wp_schedule_event(time() + 300, 'tme_hourly', 'tme_retention_sweep');
        }
    }

    public static function schedule_for_session(int $id, int $uploaded_timestamp): void
    {
        $settings = TME_Plugin::settings();
        $delete_at = $uploaded_timestamp + ((int) $settings['retention_days'] * DAY_IN_SECONDS);
        $warning_at = $delete_at - ((int) $settings['warning_days'] * DAY_IN_SECONDS);
        wp_schedule_single_event($warning_at, 'tme_retention_warning', array($id));
        wp_schedule_single_event($delete_at, 'tme_retention_delete', array($id));
    }

    public static function sweep(): void
    {
        $candidates = TME_DB::retention_candidates();
        foreach ($candidates['warnings'] as $candidate) {
            self::send_warning((int) $candidate->id);
        }
        foreach ($candidates['deletions'] as $candidate) {
            self::delete_media((int) $candidate->id);
        }
    }

    public static function send_warning(int $id): void
    {
        $session = TME_DB::get($id);
        if (!$session || $session->submission_type === 'info') {
            return;
        }

        $is_photos = $session->submission_type === 'photos';
        $active_photos = $is_photos ? array_values(array_filter(
            TME_DB::photos($session),
            static fn(array $photo): bool => empty($photo['deleted_at']) && !empty($photo['key'])
        )) : array();
        $already_warned = $is_photos ? $session->media_warning_sent_at : ($session->media_warning_sent_at ?: $session->warning_sent_at);
        if (($is_photos && !$active_photos) || (!$is_photos && (!$session->video_key || $session->video_deleted_at)) || $already_warned) {
            return;
        }

        $settings = TME_Plugin::settings();
        $recipient = sanitize_email((string) $settings['notification_email']);
        if (!$recipient) {
            return;
        }

        $expires_at = $session->media_expires_at ?: $session->video_expires_at;
        if (!$expires_at) {
            return;
        }
        $days = max(0, (int) ceil((strtotime($expires_at . ' UTC') - time()) / DAY_IN_SECONDS));
        $review_url = admin_url('admin.php?page=tme-estimates&session=' . $session->id);
        $media_label = $is_photos ? 'photos' : 'video';
        $subject = sprintf('%s deletion reminder: %s', ucfirst($media_label), $session->client_name);
        $verb = $is_photos ? 'are' : 'is';
        $message = "The moving-estimate {$media_label} for {$session->client_name} {$verb} scheduled for automatic deletion in {$days} day(s).\n\n";
        $message .= "Move date: {$session->move_date}\n";
        $message .= "Delete date: {$expires_at} UTC\n";
        $message .= "Review or download it before deletion: {$review_url}\n";
        wp_mail($recipient, $subject, $message);

        $now = current_time('mysql', true);
        $data = array('media_warning_sent_at' => $now);
        $formats = array('%s');
        if (!$is_photos) {
            $data['warning_sent_at'] = $now;
            $formats[] = '%s';
        }
        TME_DB::update($id, $data, $formats);
    }

    public static function delete_media(int $id, bool $manual = false)
    {
        $session = TME_DB::get($id);
        if (!$session || $session->submission_type === 'info') {
            return true;
        }
        return $session->submission_type === 'photos'
            ? self::delete_photos($id, $manual)
            : self::delete_video($id, $manual);
    }

    public static function delete_video(int $id, bool $manual = false)
    {
        $session = TME_DB::get($id);
        if (!$session || !$session->video_key || $session->video_deleted_at) {
            return true;
        }

        $expires_at = $session->media_expires_at ?: $session->video_expires_at;
        if (!$manual && $expires_at && strtotime($expires_at . ' UTC') > time()) {
            return false;
        }

        $result = (new TME_R2())->delete($session->video_key);
        if (is_wp_error($result)) {
            TME_DB::update($id, array(
                'deletion_error'       => $result->get_error_message(),
                'media_deletion_error' => $result->get_error_message(),
            ), array('%s', '%s'));
            return $result;
        }

        $deleted_at = current_time('mysql', true);
        TME_DB::update(
            $id,
            array(
                'video_deleted_at'    => $deleted_at,
                'media_deleted_at'    => $deleted_at,
                'deletion_error'      => '',
                'media_deletion_error'=> '',
                'pending_video_key'   => null,
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
        return true;
    }

    public static function delete_photo(int $id, string $photo_id)
    {
        $session = TME_DB::get($id);
        if (!$session || $session->submission_type !== 'photos') {
            return new WP_Error('tme_photo_missing', __('This photo is no longer available.', 'tom-moving-estimate'));
        }

        $photos = TME_DB::photos($session);
        $found = false;
        foreach ($photos as &$photo) {
            if (($photo['id'] ?? '') !== $photo_id || !empty($photo['deleted_at'])) {
                continue;
            }
            $found = true;
            $key = (string) ($photo['key'] ?? '');
            if ($key === '') {
                return new WP_Error('tme_photo_missing', __('This photo is no longer available.', 'tom-moving-estimate'));
            }
            $result = (new TME_R2())->delete($key);
            if (is_wp_error($result)) {
                return $result;
            }
            $photo['deleted_at'] = current_time('mysql', true);
            break;
        }
        unset($photo);

        if (!$found) {
            return new WP_Error('tme_photo_missing', __('This photo is no longer available.', 'tom-moving-estimate'));
        }

        $remaining = array_filter($photos, static fn(array $photo): bool => empty($photo['deleted_at']) && !empty($photo['key']));
        TME_DB::update($id, array(
            'photos'               => TME_DB::encode_photos($photos),
            'media_deleted_at'     => $remaining ? null : current_time('mysql', true),
            'media_deletion_error' => '',
        ), array('%s', '%s', '%s'));
        return true;
    }

    public static function delete_photos(int $id, bool $manual = false)
    {
        $session = TME_DB::get($id);
        if (!$session || $session->submission_type !== 'photos' || $session->media_deleted_at) {
            return true;
        }
        if (!$manual && $session->media_expires_at && strtotime($session->media_expires_at . ' UTC') > time()) {
            return false;
        }

        $photos = TME_DB::photos($session);
        $errors = array();
        $deleted_at = current_time('mysql', true);
        foreach ($photos as &$photo) {
            if (!empty($photo['deleted_at']) || empty($photo['key'])) {
                continue;
            }
            $result = (new TME_R2())->delete((string) $photo['key']);
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
                continue;
            }
            $photo['deleted_at'] = $deleted_at;
        }
        unset($photo);

        $remaining = array_filter($photos, static fn(array $photo): bool => empty($photo['deleted_at']) && !empty($photo['key']));
        $error_message = $errors ? implode(' ', array_unique($errors)) : '';
        TME_DB::update($id, array(
            'photos'               => TME_DB::encode_photos($photos),
            'pending_photos'       => '[]',
            'media_deleted_at'     => $remaining ? null : $deleted_at,
            'media_deletion_error' => $error_message,
        ), array('%s', '%s', '%s', '%s'));

        return $errors
            ? new WP_Error('tme_photo_delete', $error_message)
            : true;
    }
}
