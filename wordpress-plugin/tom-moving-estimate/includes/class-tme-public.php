<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TME_Public
{
    private const HOME_SIZES = array('Studio', '1 bedroom', '2 bedrooms', '3 bedrooms', '4+ bedrooms', 'House', 'Storage unit');
    private const SUBMISSION_TYPES = array('info', 'photos', 'video');
    private const PHOTO_TYPES = array(
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    );

    public static function init(): void
    {
        add_shortcode('tom_moving_estimate', array(__CLASS__, 'shortcode'));
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes(): void
    {
        register_rest_route('tme/v1', '/sessions', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'create_session'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('tme/v1', '/sessions/(?P<token>[a-f0-9]{64})', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array(__CLASS__, 'get_session'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('tme/v1', '/sessions/(?P<token>[a-f0-9]{64})/submission-type', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'choose_submission_type'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('tme/v1', '/sessions/(?P<token>[a-f0-9]{64})/upload-url', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'upload_url'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('tme/v1', '/sessions/(?P<token>[a-f0-9]{64})/complete', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'complete_upload'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('tme/v1', '/sessions/(?P<token>[a-f0-9]{64})/photo-upload-url', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'photo_upload_url'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('tme/v1', '/sessions/(?P<token>[a-f0-9]{64})/complete-photos', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'complete_photos'),
            'permission_callback' => '__return_true',
        ));
    }

    private static function client_key(string $action): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        return 'tme_' . substr(hash_hmac('sha256', $action . '|' . $ip, wp_salt('nonce')), 0, 32);
    }

    private static function rate_limited(string $action, int $limit, int $window): bool
    {
        $key = self::client_key($action);
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return true;
        }
        set_transient($key, $count + 1, $window);
        return false;
    }

    private static function text($value, int $max): string
    {
        return mb_substr(sanitize_text_field((string) $value), 0, $max);
    }

    private static function session_is_expired(object $session): bool
    {
        return strtotime($session->created_at . ' UTC') < time() - (7 * DAY_IN_SECONDS);
    }

    public static function create_session(WP_REST_Request $request)
    {
        if (self::rate_limited('create', 10, HOUR_IN_SECONDS)) {
            return new WP_Error('tme_rate_limit', __('Too many requests. Please try again later.', 'tom-moving-estimate'), array('status' => 429));
        }

        $payload = $request->get_json_params();
        $payload = is_array($payload) ? $payload : array();
        if (!empty($payload['website'])) {
            return new WP_REST_Response(array('token' => bin2hex(random_bytes(32))), 201);
        }

        $submission_type = sanitize_key((string) ($payload['submissionType'] ?? 'info'));
        if (!in_array($submission_type, self::SUBMISSION_TYPES, true)) {
            return new WP_Error('tme_submission_type', __('Please choose information, photos or video.', 'tom-moving-estimate'), array('status' => 400));
        }
        if ($submission_type !== 'info' && !TME_Plugin::is_configured()) {
            return new WP_Error('tme_unavailable', __('Private media storage is being configured. Please try again shortly.', 'tom-moving-estimate'), array('status' => 503));
        }

        $data = array(
            'client_name'         => self::text($payload['clientName'] ?? '', 120),
            'email'               => sanitize_email((string) ($payload['email'] ?? '')),
            'phone'               => self::text($payload['phone'] ?? '', 40),
            'move_date'           => self::text($payload['moveDate'] ?? '', 10),
            'current_address'     => self::text($payload['currentAddress'] ?? '', 255),
            'destination_address' => self::text($payload['destinationAddress'] ?? '', 255),
            'estimated_size'      => self::text($payload['estimatedSize'] ?? '', 32),
            'special_items'       => mb_substr(sanitize_textarea_field((string) ($payload['specialItems'] ?? '')), 0, 2000),
            'submission_type'     => $submission_type,
            'submitted_at'        => $submission_type === 'info' ? current_time('mysql', true) : null,
        );

        if (in_array('', array($data['client_name'], $data['email'], $data['phone'], $data['move_date'], $data['current_address'], $data['destination_address'], $data['estimated_size']), true)) {
            return new WP_Error('tme_required', __('Please complete every required field.', 'tom-moving-estimate'), array('status' => 400));
        }
        if (!is_email($data['email'])) {
            return new WP_Error('tme_email', __('Please enter a valid email address.', 'tom-moving-estimate'), array('status' => 400));
        }
        if (!in_array($data['estimated_size'], self::HOME_SIZES, true)) {
            return new WP_Error('tme_size', __('Please choose a home size.', 'tom-moving-estimate'), array('status' => 400));
        }
        $move_timestamp = strtotime($data['move_date'] . ' 12:00:00');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['move_date']) || !$move_timestamp || $move_timestamp < strtotime('today')) {
            return new WP_Error('tme_date', __('Please choose a valid future move date.', 'tom-moving-estimate'), array('status' => 400));
        }
        if (empty($payload['consent'])) {
            return new WP_Error('tme_consent', __('Please confirm how your estimate information and media may be used.', 'tom-moving-estimate'), array('status' => 400));
        }

        $session = TME_DB::create($data);
        if (is_wp_error($session)) {
            return $session;
        }
        if ($submission_type === 'info') {
            self::send_submission_notifications($session);
        }

        return new WP_REST_Response(array(
            'token'          => $session->public_token,
            'clientName'     => $session->client_name,
            'submissionType' => $session->submission_type,
            'completed'      => $submission_type === 'info',
        ), 201);
    }

    public static function get_session(WP_REST_Request $request)
    {
        $session = TME_DB::get_by_token((string) $request['token']);
        if (!$session) {
            return new WP_Error('tme_not_found', __('Estimate session not found.', 'tom-moving-estimate'), array('status' => 404));
        }
        $photos = TME_DB::photos($session);
        $active_photos = array_filter($photos, static fn(array $photo): bool => empty($photo['deleted_at']));
        return array(
            'clientName'     => $session->client_name,
            'submissionType' => $session->submission_type,
            'videoUploaded'  => (bool) ($session->video_uploaded_at && !$session->video_deleted_at),
            'photoCount'     => count($active_photos),
            'completed'      => (bool) ($session->submitted_at || $session->video_uploaded_at),
        );
    }

    public static function choose_submission_type(WP_REST_Request $request)
    {
        if (self::rate_limited('choose_submission_type', 20, HOUR_IN_SECONDS)) {
            return new WP_Error('tme_rate_limit', __('Too many requests. Please try again later.', 'tom-moving-estimate'), array('status' => 429));
        }

        $session = TME_DB::get_by_token((string) $request['token']);
        if (!$session || self::session_is_expired($session)) {
            return new WP_Error('tme_not_found', __('Estimate session not found.', 'tom-moving-estimate'), array('status' => 404));
        }
        if ($session->submission_type !== 'info') {
            return new WP_Error('tme_submission_locked', __('This estimate has already started a photo or video walkthrough.', 'tom-moving-estimate'), array('status' => 409));
        }

        $payload = $request->get_json_params();
        $submission_type = sanitize_key((string) (is_array($payload) ? ($payload['submissionType'] ?? '') : ''));
        if (!in_array($submission_type, array('photos', 'video'), true)) {
            return new WP_Error('tme_submission_type', __('Please choose photos or video.', 'tom-moving-estimate'), array('status' => 400));
        }
        if (!TME_Plugin::is_configured()) {
            return new WP_Error('tme_unavailable', __('Private media storage is being configured. Please try again shortly.', 'tom-moving-estimate'), array('status' => 503));
        }

        $updated = TME_DB::update(
            $session->id,
            array(
                'submission_type' => $submission_type,
                'submitted_at'    => null,
            ),
            array('%s', '%s')
        );
        if (!$updated) {
            return new WP_Error('tme_submission_update', __('The media walkthrough could not be started. Please try again.', 'tom-moving-estimate'), array('status' => 500));
        }

        return array(
            'clientName'     => $session->client_name,
            'submissionType' => $submission_type,
        );
    }

    public static function upload_url(WP_REST_Request $request)
    {
        if (self::rate_limited('presign_video', 25, HOUR_IN_SECONDS)) {
            return new WP_Error('tme_rate_limit', __('Too many upload attempts. Please try again later.', 'tom-moving-estimate'), array('status' => 429));
        }
        $session = TME_DB::get_by_token((string) $request['token']);
        if (!$session || $session->submission_type !== 'video') {
            return new WP_Error('tme_not_found', __('Video estimate session not found.', 'tom-moving-estimate'), array('status' => 404));
        }
        if ($session->submitted_at || ($session->video_uploaded_at && !$session->video_deleted_at)) {
            return new WP_Error('tme_already_uploaded', __('A video has already been submitted for this estimate.', 'tom-moving-estimate'), array('status' => 409));
        }
        if (self::session_is_expired($session)) {
            return new WP_Error('tme_expired_link', __('This upload link has expired. Please start a new estimate.', 'tom-moving-estimate'), array('status' => 410));
        }

        $payload = $request->get_json_params();
        $payload = is_array($payload) ? $payload : array();
        $content_type = strtolower(trim((string) ($payload['contentType'] ?? '')));
        $content_type = explode(';', $content_type)[0];
        $allowed = array('video/mp4', 'video/webm', 'video/quicktime');
        if (!in_array($content_type, $allowed, true)) {
            return new WP_Error('tme_video_type', __('Please use an MP4, WebM or QuickTime video.', 'tom-moving-estimate'), array('status' => 415));
        }
        $size = (int) ($payload['size'] ?? 0);
        $settings = TME_Plugin::settings();
        $max_bytes = (int) $settings['max_video_mb'] * MB_IN_BYTES;
        if ($size < 1 || $size > $max_bytes) {
            return new WP_Error('tme_video_size', sprintf(__('The video must be smaller than %d MB.', 'tom-moving-estimate'), (int) $settings['max_video_mb']), array('status' => 413));
        }

        $extension = $content_type === 'video/webm' ? 'webm' : ($content_type === 'video/quicktime' ? 'mov' : 'mp4');
        $key = 'sessions/' . $session->id . '/video/' . bin2hex(random_bytes(16)) . '.' . $extension;
        TME_DB::update($session->id, array(
            'pending_video_key'  => $key,
            'video_content_type' => $content_type,
            'video_size'         => $size,
        ), array('%s', '%s', '%d'));

        return array(
            'uploadUrl' => (new TME_R2())->upload_url($key),
            'key'       => $key,
            'maxBytes'  => $max_bytes,
        );
    }

    public static function photo_upload_url(WP_REST_Request $request)
    {
        if (self::rate_limited('presign_photo', 120, HOUR_IN_SECONDS)) {
            return new WP_Error('tme_rate_limit', __('Too many photo upload attempts. Please try again later.', 'tom-moving-estimate'), array('status' => 429));
        }
        $session = TME_DB::get_by_token((string) $request['token']);
        if (!$session || $session->submission_type !== 'photos') {
            return new WP_Error('tme_not_found', __('Photo estimate session not found.', 'tom-moving-estimate'), array('status' => 404));
        }
        if ($session->submitted_at) {
            return new WP_Error('tme_already_uploaded', __('Photos have already been submitted for this estimate.', 'tom-moving-estimate'), array('status' => 409));
        }
        if (self::session_is_expired($session)) {
            return new WP_Error('tme_expired_link', __('This upload link has expired. Please start a new estimate.', 'tom-moving-estimate'), array('status' => 410));
        }

        $payload = $request->get_json_params();
        $payload = is_array($payload) ? $payload : array();
        $content_type = strtolower(trim((string) ($payload['contentType'] ?? '')));
        $content_type = explode(';', $content_type)[0];
        if (!isset(self::PHOTO_TYPES[$content_type])) {
            return new WP_Error('tme_photo_type', __('Please choose JPEG, PNG, WebP, HEIC or HEIF photos.', 'tom-moving-estimate'), array('status' => 415));
        }
        $settings = TME_Plugin::settings();
        $size = (int) ($payload['size'] ?? 0);
        $max_bytes = (int) $settings['max_photo_mb'] * MB_IN_BYTES;
        if ($size < 1 || $size > $max_bytes) {
            return new WP_Error('tme_photo_size', sprintf(__('Each photo must be smaller than %d MB.', 'tom-moving-estimate'), (int) $settings['max_photo_mb']), array('status' => 413));
        }
        $client_id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($payload['clientId'] ?? ''));
        if ($client_id === '' || strlen($client_id) > 64) {
            return new WP_Error('tme_photo_id', __('The photo upload could not be prepared.', 'tom-moving-estimate'), array('status' => 400));
        }

        $pending = TME_DB::photos($session, true);
        foreach ($pending as $photo) {
            if (($photo['client_id'] ?? '') === $client_id) {
                if ((int) ($photo['size'] ?? 0) !== $size || ($photo['type'] ?? '') !== $content_type) {
                    return new WP_Error('tme_photo_changed', __('This photo changed before upload. Remove it and add it again.', 'tom-moving-estimate'), array('status' => 409));
                }
                return array(
                    'uploadUrl' => (new TME_R2())->upload_url((string) $photo['key']),
                    'id'        => $photo['id'],
                    'key'       => $photo['key'],
                    'maxBytes'  => $max_bytes,
                );
            }
        }
        if (count($pending) >= (int) $settings['max_photos']) {
            return new WP_Error('tme_photo_count', sprintf(__('You can submit up to %d photos.', 'tom-moving-estimate'), (int) $settings['max_photos']), array('status' => 413));
        }

        $id = bin2hex(random_bytes(12));
        $extension = self::PHOTO_TYPES[$content_type];
        $key = 'sessions/' . $session->id . '/photos/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $name = sanitize_file_name((string) ($payload['name'] ?? '')) ?: 'photo-' . (count($pending) + 1) . '.' . $extension;
        $pending[] = array(
            'id'        => $id,
            'client_id' => $client_id,
            'key'       => $key,
            'name'      => $name,
            'type'      => $content_type,
            'size'      => $size,
        );
        TME_DB::update($session->id, array('pending_photos' => TME_DB::encode_photos($pending)), array('%s'));

        return array(
            'uploadUrl' => (new TME_R2())->upload_url($key),
            'id'        => $id,
            'key'       => $key,
            'maxBytes'  => $max_bytes,
        );
    }

    public static function complete_upload(WP_REST_Request $request)
    {
        $session = TME_DB::get_by_token((string) $request['token']);
        if (!$session || $session->submission_type !== 'video') {
            return new WP_Error('tme_not_found', __('Video estimate session not found.', 'tom-moving-estimate'), array('status' => 404));
        }
        $payload = $request->get_json_params();
        $key = is_array($payload) ? sanitize_text_field((string) ($payload['key'] ?? '')) : '';
        if (!$key || !$session->pending_video_key || !hash_equals($session->pending_video_key, $key)) {
            return new WP_Error('tme_upload_key', __('The upload could not be verified.', 'tom-moving-estimate'), array('status' => 400));
        }

        $r2 = new TME_R2();
        $head = $r2->head($key);
        if (is_wp_error($head)) {
            return new WP_Error('tme_upload_missing', __('The upload has not finished yet. Wait a moment and try again.', 'tom-moving-estimate'), array('status' => 409));
        }
        $settings = TME_Plugin::settings();
        if ($head['size'] > ((int) $settings['max_video_mb'] * MB_IN_BYTES)) {
            $r2->delete($key);
            return new WP_Error('tme_video_size', __('The uploaded video is too large.', 'tom-moving-estimate'), array('status' => 413));
        }

        $uploaded = time();
        $expires = $uploaded + ((int) $settings['retention_days'] * DAY_IN_SECONDS);
        TME_DB::update($session->id, array(
            'video_key'             => $key,
            'pending_video_key'     => null,
            'video_content_type'    => $head['type'] ?: $session->video_content_type,
            'video_size'            => $head['size'] ?: $session->video_size,
            'video_uploaded_at'     => gmdate('Y-m-d H:i:s', $uploaded),
            'video_expires_at'      => gmdate('Y-m-d H:i:s', $expires),
            'video_deleted_at'      => null,
            'warning_sent_at'       => null,
            'deletion_error'        => '',
            'submitted_at'          => gmdate('Y-m-d H:i:s', $uploaded),
            'media_expires_at'      => gmdate('Y-m-d H:i:s', $expires),
            'media_warning_sent_at' => null,
            'media_deleted_at'      => null,
            'media_deletion_error'  => '',
            'status'                => 'new',
        ), array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'));
        TME_Retention::schedule_for_session((int) $session->id, $uploaded);
        self::send_submission_notifications($session, $expires);

        return array('ok' => true);
    }

    public static function complete_photos(WP_REST_Request $request)
    {
        $session = TME_DB::get_by_token((string) $request['token']);
        if (!$session || $session->submission_type !== 'photos') {
            return new WP_Error('tme_not_found', __('Photo estimate session not found.', 'tom-moving-estimate'), array('status' => 404));
        }
        if ($session->submitted_at) {
            return new WP_Error('tme_already_uploaded', __('Photos have already been submitted for this estimate.', 'tom-moving-estimate'), array('status' => 409));
        }

        $payload = $request->get_json_params();
        $submitted = is_array($payload) && is_array($payload['photos'] ?? null) ? $payload['photos'] : array();
        $settings = TME_Plugin::settings();
        if (!$submitted || count($submitted) > (int) $settings['max_photos']) {
            return new WP_Error('tme_photo_count', sprintf(__('Choose between 1 and %d photos.', 'tom-moving-estimate'), (int) $settings['max_photos']), array('status' => 400));
        }

        $pending = TME_DB::photos($session, true);
        $pending_by_id = array();
        foreach ($pending as $photo) {
            if (!empty($photo['id'])) {
                $pending_by_id[(string) $photo['id']] = $photo;
            }
        }

        $verified = array();
        $seen = array();
        $r2 = new TME_R2();
        $max_bytes = (int) $settings['max_photo_mb'] * MB_IN_BYTES;
        foreach ($submitted as $item) {
            $id = preg_replace('/[^a-f0-9]/', '', (string) ($item['id'] ?? ''));
            $key = sanitize_text_field((string) ($item['key'] ?? ''));
            if (!$id || isset($seen[$id]) || !isset($pending_by_id[$id]) || !hash_equals((string) $pending_by_id[$id]['key'], $key)) {
                return new WP_Error('tme_photo_key', __('One of the photo uploads could not be verified.', 'tom-moving-estimate'), array('status' => 400));
            }
            $seen[$id] = true;
            $photo = $pending_by_id[$id];
            $head = $r2->head($key);
            if (is_wp_error($head)) {
                return new WP_Error('tme_photo_missing', __('A photo has not finished uploading yet. Wait a moment and try again.', 'tom-moving-estimate'), array('status' => 409));
            }
            if ($head['size'] < 1 || $head['size'] > $max_bytes) {
                $r2->delete($key);
                return new WP_Error('tme_photo_size', __('An uploaded photo is too large.', 'tom-moving-estimate'), array('status' => 413));
            }
            $stored_type = strtolower(explode(';', (string) ($head['type'] ?? ''))[0]);
            if ($stored_type !== '' && !isset(self::PHOTO_TYPES[$stored_type])) {
                $r2->delete($key);
                return new WP_Error('tme_photo_type', __('An uploaded file is not a supported photo.', 'tom-moving-estimate'), array('status' => 415));
            }
            unset($photo['client_id']);
            $photo['size'] = $head['size'];
            $photo['type'] = $stored_type ?: $photo['type'];
            $photo['uploaded_at'] = current_time('mysql', true);
            $photo['deleted_at'] = null;
            $photo['last_downloaded_at'] = null;
            $verified[] = $photo;
        }

        $uploaded = time();
        $expires = $uploaded + ((int) $settings['retention_days'] * DAY_IN_SECONDS);
        TME_DB::update($session->id, array(
            'photos'                  => TME_DB::encode_photos($verified),
            'pending_photos'          => '[]',
            'submitted_at'            => gmdate('Y-m-d H:i:s', $uploaded),
            'media_expires_at'        => gmdate('Y-m-d H:i:s', $expires),
            'media_warning_sent_at'   => null,
            'media_deleted_at'        => null,
            'media_deletion_error'    => '',
            'status'                  => 'new',
        ), array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'));
        TME_Retention::schedule_for_session((int) $session->id, $uploaded);
        self::send_submission_notifications($session, $expires, count($verified));

        return array('ok' => true, 'photoCount' => count($verified));
    }

    private static function send_submission_notifications(object $session, ?int $expires = null, int $photo_count = 0): void
    {
        $settings = TME_Plugin::settings();
        $admin_email = sanitize_email((string) $settings['notification_email']);
        $method = $session->submission_type === 'photos'
            ? sprintf('%d photo%s', $photo_count, $photo_count === 1 ? '' : 's')
            : ($session->submission_type === 'video' ? 'video walkthrough' : 'moving information');
        if ($admin_email) {
            $url = admin_url('admin.php?page=tme-estimates&session=' . $session->id);
            $message = "A new moving-estimate request is ready.\n\n";
            $message .= "Customer: {$session->client_name}\nMove date: {$session->move_date}\nSubmission: {$method}\n";
            $message .= "Review: {$url}\n";
            if ($expires) {
                $message .= "Automatic media deletion: " . gmdate('F j, Y', $expires) . "\n";
            }
            wp_mail($admin_email, 'New estimate request: ' . $session->client_name, $message);
        }

        if (is_email($session->email)) {
            $message = "Hi {$session->client_name},\n\nWe received your moving-estimate request ({$method}). Tom Moving will review it and follow up with you.";
            if ($expires) {
                $message .= "\n\nYour photos or video are stored privately and automatically deleted after " . (int) $settings['retention_days'] . " days.";
            }
            $message .= "\n\nTom Moving";
            wp_mail($session->email, 'We received your moving-estimate request', $message);
        }
    }

    public static function shortcode(): string
    {
        wp_enqueue_style('tme-public', TME_URL . 'assets/css/public.css', array(), TME_VERSION);
        wp_enqueue_script('tme-public', TME_URL . 'assets/js/public.js', array(), TME_VERSION, true);

        $token = isset($_GET['session']) ? strtolower(sanitize_text_field(wp_unslash($_GET['session']))) : '';
        $session = $token ? TME_DB::get_by_token($token) : null;
        $completed = $session ? (bool) ($session->submitted_at || $session->video_uploaded_at) : false;
        $initial = $session ? array(
            'token'          => $session->public_token,
            'clientName'     => $session->client_name,
            'submissionType' => $session->submission_type,
            'videoUploaded'  => (bool) $session->video_uploaded_at,
            'completed'      => $completed,
        ) : null;
        $settings = TME_Plugin::settings();
        wp_localize_script('tme-public', 'TMEPublic', array(
            'restUrl'       => esc_url_raw(rest_url('tme/v1/')),
            'estimateUrl'   => esc_url_raw(get_permalink()),
            'initial'       => $initial,
            'maxSeconds'    => 15 * MINUTE_IN_SECONDS,
            'maxBytes'      => (int) $settings['max_video_mb'] * MB_IN_BYTES,
            'maxMb'         => (int) $settings['max_video_mb'],
            'maxPhotos'     => (int) $settings['max_photos'],
            'maxPhotoBytes' => (int) $settings['max_photo_mb'] * MB_IN_BYTES,
            'maxPhotoMb'    => (int) $settings['max_photo_mb'],
            'retentionDays' => (int) $settings['retention_days'],
        ));

        ob_start();
        ?>
        <div class="tme" data-tme-app>
            <section class="tme-intro">
                <p class="tme-eyebrow">Tom Moving</p>
                <h1>Request your moving estimate</h1>
                <p>Tell us about your move first. After your information is saved, you can add photos or record a guided video walkthrough.</p>
            </section>

            <div class="tme-alert" data-tme-alert role="alert" hidden></div>

            <section class="tme-card" data-tme-form-view <?php echo $session ? 'hidden' : ''; ?>>
                <div class="tme-step"><span>1</span><div><strong>Your moving information</strong><small>Please complete every required field</small></div></div>
                <form data-tme-form>
                    <div class="tme-grid">
                        <label><span>Full name *</span><input name="clientName" autocomplete="name" maxlength="120" required></label>
                        <label><span>Email *</span><input name="email" type="email" autocomplete="email" maxlength="190" required></label>
                        <label><span>Phone *</span><input name="phone" type="tel" autocomplete="tel" maxlength="40" required></label>
                        <label><span>Move date *</span><input name="moveDate" type="date" min="<?php echo esc_attr(wp_date('Y-m-d')); ?>" required></label>
                    </div>
                    <label><span>Current address *</span><input name="currentAddress" autocomplete="street-address" maxlength="255" required></label>
                    <label><span>Destination address *</span><input name="destinationAddress" maxlength="255" required></label>
                    <label><span>Estimated home size *</span><select name="estimatedSize" required><option value="">Select home size</option><?php foreach (self::HOME_SIZES as $size) : ?><option value="<?php echo esc_attr($size); ?>"><?php echo esc_html($size); ?></option><?php endforeach; ?></select></label>
                    <label><span>Special items or notes</span><textarea name="specialItems" rows="4" maxlength="2000" placeholder="Piano, antiques, storage areas, stairs, elevator details, items that are not moving..."></textarea></label>
                    <label class="tme-honeypot" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>
                    <label class="tme-consent"><input name="consent" type="checkbox" value="1" required><span>I agree that Tom Moving may use this information to prepare my estimate. If I add photos or video, the files will be stored privately and automatically deleted after <?php echo esc_html((string) $settings['retention_days']); ?> days.</span></label>
                    <button class="tme-button tme-button--primary tme-button--wide" type="submit" data-tme-form-submit>Continue</button>
                    <p class="tme-footnote">Your submission is used only to prepare your moving estimate.</p>
                </form>
            </section>

            <section class="tme-card" data-tme-choice-view <?php echo (!$session || $session->submission_type !== 'info') ? 'hidden' : ''; ?>>
                <div class="tme-step"><span>2</span><div><strong>Add visual details</strong><small>Your required information is saved</small></div></div>
                <h2>How would you like to show the move?</h2>
                <p>Thank you, <strong data-tme-choice-client-name><?php echo $session ? esc_html($session->client_name) : ''; ?></strong>. Choose photos or a guided video walkthrough.</p>
                <div class="tme-choice-grid">
                    <button class="tme-choice" type="button" data-tme-choose-photos>
                        <strong>Upload photos</strong>
                        <span>Add up to <?php echo esc_html((string) $settings['max_photos']); ?> photos of the rooms, furniture and access route.</span>
                    </button>
                    <button class="tme-choice tme-choice--video" type="button" data-tme-choose-video>
                        <strong>Start video walkthrough</strong>
                        <span>Record the parking route, entrances, stairs, rooms, furniture and items.</span>
                    </button>
                </div>
            </section>

            <section class="tme-card" data-tme-photo-view <?php echo (!$session || $session->submission_type !== 'photos' || $completed) ? 'hidden' : ''; ?>>
                <div class="tme-step"><span>2</span><div><strong>Photo walkthrough</strong><small>Step 2 of 2</small></div></div>
                <h2>Add photos of your move</h2>
                <p>Hello <strong data-tme-photo-client-name><?php echo $session ? esc_html($session->client_name) : ''; ?></strong>. Include furniture, rooms, stairs, entrances and the truck parking route.</p>
                <div class="tme-photo-actions">
                    <label class="tme-button tme-button--primary">Choose photos<input type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,image/*" multiple data-tme-photo-files></label>
                    <label class="tme-button">Take a photo<input type="file" accept="image/*" capture="environment" data-tme-photo-camera></label>
                    <span data-tme-photo-count>0 of <?php echo esc_html((string) $settings['max_photos']); ?> photos</span>
                </div>
                <div class="tme-photo-grid" data-tme-photo-grid></div>
                <p class="tme-photo-empty" data-tme-photo-empty>No photos selected yet.</p>
                <div class="tme-progress" data-tme-photo-progress hidden><span data-tme-photo-progress-bar></span></div>
                <p class="tme-progress-text" data-tme-photo-progress-text hidden></p>
                <button class="tme-button tme-button--primary tme-button--wide" type="button" data-tme-submit-photos disabled>Submit photos</button>
            </section>

            <section class="tme-card" data-tme-recorder-view <?php echo (!$session || $session->submission_type !== 'video' || $completed) ? 'hidden' : ''; ?>>
                <div class="tme-step"><span>2</span><div><strong>Video walkthrough</strong><small>Step 2 of 2</small></div></div>
                <h2>Show us the full moving route</h2>
                <p>Hello <strong data-tme-client-name><?php echo $session ? esc_html($session->client_name) : ''; ?></strong>. Move slowly and describe what you see.</p>
                <ol class="tme-checklist">
                    <li>Start outside: show where the truck can park and the route to the entrance.</li>
                    <li>Show entrances, elevators, stairs, hallways and tight corners.</li>
                    <li>Visit every room and say the room name.</li>
                    <li>Point out furniture that is moving and clearly identify anything staying behind.</li>
                    <li>Show boxes, loose items, basement, garage, storage and every floor.</li>
                </ol>

                <div class="tme-permission" data-tme-permission>
                    <h3>Ready to record?</h3>
                    <p>Your browser will ask for camera and microphone access. Nothing is uploaded until you review it and press Submit.</p>
                    <button class="tme-button tme-button--primary" type="button" data-tme-enable>Enable camera</button>
                    <label class="tme-file-fallback">Or choose an existing video<input type="file" accept="video/mp4,video/webm,video/quicktime,video/*" capture="environment" data-tme-file></label>
                </div>

                <div class="tme-recorder" data-tme-recorder hidden>
                    <div class="tme-video-stage">
                        <video data-tme-video playsinline muted></video>
                        <span class="tme-rec-pill" data-tme-rec-pill hidden><i></i> REC <b data-tme-clock>0:00</b></span>
                        <button class="tme-camera-switch" type="button" data-tme-switch hidden>Switch camera</button>
                        <button class="tme-exit-fullscreen" type="button" data-tme-exit-fullscreen hidden aria-label="Exit full-screen recording">Exit full screen</button>
                    </div>
                    <div class="tme-controls">
                        <p data-tme-help>Hold your phone horizontally when possible.</p>
                        <div class="tme-button-row">
                            <button class="tme-button tme-button--record" type="button" data-tme-start>Start recording</button>
                            <button class="tme-button tme-button--stop" type="button" data-tme-stop hidden>Stop recording</button>
                            <button class="tme-button" type="button" data-tme-retake hidden>Retake</button>
                            <button class="tme-button tme-button--primary" type="button" data-tme-submit hidden>Submit video</button>
                        </div>
                        <div class="tme-progress" data-tme-progress hidden><span data-tme-progress-bar></span></div>
                        <p class="tme-progress-text" data-tme-progress-text hidden></p>
                    </div>
                </div>
            </section>

            <section class="tme-card tme-thanks" data-tme-done-view <?php echo (!$session || !$completed || $session->submission_type === 'info') ? 'hidden' : ''; ?>>
                <span class="tme-checkmark">&#10003;</span>
                <h2>Thank you<?php echo $session ? ', ' . esc_html($session->client_name) : ''; ?>!</h2>
                <p data-tme-done-message>Your moving-estimate request was received. Tom Moving will review it and follow up with you.</p>
            </section>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
