<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TME_Public
{
    private const HOME_SIZES = array('Studio', '1 bedroom', '2 bedrooms', '3 bedrooms', '4+ bedrooms', 'House', 'Storage unit', 'Other');
    private const SUBMISSION_TYPES = array('info', 'photos', 'video');
    /** Video goes up in 8 MB parts; R2 needs at least 5 MB for every part but the last. */
    private const VIDEO_PART_BYTES = 8 * 1048576;
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
        add_action('tme_send_customer_progress_email', array(__CLASS__, 'send_customer_progress_email'));
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
        register_rest_route('tme/v1', '/sessions/(?P<token>[a-f0-9]{64})/video-upload', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'start_video_upload'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('tme/v1', '/sessions/(?P<token>[a-f0-9]{64})/video-parts', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'video_part_urls'),
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

    /** French or English text for a customer-facing message, by the form's language. */
    private static function pick(string $language, string $en, string $fr): string
    {
        return $language === 'fr' ? $fr : $en;
    }

    /** Keeps the page the form was filled on, so the customer email can link back to it. Other sites are ignored. */
    private static function same_site_url(string $url): string
    {
        $url = esc_url_raw(mb_substr($url, 0, 255));
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $home = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        return ($url !== '' && $host === $home) ? (string) strtok($url, '?#') : '';
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

        $lang = ($payload['language'] ?? '') === 'fr' ? 'fr' : 'en';
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
            'language'            => $lang,
            'form_url'            => self::same_site_url((string) ($payload['formUrl'] ?? '')),
        );

        // Email, destination, move date and home size are optional: a phone number is enough to follow up.
        if (in_array('', array($data['client_name'], $data['phone'], $data['current_address']), true)) {
            return new WP_Error('tme_required', self::pick($lang, 'Please complete every required field.', 'Veuillez remplir tous les champs obligatoires.'), array('status' => 400));
        }
        if ($data['email'] !== '' && !is_email($data['email'])) {
            return new WP_Error('tme_email', self::pick($lang, 'Please enter a valid email address.', 'Veuillez entrer une adresse courriel valide.'), array('status' => 400));
        }
        if ($data['estimated_size'] !== '' && !in_array($data['estimated_size'], self::HOME_SIZES, true)) {
            return new WP_Error('tme_size', self::pick($lang, 'Please choose a home size.', 'Veuillez choisir la taille du logement.'), array('status' => 400));
        }
        if ($data['move_date'] !== '') {
            $move_timestamp = strtotime($data['move_date'] . ' 12:00:00');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['move_date']) || !$move_timestamp || $move_timestamp < strtotime('today')) {
                return new WP_Error('tme_date', self::pick($lang, 'Please choose a valid future move date.', 'Veuillez choisir une date de déménagement future valide.'), array('status' => 400));
            }
        }
        if (empty($payload['consent'])) {
            return new WP_Error('tme_consent', self::pick($lang, 'Please confirm how your estimate information and media may be used.', 'Veuillez confirmer l’utilisation de vos renseignements et de vos fichiers.'), array('status' => 400));
        }

        $session = TME_DB::create($data);
        if (is_wp_error($session)) {
            return $session;
        }
        if ($submission_type === 'info') {
            self::send_admin_step_notification($session, 1);
            self::schedule_customer_email($session);
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

        $session->submission_type = $submission_type;
        self::send_admin_step_notification($session, 2);
        self::schedule_customer_email($session);

        return array(
            'clientName'     => $session->client_name,
            'submissionType' => $submission_type,
        );
    }

    /** Checks shared by the single-PUT and multipart video uploads. Returns the content type or an error. */
    private static function video_upload_checks(object $session, array $payload)
    {
        $lang = (string) ($session->language ?? 'en');
        if ($session->submitted_at || ($session->video_uploaded_at && !$session->video_deleted_at)) {
            return new WP_Error('tme_already_uploaded', self::pick($lang, 'A video has already been submitted for this estimate.', 'Une vidéo a déjà été envoyée pour cette estimation.'), array('status' => 409));
        }
        if (self::session_is_expired($session)) {
            return new WP_Error('tme_expired_link', self::pick($lang, 'This upload link has expired. Please start a new estimate.', 'Ce lien de téléversement a expiré. Veuillez commencer une nouvelle estimation.'), array('status' => 410));
        }
        $content_type = strtolower(trim((string) ($payload['contentType'] ?? '')));
        $content_type = explode(';', $content_type)[0];
        if (!in_array($content_type, array('video/mp4', 'video/webm', 'video/quicktime'), true)) {
            return new WP_Error('tme_video_type', self::pick($lang, 'Please use an MP4, WebM or QuickTime video.', 'Veuillez utiliser une vidéo MP4, WebM ou QuickTime.'), array('status' => 415));
        }
        $size = (int) ($payload['size'] ?? 0);
        $max_mb = (int) TME_Plugin::settings()['max_video_mb'];
        if ($size < 1 || $size > $max_mb * MB_IN_BYTES) {
            return new WP_Error('tme_video_size', sprintf(self::pick($lang, 'The video must be smaller than %d MB.', 'La vidéo doit faire moins de %d Mo.'), $max_mb), array('status' => 413));
        }
        return $content_type;
    }

    private static function video_part_count(object $session): int
    {
        return max(1, (int) ceil(((int) $session->video_size) / self::VIDEO_PART_BYTES));
    }

    /** Starts a multipart upload: the browser then sends 8 MB parts and retries any part that fails. */
    public static function start_video_upload(WP_REST_Request $request)
    {
        $session = TME_DB::get_by_token((string) $request['token']);
        $lang = (string) ($session->language ?? 'en');
        if (self::rate_limited('presign_video', 25, HOUR_IN_SECONDS)) {
            return new WP_Error('tme_rate_limit', self::pick($lang, 'Too many upload attempts. Please try again later.', 'Trop de tentatives de téléversement. Veuillez réessayer plus tard.'), array('status' => 429));
        }
        if (!$session || $session->submission_type !== 'video') {
            return new WP_Error('tme_not_found', self::pick($lang, 'Video estimate session not found.', 'Demande d’estimation vidéo introuvable.'), array('status' => 404));
        }
        $payload = $request->get_json_params();
        $payload = is_array($payload) ? $payload : array();
        $content_type = self::video_upload_checks($session, $payload);
        if (is_wp_error($content_type)) {
            return $content_type;
        }

        $r2 = new TME_R2();
        if ($session->pending_video_key && $session->pending_upload_id) {
            $r2->abort_multipart((string) $session->pending_video_key, (string) $session->pending_upload_id);
        }
        $extension = $content_type === 'video/webm' ? 'webm' : ($content_type === 'video/quicktime' ? 'mov' : 'mp4');
        $key = 'sessions/' . $session->id . '/video/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $upload_id = $r2->create_multipart($key, $content_type);
        if (is_wp_error($upload_id)) {
            return new WP_Error('tme_upload_start', self::pick($lang, 'The upload could not be started. Please try again.', 'Le téléversement n’a pas pu démarrer. Veuillez réessayer.'), array('status' => 502));
        }
        $size = (int) $payload['size'];
        TME_DB::update($session->id, array(
            'pending_video_key'  => $key,
            'pending_upload_id'  => $upload_id,
            'video_content_type' => $content_type,
            'video_size'         => $size,
        ), array('%s', '%s', '%s', '%d'));

        return array(
            'key'       => $key,
            'partSize'  => self::VIDEO_PART_BYTES,
            'partCount' => (int) ceil($size / self::VIDEO_PART_BYTES),
        );
    }

    /** Signed URLs for up to 10 parts at a time, so a long upload never runs into an expired link. */
    public static function video_part_urls(WP_REST_Request $request)
    {
        $session = TME_DB::get_by_token((string) $request['token']);
        $lang = (string) ($session->language ?? 'en');
        if (self::rate_limited('presign_video_parts', 400, HOUR_IN_SECONDS)) {
            return new WP_Error('tme_rate_limit', self::pick($lang, 'Too many upload attempts. Please try again later.', 'Trop de tentatives de téléversement. Veuillez réessayer plus tard.'), array('status' => 429));
        }
        if (!$session || $session->submission_type !== 'video' || !$session->pending_upload_id || self::session_is_expired($session)) {
            return new WP_Error('tme_not_found', self::pick($lang, 'Video estimate session not found.', 'Demande d’estimation vidéo introuvable.'), array('status' => 404));
        }
        $payload = $request->get_json_params();
        $numbers = is_array($payload) && is_array($payload['parts'] ?? null) ? array_slice($payload['parts'], 0, 10) : array();
        $count = self::video_part_count($session);
        $r2 = new TME_R2();
        $urls = array();
        foreach ($numbers as $number) {
            $number = (int) $number;
            if ($number >= 1 && $number <= $count) {
                $urls[$number] = $r2->part_url((string) $session->pending_video_key, (string) $session->pending_upload_id, $number);
            }
        }
        if (!$urls) {
            return new WP_Error('tme_upload_parts', self::pick($lang, 'The upload could not be verified.', 'Le téléversement n’a pas pu être vérifié.'), array('status' => 400));
        }
        // A long upload is still progress: push the customer email back so it doesn't say "didn't finish" mid-upload.
        self::schedule_customer_email($session);
        return array('urls' => $urls);
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
            'pending_upload_id'  => null,
            'video_content_type' => $content_type,
            'video_size'         => $size,
        ), array('%s', '%s', '%s', '%d'));

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
        if (!empty($session->pending_upload_id)) {
            $not_finished = new WP_Error('tme_upload_missing', self::pick((string) ($session->language ?? 'en'), 'The upload has not finished yet. Wait a moment and try again.', 'Le téléversement n’est pas encore terminé. Attendez un instant et réessayez.'), array('status' => 409));
            $parts = $r2->list_parts($key, (string) $session->pending_upload_id);
            if (is_wp_error($parts) || count($parts) < self::video_part_count($session)) {
                return $not_finished;
            }
            if (is_wp_error($r2->complete_multipart($key, (string) $session->pending_upload_id, $parts))) {
                return $not_finished;
            }
            TME_DB::update($session->id, array('pending_upload_id' => null), array('%s'));
        }
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
        self::send_admin_step_notification($session, 3, $expires);
        self::schedule_customer_email($session);

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
        self::send_admin_step_notification($session, 3, $expires, count($verified));
        self::schedule_customer_email($session);

        return array('ok' => true, 'photoCount' => count($verified));
    }

    private static function send_admin_step_notification(object $session, int $step, ?int $expires = null, int $photo_count = 0): void
    {
        $settings = TME_Plugin::settings();
        $admin_email = sanitize_email((string) $settings['notification_email']);
        if (!$admin_email) {
            return;
        }

        $url = admin_url('admin.php?page=tme-estimates&session=' . $session->id);
        $message = "Customer: {$session->client_name}\nPhone: {$session->phone}\n";
        $message .= 'Email: ' . ($session->email !== '' ? $session->email : '(not given)') . "\n";
        $message .= 'Move date: ' . ($session->move_date ?: '(not given)') . "\n";
        $message .= 'Language: ' . (($session->language ?? 'en') === 'fr' ? 'French' : 'English') . "\n";

        if ($step === 1) {
            $message .= "Stage: Contact information submitted.\n";
        } elseif ($step === 2) {
            $method = $session->submission_type === 'photos' ? 'photo walkthrough' : 'video walkthrough';
            $message .= "Stage: Chose to continue with a {$method} (not yet uploaded).\n";
        } else {
            $method = $session->submission_type === 'photos'
                ? sprintf('%d photo%s', $photo_count, $photo_count === 1 ? '' : 's')
                : 'video walkthrough';
            $message .= "Stage: Completed submission ({$method}).\n";
            if ($expires) {
                $message .= "Automatic media deletion: " . gmdate('F j, Y', $expires) . "\n";
            }
        }

        $message .= "Review: {$url}\n";
        $subject = sprintf('Site form lead %s - Step %d', $session->client_name, $step);
        wp_mail($admin_email, $subject, $message);
    }

    private static function schedule_customer_email(object $session): void
    {
        $hook = 'tme_send_customer_progress_email';
        $args = array((int) $session->id);
        $existing = wp_next_scheduled($hook, $args);
        if ($existing) {
            wp_unschedule_event($existing, $hook, $args);
        }
        wp_schedule_single_event(time() + (15 * MINUTE_IN_SECONDS), $hook, $args);
    }

    public static function send_customer_progress_email(int $session_id): void
    {
        $session = TME_DB::get($session_id);
        if (!$session || !is_email($session->email)) {
            return;
        }

        $settings = TME_Plugin::settings();
        $completed = (bool) ($session->submitted_at || $session->video_uploaded_at);
        $fr = ($session->language ?? 'en') === 'fr';
        $days = (int) $settings['retention_days'];
        $name = $session->client_name;

        if ($completed) {
            $method = $session->submission_type === 'photos'
                ? 'photos'
                : ($session->submission_type === 'video' ? ($fr ? 'visite vidéo' : 'video walkthrough') : ($fr ? 'informations de déménagement' : 'moving information'));
            $message = $fr
                ? "Bonjour {$name},\n\nNous avons bien reçu votre demande d’estimation ({$method}). Déménagement Tom l’examinera et communiquera avec vous."
                : "Hi {$name},\n\nWe received your moving-estimate request ({$method}). Tom Moving will review it and follow up with you.";
            if ($session->submission_type !== 'info') {
                $message .= $fr
                    ? "\n\nVos photos ou votre vidéo sont conservées de façon privée et supprimées automatiquement après {$days} jours."
                    : "\n\nYour photos or video are stored privately and automatically deleted after {$days} days.";
            }
        } elseif ($session->submission_type !== 'info') {
            $method = $session->submission_type === 'photos' ? ($fr ? 'des photos' : 'photos') : ($fr ? 'une visite vidéo' : 'a video walkthrough');
            $message = $fr
                ? "Bonjour {$name},\n\nNous avons bien reçu vos informations de déménagement. Vous avez commencé à ajouter {$method}, mais l’envoi ne s’est pas terminé. Vous pouvez revenir le terminer quand vous voulez : vos réponses sont enregistrées."
                : "Hi {$name},\n\nWe received your moving information, and it looks like you started adding {$method} but didn't finish. Feel free to return anytime to complete it — your earlier answers are saved.";
            if (!empty($session->form_url)) {
                $message .= "\n\n" . add_query_arg('session', $session->public_token, $session->form_url);
            }
        } else {
            $message = $fr
                ? "Bonjour {$name},\n\nNous avons bien reçu vos informations de déménagement. Déménagement Tom les examinera et communiquera avec vous."
                : "Hi {$name},\n\nWe received your moving information. Tom Moving will review it and follow up with you.";
        }
        $message .= $fr ? "\n\nDéménagement Tom" : "\n\nTom Moving";

        wp_mail($session->email, $fr ? 'Nous avons bien reçu votre demande d’estimation' : 'We received your moving-estimate request', $message);
    }

    private static function detect_language($atts = array()): string
    {
        $atts = is_array($atts) ? $atts : array();
        $requested = isset($atts['lang']) ? strtolower(sanitize_key((string) $atts['lang'])) : '';
        if (in_array($requested, array('en', 'fr'), true)) {
            return $requested;
        }

        if (function_exists('pll_current_language')) {
            $polylang = strtolower((string) pll_current_language('slug'));
            if (in_array($polylang, array('en', 'fr'), true)) {
                return $polylang;
            }
        }

        $path = strtolower((string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
        return preg_match('#(^|/)fr(/|$)#', $path) ? 'fr' : 'en';
    }

    private static function home_size_label(string $size, bool $is_fr): string
    {
        if (!$is_fr) {
            return $size;
        }

        $labels = array(
            'Studio'       => 'Studio',
            '1 bedroom'    => '1 chambre',
            '2 bedrooms'   => '2 chambres',
            '3 bedrooms'   => '3 chambres',
            '4+ bedrooms'  => '4 chambres ou plus',
            'House'        => 'Maison',
            'Storage unit' => 'Unité d’entreposage',
            'Other'        => 'Autre',
        );

        return $labels[$size] ?? $size;
    }

    private static function i18n_strings(bool $is_fr): array
    {
        if (!$is_fr) {
            return array(
                'generic_error' => 'Something went wrong. Please try again.',
                'network_error' => 'The browser could not reach private media storage. Check your connection and try again.',
                'done_photos' => 'Your photos were uploaded securely. Tom Moving will review them and follow up with you.',
                'done_video' => 'Your video walkthrough was uploaded securely. Tom Moving will review it and follow up with you.',
                'done_info' => 'Your moving information was received. Tom Moving will review it and follow up with you.',
                'thank_you_prefix' => 'Thank you, ',
                'thank_you_suffix' => '!',
                'fallback_there' => 'there',
                'choose_type_error' => 'The media walkthrough could not be started. Please try again.',
                'max_photos_prefix' => 'You can submit up to ',
                'max_photos_suffix' => ' photos.',
                'invalid_photo_type' => 'Please choose JPEG, PNG, WebP, HEIC or HEIF photos.',
                'photo_too_large_prefix' => 'Each photo must be smaller than ',
                'photo_too_large_suffix' => ' MB.',
                'selected_photo_alt_prefix' => 'Selected photo ',
                'photo_fallback_name_prefix' => 'Photo ',
                'remove_label' => 'Remove',
                'remove_aria_prefix' => 'Remove ',
                'photo_count_mid' => ' of ',
                'photo_count_suffix' => ' photos',
                'preparing_photo_prefix' => 'Preparing photo ',
                'preparing_photo_mid' => ' of ',
                'preparing_photo_suffix' => '...',
                'uploading_photo_prefix' => 'Uploading photo ',
                'uploading_photo_mid' => ' of ',
                'uploading_photo_mid2' => '... ',
                'uploading_photo_suffix' => '%',
                'verifying_photos' => 'Verifying photos...',
                'photos_submit_error' => 'The photos could not be submitted. Please try again.',
                'help_preview' => 'Hold your phone horizontally when possible, then walk at a comfortable pace.',
                'help_review' => 'Review your walkthrough before sending it.',
                'unsupported_browser' => 'This browser cannot record directly. Choose an existing video instead, or use the current Safari, Chrome or Edge browser.',
                'camera_blocked' => 'Camera or microphone access was blocked. Allow access in your browser settings, then try again. You can also choose an existing video.',
                'recording_error' => 'Recording stopped unexpectedly. Please try again.',
                'no_video_captured' => 'No video was captured. Please try again.',
                'recording_too_large_prefix' => 'This recording is larger than ',
                'recording_too_large_suffix' => ' MB. Please retake a shorter walkthrough.',
                'recording_time_prefix' => 'Recording ',
                'recording_time_suffix' => ' - maximum 15:00',
                'recorder_start_error' => 'This browser could not start the recorder. Try the current Safari, Chrome or Edge browser.',
                'video_too_large_prefix' => 'This video is larger than ',
                'video_too_large_suffix' => ' MB. Please choose or record a shorter video.',
                'invalid_video_type' => 'Please choose an MP4, WebM or QuickTime video.',
                'video_too_long' => 'The video is longer than 15 minutes. Please choose or record a shorter walkthrough.',
                'preparing_upload' => 'Preparing secure upload...',
                'uploading_securely_prefix' => 'Uploading securely... ',
                'uploading_securely_suffix' => '%',
                'verifying_upload' => 'Verifying upload...',
                'upload_failed' => 'Upload failed. Please try again.',
                'submitting_label' => 'Submitting...',
                'estimate_start_error' => 'The estimate could not be started. Please try again.',
                'upload_failed_labeled_prefix' => '',
                'label_file_of' => 'File',
                'label_photo_of' => 'Photo',
                'label_video_of' => 'Video',
                'upload_failed_labeled_mid' => ' upload failed (',
                'upload_failed_labeled_suffix' => '). Please try again.',
                'retrying_part_prefix' => 'Connection lost. Retrying part ',
                'retrying_part_mid' => ' of ',
                'retrying_part_suffix' => '...',
            );
        }

        return array(
            'generic_error' => 'Une erreur est survenue. Veuillez réessayer.',
            'network_error' => 'Le navigateur n’a pas pu joindre le stockage privé des médias. Vérifiez votre connexion et réessayez.',
            'done_photos' => 'Vos photos ont été téléversées en toute sécurité. Déménagement Tom les examinera et communiquera avec vous.',
            'done_video' => 'Votre visite vidéo a été téléversée en toute sécurité. Déménagement Tom l’examinera et communiquera avec vous.',
            'done_info' => 'Vos informations de déménagement ont été reçues. Déménagement Tom les examinera et communiquera avec vous.',
            'thank_you_prefix' => 'Merci, ',
            'thank_you_suffix' => '!',
            'fallback_there' => 'vous',
            'choose_type_error' => 'La visite multimédia n’a pas pu démarrer. Veuillez réessayer.',
            'max_photos_prefix' => 'Vous pouvez soumettre jusqu’à ',
            'max_photos_suffix' => ' photos.',
            'invalid_photo_type' => 'Veuillez choisir des photos JPEG, PNG, WebP, HEIC ou HEIF.',
            'photo_too_large_prefix' => 'Chaque photo doit être plus petite que ',
            'photo_too_large_suffix' => ' Mo.',
            'selected_photo_alt_prefix' => 'Photo sélectionnée ',
            'photo_fallback_name_prefix' => 'Photo ',
            'remove_label' => 'Retirer',
            'remove_aria_prefix' => 'Retirer ',
            'photo_count_mid' => ' sur ',
            'photo_count_suffix' => ' photos',
            'preparing_photo_prefix' => 'Préparation de la photo ',
            'preparing_photo_mid' => ' sur ',
            'preparing_photo_suffix' => '...',
            'uploading_photo_prefix' => 'Téléversement de la photo ',
            'uploading_photo_mid' => ' sur ',
            'uploading_photo_mid2' => '... ',
            'uploading_photo_suffix' => ' %',
            'verifying_photos' => 'Vérification des photos...',
            'photos_submit_error' => 'Les photos n’ont pas pu être soumises. Veuillez réessayer.',
            'help_preview' => 'Tenez votre téléphone à l’horizontale lorsque possible, puis marchez à un rythme confortable.',
            'help_review' => 'Révisez votre visite avant de l’envoyer.',
            'unsupported_browser' => 'Ce navigateur ne peut pas enregistrer directement. Choisissez une vidéo existante, ou utilisez une version récente de Safari, Chrome ou Edge.',
            'camera_blocked' => 'L’accès à la caméra ou au microphone a été bloqué. Autorisez l’accès dans les réglages de votre navigateur, puis réessayez. Vous pouvez aussi choisir une vidéo existante.',
            'recording_error' => 'L’enregistrement s’est arrêté de façon inattendue. Veuillez réessayer.',
            'no_video_captured' => 'Aucune vidéo n’a été captée. Veuillez réessayer.',
            'recording_too_large_prefix' => 'Cet enregistrement dépasse ',
            'recording_too_large_suffix' => ' Mo. Veuillez reprendre une visite plus courte.',
            'recording_time_prefix' => 'Enregistrement ',
            'recording_time_suffix' => ' - maximum 15:00',
            'recorder_start_error' => 'Ce navigateur n’a pas pu démarrer l’enregistreur. Essayez une version récente de Safari, Chrome ou Edge.',
            'video_too_large_prefix' => 'Cette vidéo dépasse ',
            'video_too_large_suffix' => ' Mo. Veuillez choisir ou enregistrer une vidéo plus courte.',
            'invalid_video_type' => 'Veuillez choisir une vidéo MP4, WebM ou QuickTime.',
            'video_too_long' => 'La vidéo dépasse 15 minutes. Veuillez choisir ou enregistrer une visite plus courte.',
            'preparing_upload' => 'Préparation du téléversement sécurisé...',
            'uploading_securely_prefix' => 'Téléversement sécurisé... ',
            'uploading_securely_suffix' => ' %',
            'verifying_upload' => 'Vérification du téléversement...',
            'upload_failed' => 'Le téléversement a échoué. Veuillez réessayer.',
            'submitting_label' => 'Envoi en cours...',
            'estimate_start_error' => 'L’estimation n’a pas pu démarrer. Veuillez réessayer.',
            'upload_failed_labeled_prefix' => 'Le téléversement ',
            'label_file_of' => 'du fichier',
            'label_photo_of' => 'de la photo',
            'label_video_of' => 'de la vidéo',
            'upload_failed_labeled_mid' => ' a échoué (',
            'upload_failed_labeled_suffix' => '). Veuillez réessayer.',
            'retrying_part_prefix' => 'Connexion perdue. Nouvel essai de la partie ',
            'retrying_part_mid' => ' sur ',
            'retrying_part_suffix' => '...',
        );
    }

    public static function shortcode($atts = array()): string
    {
        wp_enqueue_style('tme-public', TME_URL . 'assets/css/public.css', array(), TME_VERSION);
        wp_enqueue_script('tme-public', TME_URL . 'assets/js/public.js', array(), TME_VERSION, true);

        $language = self::detect_language($atts);
        $is_fr = $language === 'fr';

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
            'locale'        => $language,
            'i18n'          => self::i18n_strings($is_fr),
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
        <div class="tme" data-tme-app data-language="<?php echo esc_attr($language); ?>">
            <section class="tme-intro">
                <p class="tme-eyebrow">Tom Moving</p>
                <h1><?php echo $is_fr ? 'Demandez votre estimation de déménagement' : 'Request your moving estimate'; ?></h1>
                <p><?php echo $is_fr ? 'Parlez-nous d’abord de votre déménagement. Une fois vos informations enregistrées, vous pourrez ajouter des photos ou enregistrer une visite vidéo guidée.' : 'Tell us about your move first. After your information is saved, you can add photos or record a guided video walkthrough.'; ?></p>
            </section>

            <div class="tme-alert" data-tme-alert role="alert" hidden></div>

            <section class="tme-card" data-tme-form-view <?php echo $session ? 'hidden' : ''; ?>>
                <div class="tme-step"><span>1</span><div><strong><?php echo $is_fr ? 'Vos informations de déménagement' : 'Your moving information'; ?></strong><small><?php echo $is_fr ? 'Veuillez remplir tous les champs obligatoires' : 'Please complete every required field'; ?></small></div></div>
                <form data-tme-form>
                    <div class="tme-grid">
                        <label><span><?php echo $is_fr ? 'Nom complet *' : 'Full name *'; ?></span><input name="clientName" autocomplete="name" maxlength="120" required></label>
                        <label><span><?php echo $is_fr ? 'Courriel' : 'Email'; ?></span><input name="email" type="email" autocomplete="email" maxlength="190"></label>
                        <label><span><?php echo $is_fr ? 'Téléphone *' : 'Phone *'; ?></span><input name="phone" type="tel" autocomplete="tel" maxlength="40" required></label>
                        <label><span><?php echo $is_fr ? 'Date du déménagement' : 'Move date'; ?></span><input name="moveDate" type="date" min="<?php echo esc_attr(wp_date('Y-m-d')); ?>"></label>
                    </div>
                    <div class="tme-form-more" data-tme-form-more hidden>
                        <label><span><?php echo $is_fr ? 'Adresse actuelle *' : 'Current address *'; ?></span><input name="currentAddress" autocomplete="street-address" maxlength="255" required></label>
                        <label><span><?php echo $is_fr ? 'Adresse de destination' : 'Destination address'; ?></span><input name="destinationAddress" maxlength="255"></label>
                        <label><span><?php echo $is_fr ? 'Taille estimée du logement' : 'Estimated home size'; ?></span><select name="estimatedSize"><option value=""><?php echo $is_fr ? 'Sélectionnez la taille du logement' : 'Select home size'; ?></option><?php foreach (self::HOME_SIZES as $size) : ?><option value="<?php echo esc_attr($size); ?>"><?php echo esc_html(self::home_size_label($size, $is_fr)); ?></option><?php endforeach; ?></select></label>
                        <label><span><?php echo $is_fr ? 'Objets spéciaux ou notes' : 'Special items or notes'; ?></span><textarea name="specialItems" rows="4" maxlength="2000" placeholder="<?php echo esc_attr($is_fr ? 'Piano, antiquités, espaces de rangement, escaliers, détails sur l’ascenseur, objets qui ne déménagent pas...' : 'Piano, antiques, storage areas, stairs, elevator details, items that are not moving...'); ?>"></textarea></label>
                        <label class="tme-honeypot" aria-hidden="true"><?php echo $is_fr ? 'Site web' : 'Website'; ?><input name="website" tabindex="-1" autocomplete="off"></label>
                        <label class="tme-consent"><input name="consent" type="checkbox" value="1" required><span><?php echo $is_fr
                            ? 'J’accepte que Déménagement Tom utilise ces renseignements pour préparer mon estimation. Si j’ajoute des photos ou une vidéo, les fichiers seront stockés de façon privée et automatiquement supprimés après ' . esc_html((string) $settings['retention_days']) . ' jours.'
                            : 'I agree that Tom Moving may use this information to prepare my estimate. If I add photos or video, the files will be stored privately and automatically deleted after ' . esc_html((string) $settings['retention_days']) . ' days.'; ?></span></label>
                    </div>
                    <button class="tme-button tme-button--primary tme-button--wide" type="submit" data-tme-form-submit><?php echo $is_fr ? 'Continuer' : 'Continue'; ?></button>
                    <p class="tme-footnote"><?php echo $is_fr ? 'Votre soumission sert uniquement à préparer votre estimation de déménagement.' : 'Your submission is used only to prepare your moving estimate.'; ?></p>
                </form>
            </section>

            <section class="tme-card" data-tme-choice-view <?php echo (!$session || $session->submission_type !== 'info') ? 'hidden' : ''; ?>>
                <div class="tme-step"><span>2</span><div><strong><?php echo $is_fr ? 'Ajoutez des détails visuels' : 'Add visual details'; ?></strong><small><?php echo $is_fr ? 'Vos informations obligatoires sont enregistrées' : 'Your required information is saved'; ?></small></div></div>
                <h2><?php echo $is_fr ? 'Comment souhaitez-vous présenter le déménagement?' : 'How would you like to show the move?'; ?></h2>
                <p><?php echo $is_fr ? 'Merci, ' : 'Thank you, '; ?><strong data-tme-choice-client-name><?php echo $session ? esc_html($session->client_name) : ''; ?></strong><?php echo $is_fr ? '. Choisissez des photos ou une visite vidéo guidée.' : '. Choose photos or a guided video walkthrough.'; ?></p>
                <div class="tme-choice-grid">
                    <button class="tme-choice" type="button" data-tme-choose-photos>
                        <strong><?php echo $is_fr ? 'Téléverser des photos' : 'Upload photos'; ?></strong>
                        <span><?php echo $is_fr ? 'Ajoutez jusqu’à ' : 'Add up to '; ?><?php echo esc_html((string) $settings['max_photos']); ?><?php echo $is_fr ? ' photos des pièces, des meubles et de la voie d’accès.' : ' photos of the rooms, furniture and access route.'; ?></span>
                    </button>
                    <button class="tme-choice tme-choice--video" type="button" data-tme-choose-video>
                        <strong><?php echo $is_fr ? 'Commencer la visite vidéo' : 'Start video walkthrough'; ?></strong>
                        <span><?php echo $is_fr ? 'Filmez le trajet de stationnement, les entrées, les escaliers, les pièces, les meubles et les objets.' : 'Record the parking route, entrances, stairs, rooms, furniture and items.'; ?></span>
                    </button>
                </div>
            </section>

            <section class="tme-card" data-tme-photo-view <?php echo (!$session || $session->submission_type !== 'photos' || $completed) ? 'hidden' : ''; ?>>
                <div class="tme-step"><span>2</span><div><strong><?php echo $is_fr ? 'Visite en photos' : 'Photo walkthrough'; ?></strong><small><?php echo $is_fr ? 'Étape 2 de 2' : 'Step 2 of 2'; ?></small></div></div>
                <h2><?php echo $is_fr ? 'Ajoutez des photos de votre déménagement' : 'Add photos of your move'; ?></h2>
                <p><?php echo $is_fr ? 'Bonjour ' : 'Hello '; ?><strong data-tme-photo-client-name><?php echo $session ? esc_html($session->client_name) : ''; ?></strong><?php echo $is_fr ? '. Incluez les meubles, les pièces, les escaliers, les entrées et le trajet de stationnement du camion.' : '. Include furniture, rooms, stairs, entrances and the truck parking route.'; ?></p>
                <div class="tme-photo-actions">
                    <label class="tme-button tme-button--primary"><?php echo $is_fr ? 'Choisir des photos' : 'Choose photos'; ?><input type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,image/*" multiple data-tme-photo-files></label>
                    <label class="tme-button"><?php echo $is_fr ? 'Prendre une photo' : 'Take a photo'; ?><input type="file" accept="image/*" capture="environment" data-tme-photo-camera></label>
                    <span data-tme-photo-count>0 <?php echo $is_fr ? 'sur' : 'of'; ?> <?php echo esc_html((string) $settings['max_photos']); ?> <?php echo $is_fr ? 'photos' : 'photos'; ?></span>
                </div>
                <div class="tme-photo-grid" data-tme-photo-grid></div>
                <p class="tme-photo-empty" data-tme-photo-empty><?php echo $is_fr ? 'Aucune photo sélectionnée pour l’instant.' : 'No photos selected yet.'; ?></p>
                <div class="tme-progress" data-tme-photo-progress hidden><span data-tme-photo-progress-bar></span></div>
                <p class="tme-progress-text" data-tme-photo-progress-text hidden></p>
                <button class="tme-button tme-button--primary tme-button--wide" type="button" data-tme-submit-photos disabled><?php echo $is_fr ? 'Soumettre les photos' : 'Submit photos'; ?></button>
            </section>

            <section class="tme-card" data-tme-recorder-view <?php echo (!$session || $session->submission_type !== 'video' || $completed) ? 'hidden' : ''; ?>>
                <div class="tme-step"><span>2</span><div><strong><?php echo $is_fr ? 'Visite vidéo' : 'Video walkthrough'; ?></strong><small><?php echo $is_fr ? 'Étape 2 de 2' : 'Step 2 of 2'; ?></small></div></div>
                <h2><?php echo $is_fr ? 'Montrez-nous le trajet complet du déménagement' : 'Show us the full moving route'; ?></h2>
                <p><?php echo $is_fr ? 'Bonjour ' : 'Hello '; ?><strong data-tme-client-name><?php echo $session ? esc_html($session->client_name) : ''; ?></strong><?php echo $is_fr ? '. Déplacez-vous lentement et décrivez ce que vous voyez.' : '. Move slowly and describe what you see.'; ?></p>
                <ol class="tme-checklist">
                    <?php if ($is_fr) : ?>
                    <li>Commencez à l’extérieur : montrez où le camion peut se stationner et le trajet jusqu’à l’entrée.</li>
                    <li>Montrez les entrées, ascenseurs, escaliers, couloirs et coins étroits.</li>
                    <li>Visitez chaque pièce et nommez-la.</li>
                    <li>Indiquez les meubles qui déménagent et identifiez clairement tout ce qui reste sur place.</li>
                    <li>Montrez les boîtes, les objets non emballés, le sous-sol, le garage, l’espace d’entreposage et chaque étage.</li>
                    <?php else : ?>
                    <li>Start outside: show where the truck can park and the route to the entrance.</li>
                    <li>Show entrances, elevators, stairs, hallways and tight corners.</li>
                    <li>Visit every room and say the room name.</li>
                    <li>Point out furniture that is moving and clearly identify anything staying behind.</li>
                    <li>Show boxes, loose items, basement, garage, storage and every floor.</li>
                    <?php endif; ?>
                </ol>

                <div class="tme-permission" data-tme-permission>
                    <h3><?php echo $is_fr ? 'Prêt à filmer?' : 'Ready to record?'; ?></h3>
                    <p><?php echo $is_fr ? 'Votre navigateur vous demandera l’accès à la caméra et au microphone. Rien n’est téléversé avant que vous l’ayez visionné et cliqué sur Soumettre.' : 'Your browser will ask for camera and microphone access. Nothing is uploaded until you review it and press Submit.'; ?></p>
                    <button class="tme-button tme-button--primary" type="button" data-tme-enable><?php echo $is_fr ? 'Activer la caméra' : 'Enable camera'; ?></button>
                    <label class="tme-file-fallback"><?php echo $is_fr ? 'Ou choisissez une vidéo existante' : 'Or choose an existing video'; ?><input type="file" accept="video/mp4,video/webm,video/quicktime,video/*" capture="environment" data-tme-file></label>
                </div>

                <div class="tme-recorder" data-tme-recorder hidden>
                    <div class="tme-video-stage">
                        <video data-tme-video playsinline muted></video>
                        <span class="tme-rec-pill" data-tme-rec-pill hidden><i></i> REC <b data-tme-clock>0:00</b></span>
                        <button class="tme-camera-switch" type="button" data-tme-switch hidden><?php echo $is_fr ? 'Changer de caméra' : 'Switch camera'; ?></button>
                        <button class="tme-exit-fullscreen" type="button" data-tme-exit-fullscreen hidden aria-label="<?php echo esc_attr($is_fr ? 'Quitter l’enregistrement plein écran' : 'Exit full-screen recording'); ?>"><?php echo $is_fr ? 'Quitter le plein écran' : 'Exit full screen'; ?></button>
                    </div>
                    <div class="tme-controls">
                        <p data-tme-help><?php echo $is_fr ? 'Tenez votre téléphone à l’horizontale lorsque possible.' : 'Hold your phone horizontally when possible.'; ?></p>
                        <div class="tme-button-row">
                            <button class="tme-button tme-button--record" type="button" data-tme-start><?php echo $is_fr ? 'Commencer l’enregistrement' : 'Start recording'; ?></button>
                            <button class="tme-button tme-button--stop" type="button" data-tme-stop hidden><?php echo $is_fr ? 'Arrêter l’enregistrement' : 'Stop recording'; ?></button>
                            <button class="tme-button" type="button" data-tme-retake hidden><?php echo $is_fr ? 'Reprendre' : 'Retake'; ?></button>
                            <button class="tme-button tme-button--primary" type="button" data-tme-submit hidden><?php echo $is_fr ? 'Soumettre la vidéo' : 'Submit video'; ?></button>
                        </div>
                        <div class="tme-progress" data-tme-progress hidden><span data-tme-progress-bar></span></div>
                        <p class="tme-progress-text" data-tme-progress-text hidden></p>
                    </div>
                </div>
            </section>

            <section class="tme-card tme-thanks" data-tme-done-view <?php echo (!$session || !$completed || $session->submission_type === 'info') ? 'hidden' : ''; ?>>
                <span class="tme-checkmark">&#10003;</span>
                <h2><?php echo $is_fr ? 'Merci' : 'Thank you'; ?><?php echo $session ? ', ' . esc_html($session->client_name) : ''; ?>!</h2>
                <p data-tme-done-message><?php echo $is_fr ? 'Votre demande d’estimation de déménagement a été reçue. Déménagement Tom l’examinera et communiquera avec vous.' : 'Your moving-estimate request was received. Tom Moving will review it and follow up with you.'; ?></p>
            </section>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
