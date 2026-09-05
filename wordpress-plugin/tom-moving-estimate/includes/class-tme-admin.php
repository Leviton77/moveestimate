<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TME_Admin
{
    private const CAPABILITY = 'tme_manage_estimates';
    private const BRIDGE_MARKER = 'TOM_MOVING_ESTIMATE_BRIDGE';

    public static function init(): void
    {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_post_tme_save_session', array(__CLASS__, 'save_session'));
        add_action('admin_post_tme_load_synthetic_ai_report', array(__CLASS__, 'load_synthetic_ai_report'));
        add_action('admin_post_tme_view_lead_report', array(__CLASS__, 'view_lead_report'));
        add_action('admin_post_tme_email_lead_report', array(__CLASS__, 'email_lead_report'));
        if (class_exists('TME_AI_Export')) {
            add_action('admin_post_tme_download_ai_csv', array(__CLASS__, 'download_ai_csv'));
            add_action('admin_post_tme_download_ai_json', array(__CLASS__, 'download_ai_json'));
            add_action('admin_post_tme_print_ai_report', array(__CLASS__, 'print_ai_report'));
        }
        add_action('admin_post_tme_delete_video', array(__CLASS__, 'delete_video'));
        add_action('admin_post_tme_download_video', array(__CLASS__, 'download_video'));
        add_action('admin_post_tme_delete_photo', array(__CLASS__, 'delete_photo'));
        add_action('admin_post_tme_delete_photos', array(__CLASS__, 'delete_photos'));
        add_action('admin_post_tme_download_photo', array(__CLASS__, 'download_photo'));
        add_action('admin_post_tme_save_settings', array(__CLASS__, 'save_settings'));
        add_action('admin_post_tme_test_r2', array(__CLASS__, 'test_r2'));
        add_action('admin_post_tme_setup_page', array(__CLASS__, 'setup_page'));
    }

    public static function menu(): void
    {
        add_menu_page(
            __('Move Estimates', 'tom-moving-estimate'),
            __('Move Estimates', 'tom-moving-estimate'),
            self::CAPABILITY,
            'tme-estimates',
            array(__CLASS__, 'estimates_page'),
            'dashicons-video-alt3',
            26
        );
        add_submenu_page(
            'tme-estimates',
            __('Estimate Settings', 'tom-moving-estimate'),
            __('Settings', 'tom-moving-estimate'),
            'manage_options',
            'tme-settings',
            array(__CLASS__, 'settings_page')
        );
    }

    public static function assets(): void
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (!in_array($page, array('tme-estimates', 'tme-settings'), true)) {
            return;
        }
        wp_enqueue_style('tme-admin', TME_URL . 'assets/css/admin.css', array(), TME_VERSION);
        if ($page === 'tme-estimates' && !empty($_GET['session'])) {
            wp_enqueue_script('tme-admin', TME_URL . 'assets/js/admin.js', array(), TME_VERSION, true);
            wp_enqueue_script('tme-ai-report', TME_URL . 'assets/js/ai-report.js', array(), TME_VERSION, true);
        }
    }

    private static function require_capability(string $capability = self::CAPABILITY): void
    {
        if (!current_user_can($capability)) {
            wp_die(esc_html__('You do not have permission to manage moving estimates.', 'tom-moving-estimate'), '', array('response' => 403));
        }
    }

    private static function notice_url(string $page, string $message, string $type = 'success', array $extra = array()): string
    {
        return add_query_arg(array_merge(array(
            'page'       => $page,
            'tme_notice' => $message,
            'tme_type'   => $type,
        ), $extra), admin_url('admin.php'));
    }

    private static function render_notice(): void
    {
        if (empty($_GET['tme_notice'])) {
            return;
        }
        $message = sanitize_text_field(wp_unslash($_GET['tme_notice']));
        $type = (!empty($_GET['tme_type']) && $_GET['tme_type'] === 'error') ? 'error' : 'success';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    public static function estimates_page(): void
    {
        self::require_capability();
        $id = isset($_GET['session']) ? absint($_GET['session']) : 0;
        if ($id) {
            self::detail_page($id);
            return;
        }
        self::list_page();
    }

    private static function list_page(): void
    {
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $sessions = TME_DB::list(array('status' => $status, 'search' => $search));
        ?>
        <div class="wrap tme-admin">
            <div class="tme-admin-heading"><div><h1><?php esc_html_e('Move Estimates', 'tom-moving-estimate'); ?></h1><p><?php esc_html_e('Customer information, photo and video estimate requests.', 'tom-moving-estimate'); ?></p></div><div><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=tme-live')); ?>"><?php esc_html_e('New live walkthrough', 'tom-moving-estimate'); ?></a> <?php if (current_user_can('manage_options')) : ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tme-settings')); ?>"><?php esc_html_e('Settings', 'tom-moving-estimate'); ?></a><?php endif; ?></div></div>
            <?php self::render_notice(); ?>
            <?php if (!TME_Plugin::is_configured() && current_user_can('manage_options')) : ?><div class="notice notice-warning"><p><strong><?php esc_html_e('R2 is not connected.', 'tom-moving-estimate'); ?></strong> <a href="<?php echo esc_url(admin_url('admin.php?page=tme-settings')); ?>"><?php esc_html_e('Complete the storage settings.', 'tom-moving-estimate'); ?></a></p></div><?php endif; ?>

            <form method="get" class="tme-filters">
                <input type="hidden" name="page" value="tme-estimates">
                <select name="status"><option value=""><?php esc_html_e('All statuses', 'tom-moving-estimate'); ?></option><?php foreach (array('new' => 'New', 'reviewed' => 'Reviewed', 'quoted' => 'Quoted') as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($status, $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search name, email or address">
                <button class="button" type="submit"><?php esc_html_e('Filter', 'tom-moving-estimate'); ?></button>
            </form>

            <div class="tme-table-card">
                <table class="widefat striped tme-table">
                    <thead><tr><th><?php esc_html_e('Customer', 'tom-moving-estimate'); ?></th><th><?php esc_html_e('Move', 'tom-moving-estimate'); ?></th><th><?php esc_html_e('Status', 'tom-moving-estimate'); ?></th><th><?php esc_html_e('Submission', 'tom-moving-estimate'); ?></th><th><?php esc_html_e('Received', 'tom-moving-estimate'); ?></th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($sessions as $session) :
                        $review = admin_url('admin.php?page=tme-estimates&session=' . $session->id);
                        ?>
                        <tr>
                            <td><strong><a href="<?php echo esc_url($review); ?>"><?php echo esc_html($session->client_name); ?></a></strong><small><?php echo esc_html($session->email); ?><br><?php echo esc_html($session->phone); ?></small></td>
                            <td><strong><?php echo esc_html(wp_date('M j, Y', strtotime($session->move_date . ' 12:00:00'))); ?></strong><small><?php echo esc_html($session->estimated_size); ?><br><?php echo esc_html($session->current_address); ?></small></td>
                            <td><span class="tme-status tme-status--<?php echo esc_attr($session->status); ?>"><?php echo esc_html(ucfirst($session->status)); ?></span></td>
                            <td>
                                <span class="tme-method-badge"><?php echo esc_html(self::submission_label($session)); ?></span>
                                <?php echo wp_kses_post(self::media_status($session)); ?>
                            </td>
                            <td><?php echo esc_html(wp_date('M j, Y', strtotime($session->created_at . ' UTC'))); ?></td>
                            <td><a class="button button-small" href="<?php echo esc_url($review); ?>"><?php esc_html_e('Review', 'tom-moving-estimate'); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$sessions) : ?><tr><td colspan="6" class="tme-empty"><?php esc_html_e('No estimate requests found.', 'tom-moving-estimate'); ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    private static function media_status(object $session): string
    {
        if ($session->submission_type === 'info') {
            return '<span class="tme-media-state tme-media-state--info">Information only</span>';
        }
        if ($session->submission_type === 'photos') {
            $photos = array_filter(
                TME_DB::photos($session),
                static fn(array $photo): bool => empty($photo['deleted_at']) && !empty($photo['key'])
            );
            if ($session->media_deleted_at || ($session->submitted_at && !$photos)) {
                return '<span class="tme-video-state tme-video-state--deleted">Photos deleted</span>';
            }
            if (!$session->submitted_at) {
                return '<span class="tme-video-state">Waiting for photos</span>';
            }
            $days = max(0, (int) ceil((strtotime($session->media_expires_at . ' UTC') - time()) / DAY_IN_SECONDS));
            $class = $days <= 3 ? ' tme-video-state--warning' : ' tme-video-state--ready';
            $count = count($photos);
            return '<span class="tme-video-state' . $class . '">' . $count . ' photo' . ($count === 1 ? '' : 's') . '</span><small>Deletes in ' . $days . ' day' . ($days === 1 ? '' : 's') . '</small>';
        }
        return self::video_status($session);
    }

    private static function video_status(object $session): string
    {
        if ($session->video_deleted_at) {
            return '<span class="tme-video-state tme-video-state--deleted">Deleted</span>';
        }
        if (!$session->video_uploaded_at) {
            return '<span class="tme-video-state">Waiting</span>';
        }
        $days = max(0, (int) ceil((strtotime($session->video_expires_at . ' UTC') - time()) / DAY_IN_SECONDS));
        $class = $days <= 3 ? ' tme-video-state--warning' : ' tme-video-state--ready';
        return '<span class="tme-video-state' . $class . '">Ready</span><small>Deletes in ' . $days . ' day' . ($days === 1 ? '' : 's') . '</small>';
    }

    public static function submission_label(object $session): string
    {
        if ($session->submission_type === 'photos') {
            return __('Photos', 'tom-moving-estimate');
        }
        if ($session->submission_type === 'info') {
            return __('Information only', 'tom-moving-estimate');
        }
        if ($session->submission_type === 'live') {
            return __('Live walkthrough', 'tom-moving-estimate');
        }
        return __('Video', 'tom-moving-estimate');
    }

    private static function detail_page(int $id): void
    {
        $session = TME_DB::get($id);
        if (!$session) {
            wp_die(esc_html__('Estimate not found.', 'tom-moving-estimate'), '', array('response' => 404));
        }
        $active_video = $session->video_key && !$session->video_deleted_at;
        $r2 = TME_Plugin::is_configured() ? new TME_R2() : null;
        $video_url = ($active_video && $r2) ? $r2->view_url($session->video_key, 1800) : '';
        $photos = TME_DB::photos($session);
        $active_photos = array();
        foreach ($photos as $photo) {
            if (!empty($photo['deleted_at']) || empty($photo['key'])) {
                continue;
            }
            $photo['view_url'] = $r2 ? $r2->view_url((string) $photo['key'], 1800) : '';
            $active_photos[] = $photo;
        }
        $annotations = self::sanitize_annotations($session->annotations ?: '[]');
        $ai_report = TME_AI_Report::decode((string) ($session->ai_report_current ?? ''));
        $ai_statuses = array(
            'not_started' => __('Not started', 'tom-moving-estimate'),
            'queued'      => __('Queued', 'tom-moving-estimate'),
            'processing'  => __('Processing', 'tom-moving-estimate'),
            'needs_review'=> __('Needs review', 'tom-moving-estimate'),
            'approved'    => __('Approved', 'tom-moving-estimate'),
            'failed'      => __('Failed', 'tom-moving-estimate'),
        );
        $ai_status = sanitize_key((string) ($session->ai_status ?? 'not_started'));
        if (!isset($ai_statuses[$ai_status])) {
            $ai_status = 'not_started';
        }
        ?>
        <div class="wrap tme-admin tme-review">
            <a class="tme-back" href="<?php echo esc_url(admin_url('admin.php?page=tme-estimates')); ?>">← <?php esc_html_e('Back to estimates', 'tom-moving-estimate'); ?></a>
            <?php self::render_notice(); ?>
            <div class="tme-admin-heading"><div><h1><?php echo esc_html($session->client_name); ?></h1><p><?php echo esc_html($session->current_address); ?> → <?php echo esc_html($session->destination_address); ?></p></div><span class="tme-status tme-status--<?php echo esc_attr($session->status); ?>"><?php echo esc_html(ucfirst($session->status)); ?></span></div>

            <p class="tme-submission-summary"><strong><?php esc_html_e('Submission:', 'tom-moving-estimate'); ?></strong> <span class="tme-method-badge"><?php echo esc_html(self::submission_label($session)); ?></span> <a class="button" target="_blank" rel="noopener" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tme_view_lead_report&session_id=' . $session->id), 'tme_view_lead_report_' . $session->id)); ?>"><?php esc_html_e('Create lead report', 'tom-moving-estimate'); ?></a></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-tme-review-form>
                <input type="hidden" name="action" value="tme_save_session">
                <input type="hidden" name="session_id" value="<?php echo esc_attr($session->id); ?>">
                <?php wp_nonce_field('tme_save_session_' . $session->id); ?>
                <input type="hidden" name="annotations" value="<?php echo esc_attr(wp_json_encode($annotations)); ?>" data-tme-annotations>

                <div class="tme-review-grid">
                    <div class="tme-review-main">
                        <?php if ($session->submission_type === 'photos') : ?>
                        <section class="tme-panel tme-photo-panel">
                            <h2><?php esc_html_e('Customer photos', 'tom-moving-estimate'); ?></h2>
                            <?php if ($active_photos) : ?>
                                <div class="tme-admin-photo-grid">
                                <?php foreach ($active_photos as $index => $photo) :
                                    $photo_id = preg_replace('/[^a-f0-9]/', '', (string) ($photo['id'] ?? ''));
                                    $download_url = wp_nonce_url(admin_url('admin-post.php?action=tme_download_photo&session_id=' . $session->id . '&photo_id=' . $photo_id), 'tme_download_photo_' . $session->id . '_' . $photo_id);
                                    $delete_url = wp_nonce_url(admin_url('admin-post.php?action=tme_delete_photo&session_id=' . $session->id . '&photo_id=' . $photo_id), 'tme_delete_photo_' . $session->id . '_' . $photo_id);
                                    ?>
                                    <article class="tme-admin-photo">
                                        <?php if ($photo['view_url']) : ?><a href="<?php echo esc_url($photo['view_url']); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url($photo['view_url']); ?>" alt="<?php echo esc_attr(sprintf(__('Customer photo %d', 'tom-moving-estimate'), $index + 1)); ?>" loading="lazy"></a><?php endif; ?>
                                        <div><strong><?php echo esc_html((string) ($photo['name'] ?? sprintf('Photo %d', $index + 1))); ?></strong><small><?php echo esc_html(size_format((int) ($photo['size'] ?? 0))); ?></small></div>
                                        <div class="tme-photo-links"><a class="button button-small" href="<?php echo esc_url($download_url); ?>"><?php esc_html_e('Download', 'tom-moving-estimate'); ?></a><a class="button-link-delete" data-tme-delete data-tme-delete-label="photo" href="<?php echo esc_url($delete_url); ?>"><?php esc_html_e('Delete', 'tom-moving-estimate'); ?></a></div>
                                    </article>
                                <?php endforeach; ?>
                                </div>
                            <?php elseif ($session->media_deleted_at) : ?>
                                <div class="tme-video-empty"><strong><?php esc_html_e('Photos deleted', 'tom-moving-estimate'); ?></strong><p><?php echo esc_html(wp_date('M j, Y, g:i a', strtotime($session->media_deleted_at . ' UTC'))); ?></p></div>
                            <?php else : ?>
                                <div class="tme-video-empty"><strong><?php esc_html_e('Waiting for customer photos', 'tom-moving-estimate'); ?></strong></div>
                            <?php endif; ?>
                        </section>
                        <?php elseif ($session->submission_type === 'info') : ?>
                        <section class="tme-panel tme-video-panel">
                            <div class="tme-video-empty"><strong><?php esc_html_e('Information-only estimate', 'tom-moving-estimate'); ?></strong><p><?php esc_html_e('The customer chose not to attach photos or video.', 'tom-moving-estimate'); ?></p></div>
                        </section>
                        <?php else : ?>
                        <section class="tme-panel tme-video-panel">
                            <?php if ($video_url) : ?>
                                <div class="tme-admin-video" data-tme-video-wrap>
                                    <video src="<?php echo esc_url($video_url); ?>" controls playsinline preload="metadata" data-tme-video></video>
                                    <canvas data-tme-canvas aria-label="Video annotation area"></canvas>
                                </div>
                                <div class="tme-tools" data-tme-tools>
                                    <strong><?php esc_html_e('Tools', 'tom-moving-estimate'); ?></strong>
                                    <button class="button is-active" type="button" data-tool="laser">Laser</button>
                                    <button class="button" type="button" data-tool="draw">Draw</button>
                                    <button class="button" type="button" data-tool="note">Note</button>
                                    <span class="tme-draw-options" data-draw-options hidden>
                                        <?php foreach (array('#ef4444', '#2563eb', '#16a34a') as $color) : ?><button type="button" class="tme-color" style="--tme-color:<?php echo esc_attr($color); ?>" data-color="<?php echo esc_attr($color); ?>" aria-label="Choose <?php echo esc_attr($color); ?>"></button><?php endforeach; ?>
                                        <select data-line-size aria-label="Line thickness"><option value="2">Thin</option><option value="3" selected>Medium</option><option value="5">Thick</option></select>
                                    </span>
                                    <button class="button-link-delete tme-clear" type="button" data-tme-clear><?php esc_html_e('Clear annotations', 'tom-moving-estimate'); ?></button>
                                </div>
                                <p class="description tme-tool-help"><?php esc_html_e('Click or tap with the laser to drop a point at this moment in the video. Laser points, drawings and notes all appear at the matching video time; press Save changes to keep them.', 'tom-moving-estimate'); ?></p>
                                <div class="tme-annotation-list" data-tme-annotation-list></div>
                            <?php elseif ($session->video_deleted_at) : ?>
                                <div class="tme-video-empty"><strong><?php esc_html_e('Video deleted', 'tom-moving-estimate'); ?></strong><p><?php echo esc_html(wp_date('M j, Y, g:i a', strtotime($session->video_deleted_at . ' UTC'))); ?></p></div>
                            <?php else : ?>
                                <div class="tme-video-empty"><strong><?php esc_html_e('Waiting for the customer video', 'tom-moving-estimate'); ?></strong></div>
                            <?php endif; ?>
                        </section>
                        <?php endif; ?>

                        <section class="tme-panel tme-ai-panel">
                            <div class="tme-ai-heading">
                                <div>
                                    <span class="tme-ai-kicker"><?php esc_html_e('Phase 2A', 'tom-moving-estimate'); ?></span>
                                    <h2><?php esc_html_e('AI moving report', 'tom-moving-estimate'); ?></h2>
                                </div>
                                <span class="tme-ai-status tme-ai-status--<?php echo esc_attr($ai_status); ?>"><?php echo esc_html($ai_statuses[$ai_status]); ?></span>
                            </div>

                            <div class="tme-ai-safety">
                                <span class="dashicons dashicons-shield" aria-hidden="true"></span>
                                <p><strong><?php esc_html_e('AI connection is not enabled.', 'tom-moving-estimate'); ?></strong> <?php esc_html_e('This preparation release cannot send customer photos or video for analysis and cannot create AI usage charges.', 'tom-moving-estimate'); ?></p>
                            </div>

                            <?php if ($ai_report) : ?>
                                <input type="hidden" name="ai_report" value="<?php echo esc_attr(wp_json_encode($ai_report)); ?>" data-tme-ai-report>
                                <div class="tme-ai-pilot-note"><strong><?php esc_html_e('Synthetic pilot report.', 'tom-moving-estimate'); ?></strong> <?php esc_html_e('Edit it as a representative would edit a real report. No customer media was analyzed.', 'tom-moving-estimate'); ?></div>
                                <div class="tme-ai-editor" data-tme-ai-editor></div>
                                <div class="tme-ai-actions tme-ai-editor-actions">
                                    <button class="button" type="submit" name="ai_action" value="save"><?php esc_html_e('Save AI report', 'tom-moving-estimate'); ?></button>
                                    <button class="button button-primary" type="submit" name="ai_action" value="approve"><?php esc_html_e('Approve report', 'tom-moving-estimate'); ?></button>
                                    <?php if (class_exists('TME_AI_Export')) : ?>
                                        <a class="button" target="_blank" rel="noopener" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tme_print_ai_report&session_id=' . $session->id), 'tme_print_ai_report_' . $session->id)); ?>"><?php esc_html_e('Print / Save PDF', 'tom-moving-estimate'); ?></a>
                                        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tme_download_ai_csv&session_id=' . $session->id), 'tme_download_ai_csv_' . $session->id)); ?>"><?php esc_html_e('Download CSV', 'tom-moving-estimate'); ?></a>
                                        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tme_download_ai_json&session_id=' . $session->id), 'tme_download_ai_json_' . $session->id)); ?>"><?php esc_html_e('Download JSON', 'tom-moving-estimate'); ?></a>
                                        <span data-tme-ai-save-state><?php esc_html_e('Exports use the last saved version and remain internal to this estimate.', 'tom-moving-estimate'); ?></span>
                                    <?php else : ?>
                                        <span data-tme-ai-save-state><?php esc_html_e('Report exports are temporarily unavailable. Saving and approval still work.', 'tom-moving-estimate'); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php else : ?>
                                <div class="tme-ai-empty">
                                    <h3><?php esc_html_e('Report workspace ready', 'tom-moving-estimate'); ?></h3>
                                    <p><?php esc_html_e('The report will be reviewed and corrected by a representative before it can be approved.', 'tom-moving-estimate'); ?></p>
                                    <div class="tme-ai-sections" aria-label="<?php esc_attr_e('Planned AI report sections', 'tom-moving-estimate'); ?>">
                                        <div><span class="dashicons dashicons-list-view" aria-hidden="true"></span><strong><?php esc_html_e('Inventory by room', 'tom-moving-estimate'); ?></strong></div>
                                        <div><span class="dashicons dashicons-archive" aria-hidden="true"></span><strong><?php esc_html_e('Box estimates', 'tom-moving-estimate'); ?></strong></div>
                                        <div><span class="dashicons dashicons-admin-tools" aria-hidden="true"></span><strong><?php esc_html_e('Disassembly and reassembly', 'tom-moving-estimate'); ?></strong></div>
                                        <div><span class="dashicons dashicons-tag" aria-hidden="true"></span><strong><?php esc_html_e('Mattress bags by size', 'tom-moving-estimate'); ?></strong></div>
                                        <div><span class="dashicons dashicons-location-alt" aria-hidden="true"></span><strong><?php esc_html_e('Access and home layout', 'tom-moving-estimate'); ?></strong></div>
                                        <div><span class="dashicons dashicons-editor-help" aria-hidden="true"></span><strong><?php esc_html_e('Questions and uncertainty', 'tom-moving-estimate'); ?></strong></div>
                                    </div>
                                    <div class="tme-ai-actions">
                                        <?php if (TME_Plugin::is_staging() && current_user_can('manage_options')) : ?>
                                            <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tme_load_synthetic_ai_report&session_id=' . $session->id), 'tme_load_synthetic_ai_report_' . $session->id)); ?>"><?php esc_html_e('Load synthetic example', 'tom-moving-estimate'); ?></a>
                                            <span><?php esc_html_e('This uses the bundled test report only. It does not inspect the customer submission.', 'tom-moving-estimate'); ?></span>
                                        <?php else : ?>
                                            <button class="button button-primary" type="button" disabled aria-disabled="true"><?php esc_html_e('Run AI analysis', 'tom-moving-estimate'); ?></button>
                                            <span><?php esc_html_e('The AI connection remains disabled.', 'tom-moving-estimate'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="tme-panel"><h2><?php esc_html_e('Estimate notes', 'tom-moving-estimate'); ?></h2><textarea name="rep_notes" rows="10" placeholder="Internal notes for this estimate..."><?php echo esc_textarea($session->rep_notes); ?></textarea></section>
                    </div>

                    <aside class="tme-review-side">
                        <section class="tme-panel tme-details tme-details--edit">
                            <h2><?php esc_html_e('Client details', 'tom-moving-estimate'); ?></h2>
                            <p class="description"><?php esc_html_e('Editable — fill in or correct anything the customer didn’t give during a live call.', 'tom-moving-estimate'); ?></p>
                            <label><span><?php esc_html_e('Name', 'tom-moving-estimate'); ?></span>
                                <input type="text" name="client_name" maxlength="120" value="<?php echo esc_attr($session->client_name); ?>"></label>
                            <label><span><?php esc_html_e('Email', 'tom-moving-estimate'); ?></span>
                                <input type="email" name="email" maxlength="190" value="<?php echo esc_attr($session->email); ?>"></label>
                            <label><span><?php esc_html_e('Phone', 'tom-moving-estimate'); ?></span>
                                <input type="text" name="phone" maxlength="40" value="<?php echo esc_attr($session->phone); ?>"></label>
                            <label><span><?php esc_html_e('Move date', 'tom-moving-estimate'); ?></span>
                                <input type="date" name="move_date" value="<?php echo esc_attr($session->move_date); ?>"></label>
                            <label><span><?php esc_html_e('Home size', 'tom-moving-estimate'); ?></span>
                                <?php
                                $size_options = array('Studio', '1 bedroom', '2 bedrooms', '3 bedrooms', '4+ bedrooms', 'House', 'Storage unit');
                                if ($session->estimated_size !== '' && !in_array($session->estimated_size, $size_options, true)) {
                                    $size_options[] = $session->estimated_size;
                                }
                                ?>
                                <select name="estimated_size">
                                    <option value=""><?php esc_html_e('Not specified', 'tom-moving-estimate'); ?></option>
                                    <?php foreach ($size_options as $size) : ?>
                                        <option value="<?php echo esc_attr($size); ?>" <?php selected($session->estimated_size, $size); ?>><?php echo esc_html($size); ?></option>
                                    <?php endforeach; ?>
                                </select></label>
                            <label><span><?php esc_html_e('Current address', 'tom-moving-estimate'); ?></span>
                                <input type="text" name="current_address" maxlength="255" value="<?php echo esc_attr($session->current_address); ?>"></label>
                            <label><span><?php esc_html_e('Destination address', 'tom-moving-estimate'); ?></span>
                                <input type="text" name="destination_address" maxlength="255" value="<?php echo esc_attr($session->destination_address); ?>"></label>
                            <?php if ($session->special_items) : ?>
                                <p class="description"><strong><?php esc_html_e('From the customer:', 'tom-moving-estimate'); ?></strong> <?php echo nl2br(esc_html($session->special_items)); ?></p>
                            <?php endif; ?>
                        </section>

                        <section class="tme-panel"><label><strong><?php esc_html_e('Status', 'tom-moving-estimate'); ?></strong><select name="status"><?php foreach (array('new' => 'New', 'reviewed' => 'Reviewed', 'quoted' => 'Quoted') as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($session->status, $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label></section>

                        <?php if ($active_video) : ?>
                            <section class="tme-panel tme-retention-card"><h2><?php esc_html_e('Video retention', 'tom-moving-estimate'); ?></h2><p><?php echo wp_kses_post(self::video_status($session)); ?></p><p><strong><?php esc_html_e('Deletion date:', 'tom-moving-estimate'); ?></strong><br><?php echo esc_html(wp_date('F j, Y', strtotime(($session->media_expires_at ?: $session->video_expires_at) . ' UTC'))); ?></p><div class="tme-side-actions"><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tme_download_video&session_id=' . $session->id), 'tme_download_' . $session->id)); ?>"><?php esc_html_e('Download video', 'tom-moving-estimate'); ?></a><a class="button button-link-delete" data-tme-delete data-tme-delete-label="video" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tme_delete_video&session_id=' . $session->id), 'tme_delete_' . $session->id)); ?>"><?php esc_html_e('Delete now', 'tom-moving-estimate'); ?></a></div></section>
                        <?php elseif ($session->submission_type === 'photos' && $active_photos) : ?>
                            <section class="tme-panel tme-retention-card"><h2><?php esc_html_e('Photo retention', 'tom-moving-estimate'); ?></h2><p><?php echo wp_kses_post(self::media_status($session)); ?></p><p><strong><?php esc_html_e('Deletion date:', 'tom-moving-estimate'); ?></strong><br><?php echo esc_html(wp_date('F j, Y', strtotime($session->media_expires_at . ' UTC'))); ?></p><div class="tme-side-actions"><a class="button button-link-delete" data-tme-delete data-tme-delete-label="all photos" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tme_delete_photos&session_id=' . $session->id), 'tme_delete_photos_' . $session->id)); ?>"><?php esc_html_e('Delete all photos', 'tom-moving-estimate'); ?></a></div></section>
                        <?php endif; ?>

                        <button class="button button-primary button-hero tme-save" type="submit"><?php esc_html_e('Save changes', 'tom-moving-estimate'); ?></button>
                    </aside>
                </div>
            </form>
        </div>
        <?php
    }

    public static function save_session(): void
    {
        self::require_capability();
        $id = absint($_POST['session_id'] ?? 0);
        check_admin_referer('tme_save_session_' . $id);
        $session = TME_DB::get($id);
        if (!$session) {
            wp_die(esc_html__('Estimate not found.', 'tom-moving-estimate'), '', array('response' => 404));
        }
        $status = sanitize_key(wp_unslash($_POST['status'] ?? 'new'));
        if (!in_array($status, array('new', 'reviewed', 'quoted'), true)) {
            $status = 'new';
        }
        $notes = mb_substr(sanitize_textarea_field(wp_unslash($_POST['rep_notes'] ?? '')), 0, 20000);
        $annotations = self::sanitize_annotations(wp_unslash($_POST['annotations'] ?? '[]'));

        // Client details are editable so a rep can fill in or correct anything
        // a live call left blank -- but never let a blank/malformed submission
        // wipe out a NOT NULL column; fall back to the existing value instead.
        $client_name = mb_substr(sanitize_text_field(wp_unslash($_POST['client_name'] ?? '')), 0, 120);
        $move_date = sanitize_text_field(wp_unslash($_POST['move_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $move_date)) {
            $move_date = $session->move_date;
        }

        $data = array(
            'status'               => $status,
            'rep_notes'            => $notes,
            'annotations'          => wp_json_encode($annotations),
            'client_name'          => $client_name !== '' ? $client_name : $session->client_name,
            'email'                => mb_substr(sanitize_email(wp_unslash($_POST['email'] ?? '')), 0, 190),
            'phone'                => mb_substr(sanitize_text_field(wp_unslash($_POST['phone'] ?? '')), 0, 40),
            'move_date'            => $move_date,
            'estimated_size'       => mb_substr(sanitize_text_field(wp_unslash($_POST['estimated_size'] ?? '')), 0, 32),
            'current_address'      => mb_substr(sanitize_text_field(wp_unslash($_POST['current_address'] ?? '')), 0, 255),
            'destination_address'  => mb_substr(sanitize_text_field(wp_unslash($_POST['destination_address'] ?? '')), 0, 255),
        );
        $formats = array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');
        $message = __('Estimate saved.', 'tom-moving-estimate');

        if (isset($_POST['ai_report'])) {
            $report = TME_AI_Report::sanitize_json(wp_unslash((string) $_POST['ai_report']));
            if (!$report) {
                wp_safe_redirect(self::notice_url('tme-estimates', __('The AI report could not be saved because its data is invalid.', 'tom-moving-estimate'), 'error', array('session' => $id)));
                exit;
            }
            $previous = TME_AI_Report::decode((string) ($session->ai_report_current ?? ''));
            $ai_action = sanitize_key(wp_unslash($_POST['ai_action'] ?? ''));
            $prepared = TME_AI_Report::prepare_for_storage(
                $report,
                $previous,
                get_current_user_id(),
                $ai_action === 'approve',
                ($session->ai_status ?? '') === 'approved'
            );
            $stored_report = $prepared['report'];
            $review = is_array($stored_report['review'] ?? null) ? $stored_report['review'] : array();
            $analysis = is_array($stored_report['analysis'] ?? null) ? $stored_report['analysis'] : array();
            $data = array_merge($data, array(
                'ai_report_current' => wp_json_encode($stored_report),
                'ai_status'         => $prepared['status'],
                'ai_schema_version' => sanitize_text_field((string) ($stored_report['schema_version'] ?? '1.0')),
                'ai_model'          => sanitize_text_field((string) ($analysis['model'] ?? 'synthetic-example')),
                'ai_reviewed_at'    => $prepared['status'] === 'approved' ? self::json_time_to_mysql((string) ($review['reviewed_at'] ?? '')) : null,
                'ai_reviewed_by'    => $prepared['status'] === 'approved' ? absint($review['reviewed_by_user_id'] ?? 0) : null,
                'ai_error'          => '',
            ));
            array_push($formats, '%s', '%s', '%s', '%s', '%s', '%d', '%s');
            $message = $prepared['status'] === 'approved'
                ? __('AI moving report approved.', 'tom-moving-estimate')
                : __('Estimate and AI moving report saved.', 'tom-moving-estimate');
        }

        $saved = TME_DB::update($id, $data, $formats);
        wp_safe_redirect(self::notice_url(
            'tme-estimates',
            $saved ? $message : __('The estimate could not be saved.', 'tom-moving-estimate'),
            $saved ? 'success' : 'error',
            array('session' => $id)
        ));
        exit;
    }

    public static function load_synthetic_ai_report(): void
    {
        self::require_capability('manage_options');
        $id = absint($_GET['session_id'] ?? 0);
        check_admin_referer('tme_load_synthetic_ai_report_' . $id);
        if (!TME_Plugin::is_staging()) {
            wp_die(esc_html__('Synthetic reports can only be loaded on staging.', 'tom-moving-estimate'), '', array('response' => 403));
        }
        $session = TME_DB::get($id);
        if (!$session) {
            wp_die(esc_html__('Estimate not found.', 'tom-moving-estimate'), '', array('response' => 404));
        }
        if (TME_AI_Report::decode((string) ($session->ai_report_current ?? ''))) {
            wp_safe_redirect(self::notice_url('tme-estimates', __('This estimate already has an AI report.', 'tom-moving-estimate'), 'error', array('session' => $id)));
            exit;
        }

        $report = TME_AI_Report::synthetic_example($id);
        if (is_wp_error($report)) {
            wp_safe_redirect(self::notice_url('tme-estimates', $report->get_error_message(), 'error', array('session' => $id)));
            exit;
        }
        $encoded = wp_json_encode($report);
        $now = current_time('mysql', true);
        $saved = TME_DB::update($id, array(
            'ai_status'          => 'needs_review',
            'ai_report_original' => $encoded,
            'ai_report_current'  => $encoded,
            'ai_schema_version'  => sanitize_text_field((string) ($report['schema_version'] ?? '1.0')),
            'ai_model'           => 'synthetic-example',
            'ai_requested_at'    => $now,
            'ai_completed_at'    => $now,
            'ai_reviewed_at'     => null,
            'ai_reviewed_by'     => null,
            'ai_error'           => '',
        ), array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'));

        wp_safe_redirect(self::notice_url(
            'tme-estimates',
            $saved ? __('Synthetic AI report loaded. No customer media was analyzed.', 'tom-moving-estimate') : __('The synthetic report could not be saved.', 'tom-moving-estimate'),
            $saved ? 'success' : 'error',
            array('session' => $id)
        ));
        exit;
    }

    public static function download_ai_csv(): void
    {
        [$session, $report] = self::ai_export_context('tme_download_ai_csv');
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . TME_AI_Export::filename($session, 'csv') . '"');
        header('X-Content-Type-Options: nosniff');
        echo "\xEF\xBB\xBF";
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            wp_die(esc_html__('The CSV export could not be opened.', 'tom-moving-estimate'), '', array('response' => 500));
        }
        foreach (TME_AI_Export::csv_rows($session, $report) as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    public static function download_ai_json(): void
    {
        [$session, $report] = self::ai_export_context('tme_download_ai_json');
        $json = wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            wp_die(esc_html__('The JSON export could not be created.', 'tom-moving-estimate'), '', array('response' => 500));
        }
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . TME_AI_Export::filename($session, 'json') . '"');
        header('X-Content-Type-Options: nosniff');
        echo $json;
        exit;
    }

    public static function print_ai_report(): void
    {
        [$session, $report] = self::ai_export_context('tme_print_ai_report');
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_option('blog_charset', 'UTF-8'));
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('X-Content-Type-Options: nosniff');
        TME_AI_Export::render_print($session, $report);
        exit;
    }

    private static function ai_export_context(string $action): array
    {
        self::require_capability();
        if (!class_exists('TME_AI_Export')) {
            wp_die(esc_html__('Report exports are temporarily unavailable.', 'tom-moving-estimate'), '', array('response' => 503));
        }
        $id = absint($_GET['session_id'] ?? 0);
        check_admin_referer($action . '_' . $id);
        $session = TME_DB::get($id);
        if (!$session) {
            wp_die(esc_html__('Estimate not found.', 'tom-moving-estimate'), '', array('response' => 404));
        }
        $report = TME_AI_Report::decode((string) ($session->ai_report_current ?? ''));
        if (!$report) {
            wp_die(esc_html__('This estimate does not have a saved AI report to export.', 'tom-moving-estimate'), '', array('response' => 404));
        }
        return array($session, $report);
    }

    public static function view_lead_report(): void
    {
        self::require_capability();
        $id = absint($_GET['session_id'] ?? 0);
        check_admin_referer('tme_view_lead_report_' . $id);
        $session = TME_DB::get($id);
        if (!$session) {
            wp_die(esc_html__('Estimate not found.', 'tom-moving-estimate'), '', array('response' => 404));
        }
        $ai_report = TME_AI_Report::decode((string) ($session->ai_report_current ?? ''));
        $text = TME_Lead_Report::build_text($session, $ai_report);
        $recipients = TME_Lead_Report::default_recipients();
        $sent = !empty($_GET['tme_sent']);
        $mail_error = !empty($_GET['tme_mail_error']);

        nocache_headers();
        header('Content-Type: text/html; charset=' . get_option('blog_charset', 'UTF-8'));
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('X-Content-Type-Options: nosniff');
        ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html(sprintf(__('Lead report — %s', 'tom-moving-estimate'), (string) ($session->client_name !== '' ? $session->client_name : __('Customer', 'tom-moving-estimate')))); ?></title>
    <style>
        :root{--navy:#16324f;--blue:#1663a7;--ink:#16202a;--muted:#5d6975;--line:#d9e1e8;--soft:#f4f7fa;--good:#176b43;--bad:#a3261a}
        *{box-sizing:border-box}
        body{margin:0;background:#eef2f5;color:var(--ink);font:14px/1.45 Arial,Helvetica,sans-serif}
        main{max-width:820px;margin:30px auto;background:#fff;padding:38px 44px;box-shadow:0 8px 28px rgba(22,50,79,.12)}
        h1{color:var(--navy);font-size:24px;margin:0 0 4px}
        .muted{color:var(--muted)}
        .toolbar{max-width:820px;margin:20px auto 0;display:flex;justify-content:flex-end;gap:10px}
        .btn{border:0;border-radius:5px;background:var(--blue);color:#fff;font-weight:700;padding:11px 18px;cursor:pointer;font-size:14px;text-decoration:none;display:inline-block}
        pre{white-space:pre-wrap;word-wrap:break-word;font:13px/1.55 ui-monospace,Consolas,monospace;background:var(--soft);border:1px solid var(--line);border-radius:7px;padding:18px 20px;margin-top:18px}
        .send-form{margin-top:22px;border-top:1px solid var(--line);padding-top:18px}
        .send-form label{display:block;font-weight:700;color:var(--navy);margin-bottom:6px}
        .send-form input[type=text]{width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:5px;font-size:14px;margin-bottom:12px}
        .notice-banner{border-radius:6px;padding:10px 14px;margin-top:16px;font-weight:600}
        .notice-banner.success{background:#e7f6ee;color:var(--good)}
        .notice-banner.error{background:#fdeceb;color:var(--bad)}
        @media print{.toolbar,.send-form,.notice-banner{display:none}main{max-width:none;margin:0;box-shadow:none}}
    </style>
</head>
<body>
    <div class="toolbar"><a class="btn" href="#" onclick="window.print();return false;"><?php esc_html_e('Print / Save as PDF', 'tom-moving-estimate'); ?></a></div>
    <main>
        <h1><?php esc_html_e('Lead report', 'tom-moving-estimate'); ?></h1>
        <p class="muted"><?php echo esc_html($session->client_name !== '' ? $session->client_name : __('Unnamed customer', 'tom-moving-estimate')); ?> — <?php esc_html_e('Estimate', 'tom-moving-estimate'); ?> #<?php echo esc_html((string) absint($session->id)); ?></p>

        <?php if ($sent) : ?><div class="notice-banner success"><?php esc_html_e('Report emailed.', 'tom-moving-estimate'); ?></div><?php endif; ?>
        <?php if ($mail_error) : ?><div class="notice-banner error"><?php esc_html_e('The email could not be sent. Check the recipient addresses and try again.', 'tom-moving-estimate'); ?></div><?php endif; ?>

        <pre><?php echo esc_html($text); ?></pre>

        <form class="send-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="tme_email_lead_report">
            <input type="hidden" name="session_id" value="<?php echo esc_attr($session->id); ?>">
            <?php wp_nonce_field('tme_email_lead_report_' . $session->id); ?>
            <label for="tme-lead-report-to"><?php esc_html_e('Send by email to', 'tom-moving-estimate'); ?></label>
            <input type="text" id="tme-lead-report-to" name="to" value="<?php echo esc_attr($recipients); ?>" placeholder="rep@example.com, rep2@example.com">
            <button class="btn" type="submit"><?php esc_html_e('Send by email', 'tom-moving-estimate'); ?></button>
        </form>
    </main>
</body>
</html>
        <?php
        exit;
    }

    public static function email_lead_report(): void
    {
        self::require_capability();
        $id = absint($_POST['session_id'] ?? 0);
        check_admin_referer('tme_email_lead_report_' . $id);
        $session = TME_DB::get($id);
        if (!$session) {
            wp_die(esc_html__('Estimate not found.', 'tom-moving-estimate'), '', array('response' => 404));
        }

        $to = self::parse_emails((string) wp_unslash($_POST['to'] ?? ''));
        if (!$to) {
            $to = self::parse_emails(TME_Lead_Report::default_recipients());
        }

        $view_url = wp_nonce_url(admin_url('admin-post.php?action=tme_view_lead_report&session_id=' . $id), 'tme_view_lead_report_' . $id);
        if (!$to) {
            wp_safe_redirect(add_query_arg('tme_mail_error', '1', $view_url));
            exit;
        }

        $ai_report = TME_AI_Report::decode((string) ($session->ai_report_current ?? ''));
        $text = TME_Lead_Report::build_text($session, $ai_report);
        $sent = wp_mail($to, TME_Lead_Report::subject($session), $text);

        wp_safe_redirect(add_query_arg($sent ? 'tme_sent' : 'tme_mail_error', '1', $view_url));
        exit;
    }

    private static function parse_emails(string $raw): array
    {
        $emails = array();
        foreach (preg_split('/[,;]+/', $raw) ?: array() as $candidate) {
            $candidate = trim(sanitize_email($candidate));
            if ($candidate !== '' && is_email($candidate)) {
                $emails[] = $candidate;
            }
        }
        return array_values(array_unique($emails));
    }

    private static function json_time_to_mysql(string $value): ?string
    {
        $timestamp = strtotime($value);
        return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : null;
    }

    private static function sanitize_annotations(string $json): array
    {
        $items = json_decode($json, true);
        if (!is_array($items)) {
            return array();
        }
        $clean = array();
        foreach (array_slice($items, 0, 500) as $item) {
            if (!is_array($item) || !in_array($item['type'] ?? '', array('draw', 'note', 'laser'), true)) {
                continue;
            }
            $base = array(
                'id'   => preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($item['id'] ?? '')) ?: wp_generate_uuid4(),
                'type' => $item['type'],
                'time' => min(86400, max(0, (float) ($item['time'] ?? 0))),
            );
            if ($item['type'] === 'note') {
                $base['x'] = min(100, max(0, (float) ($item['x'] ?? 0)));
                $base['y'] = min(100, max(0, (float) ($item['y'] ?? 0)));
                $base['text'] = mb_substr(sanitize_text_field((string) ($item['text'] ?? '')), 0, 200);
                if ($base['text'] !== '') {
                    $clean[] = $base;
                }
                continue;
            }
            if ($item['type'] === 'laser') {
                $base['x'] = min(100, max(0, (float) ($item['x'] ?? 0)));
                $base['y'] = min(100, max(0, (float) ($item['y'] ?? 0)));
                $clean[] = $base;
                continue;
            }
            $base['color'] = in_array($item['color'] ?? '', array('#ef4444', '#2563eb', '#16a34a'), true) ? $item['color'] : '#ef4444';
            $base['size'] = in_array((int) ($item['size'] ?? 3), array(2, 3, 5), true) ? (int) ($item['size'] ?? 3) : 3;
            $base['points'] = array();
            foreach (array_slice(is_array($item['points'] ?? null) ? $item['points'] : array(), 0, 1500) as $point) {
                if (is_array($point)) {
                    $base['points'][] = array('x' => min(100, max(0, (float) ($point['x'] ?? 0))), 'y' => min(100, max(0, (float) ($point['y'] ?? 0))));
                }
            }
            if (count($base['points']) > 1) {
                $clean[] = $base;
            }
        }
        return $clean;
    }

    public static function delete_video(): void
    {
        self::require_capability();
        $id = absint($_GET['session_id'] ?? 0);
        check_admin_referer('tme_delete_' . $id);
        $result = TME_Retention::delete_video($id, true);
        $error = is_wp_error($result);
        wp_safe_redirect(self::notice_url('tme-estimates', $error ? $result->get_error_message() : __('Video deleted from private storage.', 'tom-moving-estimate'), $error ? 'error' : 'success', array('session' => $id)));
        exit;
    }

    public static function download_video(): void
    {
        self::require_capability();
        $id = absint($_GET['session_id'] ?? 0);
        check_admin_referer('tme_download_' . $id);
        $session = TME_DB::get($id);
        if (!$session || !$session->video_key || $session->video_deleted_at) {
            wp_die(esc_html__('This video is no longer available.', 'tom-moving-estimate'), '', array('response' => 404));
        }
        TME_DB::update($id, array('last_downloaded_at' => current_time('mysql', true)), array('%s'));
        $extension = pathinfo($session->video_key, PATHINFO_EXTENSION) ?: 'mp4';
        $filename = sanitize_file_name($session->client_name . '-moving-estimate.' . $extension);
        wp_redirect((new TME_R2())->download_url($session->video_key, $filename));
        exit;
    }

    public static function delete_photo(): void
    {
        self::require_capability();
        $id = absint($_GET['session_id'] ?? 0);
        $photo_id = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['photo_id'] ?? ''));
        check_admin_referer('tme_delete_photo_' . $id . '_' . $photo_id);
        $result = TME_Retention::delete_photo($id, $photo_id);
        $error = is_wp_error($result);
        wp_safe_redirect(self::notice_url('tme-estimates', $error ? $result->get_error_message() : __('Photo deleted from private storage.', 'tom-moving-estimate'), $error ? 'error' : 'success', array('session' => $id)));
        exit;
    }

    public static function delete_photos(): void
    {
        self::require_capability();
        $id = absint($_GET['session_id'] ?? 0);
        check_admin_referer('tme_delete_photos_' . $id);
        $result = TME_Retention::delete_photos($id, true);
        $error = is_wp_error($result);
        wp_safe_redirect(self::notice_url('tme-estimates', $error ? $result->get_error_message() : __('All photos were deleted from private storage.', 'tom-moving-estimate'), $error ? 'error' : 'success', array('session' => $id)));
        exit;
    }

    public static function download_photo(): void
    {
        self::require_capability();
        $id = absint($_GET['session_id'] ?? 0);
        $photo_id = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['photo_id'] ?? ''));
        check_admin_referer('tme_download_photo_' . $id . '_' . $photo_id);
        $session = TME_DB::get($id);
        if (!$session || $session->submission_type !== 'photos') {
            wp_die(esc_html__('This photo is no longer available.', 'tom-moving-estimate'), '', array('response' => 404));
        }

        $photos = TME_DB::photos($session);
        $selected = null;
        foreach ($photos as &$photo) {
            if (($photo['id'] ?? '') !== $photo_id || !empty($photo['deleted_at']) || empty($photo['key'])) {
                continue;
            }
            $photo['last_downloaded_at'] = current_time('mysql', true);
            $selected = $photo;
            break;
        }
        unset($photo);
        if (!$selected) {
            wp_die(esc_html__('This photo is no longer available.', 'tom-moving-estimate'), '', array('response' => 404));
        }

        TME_DB::update($id, array('photos' => TME_DB::encode_photos($photos)), array('%s'));
        $extension = pathinfo((string) $selected['key'], PATHINFO_EXTENSION) ?: 'jpg';
        $original = sanitize_file_name((string) ($selected['name'] ?? ''));
        $filename = $original ?: sanitize_file_name($session->client_name . '-moving-estimate-photo.' . $extension);
        wp_redirect((new TME_R2())->download_url((string) $selected['key'], $filename));
        exit;
    }

    public static function settings_page(): void
    {
        self::require_capability('manage_options');
        $settings = TME_Plugin::settings();
        $has_access = TME_Secrets::decrypt((string) $settings['access_key_enc']) !== '';
        $has_secret = TME_Secrets::decrypt((string) $settings['secret_key_enc']) !== '';
        $estimate_page = get_page_by_path('estimate');
        $is_staging = TME_Plugin::is_staging();
        $page_ready = $estimate_page
            && has_shortcode((string) $estimate_page->post_content, 'tom_moving_estimate')
            && (bool) get_option('tme_estimate_route_connected', false)
            && ($is_staging || self::estimate_bridge_ready());
        $estimate_url = $is_staging
            ? add_query_arg('pagename', 'estimate', home_url('/'))
            : ($estimate_page ? get_permalink($estimate_page) : home_url('/estimate/'));
        $cors = wp_json_encode(array(array(
            'AllowedOrigins' => array_values(array_unique(array(
                untrailingslashit(home_url()),
                'https://1227937.us19.myftpupload.com',
                'https://tommoving.ca',
                'https://www.tommoving.ca',
            ))),
            'AllowedMethods' => array('PUT'),
            'AllowedHeaders' => array('Content-Type'),
            'ExposeHeaders'  => array('ETag'),
            'MaxAgeSeconds'  => 3600,
        )), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        ?>
        <div class="wrap tme-admin tme-settings"><h1><?php esc_html_e('Move Estimate Settings', 'tom-moving-estimate'); ?></h1><?php self::render_notice(); ?>
            <div class="tme-settings-grid">
                <section class="tme-panel"><h2>1. <?php esc_html_e('Private R2 storage', 'tom-moving-estimate'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tme_save_settings"><?php wp_nonce_field('tme_save_settings'); ?>
                        <label><span><?php esc_html_e('Cloudflare Account ID', 'tom-moving-estimate'); ?></span><input name="account_id" value="<?php echo esc_attr($settings['account_id']); ?>" maxlength="32" required></label>
                        <label><span><?php esc_html_e('R2 bucket name', 'tom-moving-estimate'); ?></span><input name="bucket_name" value="<?php echo esc_attr($settings['bucket_name']); ?>" required></label>
                        <label><span><?php esc_html_e('Access Key ID', 'tom-moving-estimate'); ?></span><input name="access_key" type="password" autocomplete="new-password" placeholder="<?php echo $has_access ? esc_attr__('Saved — leave blank to keep', 'tom-moving-estimate') : ''; ?>"></label>
                        <label><span><?php esc_html_e('Secret Access Key', 'tom-moving-estimate'); ?></span><input name="secret_key" type="password" autocomplete="new-password" placeholder="<?php echo $has_secret ? esc_attr__('Saved — leave blank to keep', 'tom-moving-estimate') : ''; ?>"></label>
                        <div class="tme-settings-row"><label><span><?php esc_html_e('Keep photos and videos (days)', 'tom-moving-estimate'); ?></span><input name="retention_days" type="number" min="7" max="90" value="<?php echo esc_attr($settings['retention_days']); ?>"></label><label><span><?php esc_html_e('Warn before deletion (days)', 'tom-moving-estimate'); ?></span><input name="warning_days" type="number" min="1" max="14" value="<?php echo esc_attr($settings['warning_days']); ?>"></label></div>
                        <label><span><?php esc_html_e('Reminder email', 'tom-moving-estimate'); ?></span><input name="notification_email" type="email" value="<?php echo esc_attr($settings['notification_email']); ?>" required></label>
                        <label><span><?php esc_html_e('Maximum video size (MB)', 'tom-moving-estimate'); ?></span><input name="max_video_mb" type="number" min="100" max="1000" value="<?php echo esc_attr($settings['max_video_mb']); ?>"></label>
                        <div class="tme-settings-row"><label><span><?php esc_html_e('Maximum photo size (MB)', 'tom-moving-estimate'); ?></span><input name="max_photo_mb" type="number" min="2" max="50" value="<?php echo esc_attr($settings['max_photo_mb']); ?>"></label><label><span><?php esc_html_e('Maximum photos per estimate', 'tom-moving-estimate'); ?></span><input name="max_photos" type="number" min="1" max="50" value="<?php echo esc_attr($settings['max_photos']); ?>"></label></div>
                        <p><button class="button button-primary" type="submit"><?php esc_html_e('Save storage settings', 'tom-moving-estimate'); ?></button></p>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="tme_test_r2"><?php wp_nonce_field('tme_test_r2'); ?><button class="button" type="submit"><?php esc_html_e('Test R2 connection', 'tom-moving-estimate'); ?></button></form>
                </section>

                <section class="tme-panel"><h2>2. <?php esc_html_e('Browser upload permission', 'tom-moving-estimate'); ?></h2><p><?php esc_html_e('In the selected R2 bucket, open Settings → CORS Policy and paste this policy. This lets the customer browser upload directly while the bucket remains private.', 'tom-moving-estimate'); ?></p><textarea class="tme-code" rows="14" readonly><?php echo esc_textarea($cors); ?></textarea><p class="description"><?php esc_html_e('Apply the policy to both staging and production buckets.', 'tom-moving-estimate'); ?></p></section>

                <section class="tme-panel"><h2>3. <?php esc_html_e('Estimate page', 'tom-moving-estimate'); ?></h2><?php if ($page_ready) : ?><p class="tme-setup-ok">✓ <?php echo esc_html($is_staging ? __('The staging estimate page is connected.', 'tom-moving-estimate') : __('The /estimate/ page is connected.', 'tom-moving-estimate')); ?></p><a class="button" href="<?php echo esc_url($estimate_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open estimate page', 'tom-moving-estimate'); ?></a><?php else : ?><p><?php esc_html_e('The plugin needs the shortcode [tom_moving_estimate] on the Estimate page.', 'tom-moving-estimate'); ?></p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="tme_setup_page"><?php wp_nonce_field('tme_setup_page'); ?><button class="button button-primary" type="submit"><?php esc_html_e('Connect the /estimate/ page', 'tom-moving-estimate'); ?></button></form><p class="description"><?php esc_html_e('The current page content is saved in page metadata before replacement.', 'tom-moving-estimate'); ?></p><?php endif; ?></section>

                <section class="tme-panel"><h2>4. <?php esc_html_e('Deletion backstop', 'tom-moving-estimate'); ?></h2><p><?php esc_html_e('WordPress sends the warning before deletion and removes submitted photos or video at the end of the retention period. The R2 lifecycle rule remains a safety backstop for every file under sessions/.', 'tom-moving-estimate'); ?></p><p><strong><?php esc_html_e('Prefix:', 'tom-moving-estimate'); ?></strong> <code>sessions/</code><br><strong><?php esc_html_e('Expire after:', 'tom-moving-estimate'); ?></strong> 33 days</p></section>
            </div>
        </div>
        <?php
    }

    public static function save_settings(): void
    {
        self::require_capability('manage_options');
        check_admin_referer('tme_save_settings');
        $old = TME_Plugin::settings();
        $account = strtolower(sanitize_text_field(wp_unslash($_POST['account_id'] ?? '')));
        $bucket = strtolower(sanitize_text_field(wp_unslash($_POST['bucket_name'] ?? '')));
        $email = sanitize_email(wp_unslash($_POST['notification_email'] ?? ''));
        if (!preg_match('/^[a-f0-9]{32}$/', $account) || !preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $bucket) || !$email) {
            wp_safe_redirect(self::notice_url('tme-settings', __('Check the Account ID, bucket name and email.', 'tom-moving-estimate'), 'error'));
            exit;
        }
        $retention = min(90, max(7, absint($_POST['retention_days'] ?? 30)));
        $warning = min(14, max(1, absint($_POST['warning_days'] ?? 3)));
        $warning = min($warning, $retention - 1);
        $access = trim(sanitize_text_field(wp_unslash($_POST['access_key'] ?? '')));
        $secret = trim(sanitize_text_field(wp_unslash($_POST['secret_key'] ?? '')));
        $settings = array(
            'account_id'         => $account,
            'bucket_name'        => $bucket,
            'access_key_enc'     => $access !== '' ? TME_Secrets::encrypt($access) : $old['access_key_enc'],
            'secret_key_enc'     => $secret !== '' ? TME_Secrets::encrypt($secret) : $old['secret_key_enc'],
            'retention_days'     => $retention,
            'warning_days'       => $warning,
            'notification_email' => $email,
            'max_video_mb'       => min(1000, max(100, absint($_POST['max_video_mb'] ?? 350))),
            'max_photo_mb'       => min(50, max(2, absint($_POST['max_photo_mb'] ?? 15))),
            'max_photos'         => min(50, max(1, absint($_POST['max_photos'] ?? 50))),
        );
        if (($access !== '' && !$settings['access_key_enc']) || ($secret !== '' && !$settings['secret_key_enc'])) {
            wp_safe_redirect(self::notice_url('tme-settings', __('This server could not encrypt the R2 keys. Contact the site administrator.', 'tom-moving-estimate'), 'error'));
            exit;
        }
        update_option(TME_Plugin::SETTINGS_OPTION, $settings, false);
        wp_safe_redirect(self::notice_url('tme-settings', __('Storage settings saved securely.', 'tom-moving-estimate')));
        exit;
    }

    public static function test_r2(): void
    {
        self::require_capability('manage_options');
        check_admin_referer('tme_test_r2');
        $result = (new TME_R2())->test_connection();
        $error = is_wp_error($result);
        wp_safe_redirect(self::notice_url('tme-settings', $error ? $result->get_error_message() : __('R2 connection successful. The private bucket is ready.', 'tom-moving-estimate'), $error ? 'error' : 'success'));
        exit;
    }

    public static function setup_page(): void
    {
        self::require_capability('manage_options');
        check_admin_referer('tme_setup_page');
        $page = get_page_by_path('estimate');
        if (!$page) {
            $id = wp_insert_post(array('post_title' => 'Moving Estimate', 'post_name' => 'estimate', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '[tom_moving_estimate]'), true);
        } else {
            if (!metadata_exists('post', $page->ID, '_tme_previous_content')) {
                update_post_meta($page->ID, '_tme_previous_content', $page->post_content);
            }
            $id = wp_update_post(array('ID' => $page->ID, 'post_content' => '[tom_moving_estimate]'), true);
        }
        $error = is_wp_error($id);
        if (!$error) {
            if (TME_Plugin::is_staging()) {
                update_option('tme_estimate_route_connected', true, false);
                flush_rewrite_rules(false);
            } else {
                require_once ABSPATH . 'wp-admin/includes/misc.php';
                $htaccess = ABSPATH . '.htaccess';
                $rules = array(
                    '<IfModule mod_rewrite.c>',
                    'RewriteEngine On',
                    'RewriteRule ^estimate(?:/.*)?$ index.php?pagename=estimate [L,QSA]',
                    '</IfModule>',
                );
                if (!is_writable($htaccess) || !insert_with_markers($htaccess, 'Tom Moving Estimate', $rules)) {
                    $error = new WP_Error('tme_htaccess', __('The page was created, but WordPress could not connect /estimate/. Check .htaccess write permissions.', 'tom-moving-estimate'));
                } else {
                    $bridge_result = self::ensure_estimate_bridge();
                    if (is_wp_error($bridge_result)) {
                        $error = $bridge_result;
                    } else {
                        update_option('tme_estimate_route_connected', true, false);
                        flush_rewrite_rules(false);
                    }
                }
            }
        }
        $success_message = TME_Plugin::is_staging()
            ? __('The staging estimate page is connected. Production will continue to use /estimate/.', 'tom-moving-estimate')
            : __('The /estimate/ page is connected. The previous static folder remains untouched as a rollback copy.', 'tom-moving-estimate');
        wp_safe_redirect(self::notice_url('tme-settings', $error ? $error->get_error_message() : $success_message, $error ? 'error' : 'success'));
        exit;
    }

    private static function estimate_bridge_ready(): bool
    {
        $directory = self::estimate_public_directory();
        if (!is_dir($directory)) {
            return false;
        }

        $path = trailingslashit($directory) . 'index.html';
        if (!is_readable($path)) {
            return false;
        }

        $contents = file_get_contents($path);
        return is_string($contents) && str_contains($contents, self::BRIDGE_MARKER);
    }

    private static function ensure_estimate_bridge()
    {
        $directory = self::estimate_public_directory();
        if (!is_dir($directory) && !wp_mkdir_p($directory)) {
            return new WP_Error('tme_bridge_directory', __('WordPress could not create the public /estimate/ directory.', 'tom-moving-estimate'));
        }

        $path = trailingslashit($directory) . 'index.html';
        if (file_exists($path)) {
            $contents = file_get_contents($path);
            if (is_string($contents) && str_contains($contents, self::BRIDGE_MARKER)) {
                return true;
            }
            $backup = trailingslashit($directory) . 'index.tme-static-backup.html';
            if (!file_exists($backup) && !copy($path, $backup)) {
                return new WP_Error('tme_bridge_backup', __('The existing /estimate/index.html file could not be backed up, so it was not changed.', 'tom-moving-estimate'));
            }
            chmod($backup, 0644);
        }

        $bridge = '<!doctype html>' . "\n"
            . '<!-- ' . self::BRIDGE_MARKER . ' -->' . "\n"
            . '<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Moving Estimate - Tom Moving</title></head><body><p>Loading secure estimate...</p>'
            . '<script>(function(){fetch("/?pagename=estimate",{credentials:"same-origin"}).then(function(response){'
            . 'if(!response.ok){throw new Error("Estimate page unavailable");}return response.text();}).then(function(html){'
            . 'document.open();document.write(html);document.close();}).catch(function(){document.body.innerHTML='
            . '"<p>Unable to load the estimate. <a href=\\"/?pagename=estimate\\">Open the estimate form</a>.</p>";});}());</script>'
            . '</body></html>' . "\n";
        chmod($directory, 0755);
        if (file_put_contents($path, $bridge, LOCK_EX) === false) {
            return new WP_Error('tme_bridge_write', __('WordPress could not create /estimate/index.html. Check the estimate folder permissions.', 'tom-moving-estimate'));
        }

        chmod($path, 0644);
        return true;
    }

    private static function estimate_public_directory(): string
    {
        $document_root = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
        $resolved_root = $document_root !== '' ? realpath($document_root) : false;
        $root = ($resolved_root && is_dir($resolved_root)) ? $resolved_root : ABSPATH;
        return trailingslashit($root) . 'estimate';
    }
}
