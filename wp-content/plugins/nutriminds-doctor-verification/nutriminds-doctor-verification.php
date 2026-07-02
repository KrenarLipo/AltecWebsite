<?php
/**
 * Plugin Name: NutriMinds Specialist Verification
 * Description: Frontend registration intake for NutriMinds gut health specialist verification.
 * Version: 0.4.0
 * Author: NutriMinds
 * Text Domain: nutriminds-doctor-verification
 */

if (!defined('ABSPATH')) {
    exit;
}

final class NutriMinds_Doctor_Verification {
    private const SHORTCODE = 'nutriminds_registration';
    private const VERSION = '0.4.0';
    private const DEFAULT_LANGUAGE = 'en';
    private const LANGUAGE_COOKIE = 'nutriminds_lang';
    private const POST_TYPE = 'nm_specialist_app';
    private const AJAX_ACTION = 'nutriminds_submit_application';
    private const NONCE_ACTION = 'nutriminds_registration';
    private const META_STATUS = '_nm_application_status';
    private const META_PREFIX = '_nm_application_';
    private const PLATFORM_OPTION = 'nutriminds_platform_settings';
    // Previous staging endpoint, kept for rollback: https://stage.ocelot.social/api
    //private const PLATFORM_DEFAULT_ENDPOINT = 'https://os.nutriminds.net.stage.ocelot-social.it4c.org/api';
    private const PLATFORM_DEFAULT_ENDPOINT = 'https://os.nutriminds.net/api';
    private const REST_NAMESPACE = 'nutriminds/v1';

    private array $translations = [];
    private ?string $current_language = null;
    private bool $language_switcher_inserted = false;

    public function __construct() {
        add_action('init', [$this, 'capture_language_choice']);
        add_action('init', [$this, 'register_application_post_type']);
        add_shortcode(self::SHORTCODE, [$this, 'render_registration_form']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handle_application_submission']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [$this, 'handle_application_submission']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_post_nutriminds_application_decision', [$this, 'handle_application_decision']);
        add_action('admin_post_nutriminds_save_platform_settings', [$this, 'handle_save_platform_settings']);
        add_action('admin_post_nutriminds_platform_retry', [$this, 'handle_platform_retry']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('phpmailer_init', [$this, 'configure_local_mailpit']);
        add_filter('wp_mail_from', [$this, 'filter_local_mailpit_from']);
        add_filter('wp_mail_from_name', [$this, 'filter_local_mailpit_from_name']);
        add_action('add_meta_boxes', [$this, 'register_application_meta_boxes']);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'filter_application_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_application_column'], 10, 2);
        add_filter('render_block', [$this, 'append_language_switcher_to_navigation'], 10, 2);
    }

    public function capture_language_choice(): void {
        $requested_language = isset($_GET['nm_lang']) ? sanitize_key((string) $_GET['nm_lang']) : '';

        if (!$this->is_supported_language($requested_language)) {
            return;
        }

        $this->current_language = $requested_language;
        setcookie(
            self::LANGUAGE_COOKIE,
            $requested_language,
            [
                'expires' => time() + YEAR_IN_SECONDS,
                'path' => COOKIEPATH ?: '/',
                'secure' => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]
        );
        $_COOKIE[self::LANGUAGE_COOKIE] = $requested_language;
    }

    public function register_assets(): void {
        $base_url = plugin_dir_url(__FILE__);

        wp_enqueue_style(
            'nutriminds-dosis-font',
            'https://fonts.googleapis.com/css2?family=Dosis:wght@400;500;600;700&display=swap',
            [],
            null
        );

        wp_enqueue_style(
            'nutriminds-registration',
            $base_url . 'assets/css/registration.css',
            ['nutriminds-dosis-font'],
            self::VERSION
        );

        wp_register_script(
            'nutriminds-registration',
            $base_url . 'assets/js/registration.js',
            [],
            self::VERSION,
            true
        );
    }

    public function render_registration_form(): string {
        wp_enqueue_script('nutriminds-registration');
        wp_localize_script(
            'nutriminds-registration',
            'NutriMindsRegistration',
            $this->get_client_config()
        );

        $nutriminds_registration_config = $this->get_client_config();

        ob_start();
        require plugin_dir_path(__FILE__) . 'templates/registration-form.php';
        return (string) ob_get_clean();
    }

    public function register_application_post_type(): void {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => 'Specialist Applications',
                'singular_name' => 'Specialist Application',
                'add_new_item' => 'Add Specialist Application',
                'edit_item' => 'Specialist Application Details',
                'view_item' => 'View Specialist Application',
                'search_items' => 'Search Specialist Applications',
                'not_found' => 'No specialist applications found',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_admin_bar' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'menu_icon' => 'dashicons-clipboard',
        ]);
    }

    public function handle_application_submission(): void {
        $language = isset($_POST['language']) ? sanitize_key((string) wp_unslash($_POST['language'])) : '';
        if ($this->is_supported_language($language)) {
            $this->current_language = $language;
        }

        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => $this->t('ajax.invalidRequest')], 403);
        }

        $first_name = $this->posted_text('first_name');
        $last_name = $this->posted_text('last_name');
        $email = sanitize_email((string) wp_unslash($_POST['email'] ?? ''));
        $phone = $this->posted_text('phone');
        $license_number = $this->posted_text('license_number');
        $terms = !empty($_POST['terms']);
        $platform_consent = !empty($_POST['platform_consent']);
        $selected_specialties = $this->posted_specialties();
        $primary_specialty = $this->posted_text('primary_specialty');

        if ($first_name === '' || $last_name === '' || !is_email($email) || $phone === '' || $license_number === '' || !$terms || !$platform_consent || $selected_specialties === [] || $primary_specialty === '') {
            wp_send_json_error(['message' => $this->t('ajax.requiredFields')], 400);
        }

        if (!$this->is_valid_phone($phone)) {
            wp_send_json_error(['message' => $this->t('ajax.phoneError')], 400);
        }

        $post_id = wp_insert_post([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => sprintf('%s %s - %s', $first_name, $last_name, $email),
            'post_content' => '',
        ], true);

        if (is_wp_error($post_id)) {
            wp_send_json_error(['message' => $this->t('ajax.storageError')], 500);
        }

        $license_attachment_id = $this->handle_application_upload('license_file', (int) $post_id);
        $credential_attachment_id = $this->handle_application_upload('diploma_file', (int) $post_id);

        if (is_wp_error($license_attachment_id) || is_wp_error($credential_attachment_id)) {
            wp_delete_post((int) $post_id, true);
            wp_send_json_error(['message' => $this->t('ajax.uploadError')], 400);
        }

        update_post_meta((int) $post_id, self::META_STATUS, 'pending');
        update_post_meta((int) $post_id, self::META_PREFIX . 'first_name', $first_name);
        update_post_meta((int) $post_id, self::META_PREFIX . 'last_name', $last_name);
        update_post_meta((int) $post_id, self::META_PREFIX . 'email', $email);
        update_post_meta((int) $post_id, self::META_PREFIX . 'phone', $phone);
        update_post_meta((int) $post_id, self::META_PREFIX . 'license_number', $license_number);
        update_post_meta((int) $post_id, self::META_PREFIX . 'language', $language ?: $this->get_current_language());
        update_post_meta((int) $post_id, self::META_PREFIX . 'specialties', $selected_specialties);
        update_post_meta((int) $post_id, self::META_PREFIX . 'primary_specialty', $primary_specialty);
        update_post_meta((int) $post_id, self::META_PREFIX . 'license_attachment_id', (int) $license_attachment_id);
        update_post_meta((int) $post_id, self::META_PREFIX . 'credential_attachment_id', (int) $credential_attachment_id);
        update_post_meta((int) $post_id, self::META_PREFIX . 'submitted_at', current_time('mysql'));
        update_post_meta((int) $post_id, self::META_PREFIX . 'terms_agreed', '1');
        update_post_meta((int) $post_id, self::META_PREFIX . 'platform_consent', '1');

        wp_send_json_success([
            'message' => $this->t('ajax.success'),
            'applicationId' => (int) $post_id,
        ]);
    }

    public function register_admin_menu(): void {
        add_menu_page(
            'NutriMinds Verification',
            'NutriMinds',
            'edit_posts',
            'nutriminds-verification',
            [$this, 'render_admin_applications_page'],
            'dashicons-clipboard',
            26
        );

        add_submenu_page(
            'nutriminds-verification',
            'Applications',
            'Applications',
            'edit_posts',
            'nutriminds-verification',
            [$this, 'render_admin_applications_page']
        );

        add_submenu_page(
            'nutriminds-verification',
            'Application Records',
            'Application Records',
            'edit_posts',
            'edit.php?post_type=' . self::POST_TYPE
        );

        add_submenu_page(
            'nutriminds-verification',
            'Settings',
            'Settings',
            'manage_options',
            'nutriminds-verification-settings',
            [$this, 'render_platform_settings_page']
        );
    }

    public function render_admin_applications_page(): void {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'nutriminds-doctor-verification'));
        }

        $status = isset($_GET['status']) ? sanitize_key((string) wp_unslash($_GET['status'])) : 'pending';
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $status = 'pending';
        }

        $applications = new WP_Query([
            'post_type' => self::POST_TYPE,
            'posts_per_page' => 50,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                [
                    'key' => self::META_STATUS,
                    'value' => $status,
                ],
            ],
        ]);

        echo '<div class="wrap nm-admin">';
        echo '<h1>NutriMinds Specialist Applications</h1>';
        $this->render_admin_notice();
        $this->render_status_tabs($status);

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>Applicant</th><th>Email</th><th>Selected professions</th><th>Documents</th><th>Submitted</th><th>Status</th><th>Platform</th><th>Actions</th>';
        echo '</tr></thead><tbody>';

        if ($applications->have_posts()) {
            while ($applications->have_posts()) {
                $applications->the_post();
                $this->render_admin_application_row((int) get_the_ID());
            }
            wp_reset_postdata();
        } else {
            echo '<tr><td colspan="8">No applications found for this status.</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public function handle_application_decision(): void {
        $post_id = isset($_POST['application_id']) ? absint($_POST['application_id']) : 0;
        $decision = isset($_POST['decision']) ? sanitize_key((string) wp_unslash($_POST['decision'])) : '';

        if (!$post_id || !current_user_can('edit_post', $post_id) || !in_array($decision, ['approved', 'rejected'], true)) {
            wp_die(esc_html__('Invalid application decision.', 'nutriminds-doctor-verification'));
        }

        check_admin_referer('nutriminds_application_decision_' . $post_id);

        update_post_meta($post_id, self::META_STATUS, $decision);
        update_post_meta($post_id, self::META_PREFIX . 'decided_at', current_time('mysql'));
        update_post_meta($post_id, self::META_PREFIX . 'decided_by', get_current_user_id());

        $platform_notice = '';
        if ($decision === 'approved') {
            $platform_notice = $this->sync_application_to_platform($post_id);
        }

        $email_notice = '';
        if ($decision === 'rejected') {
            $email_notice = $this->send_rejection_email($post_id) ? 'sent' : 'failed';
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'nutriminds-verification',
            'status' => $decision,
            'nm_notice' => $decision,
            'platform_notice' => $platform_notice,
            'email_notice' => $email_notice,
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_save_platform_settings(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to change these settings.', 'nutriminds-doctor-verification'));
        }

        check_admin_referer('nutriminds_platform_settings');

        $existing = $this->get_platform_settings();
        $endpoint = esc_url_raw((string) wp_unslash($_POST['platform_endpoint'] ?? ''));
        $token = sanitize_text_field((string) wp_unslash($_POST['platform_token'] ?? ''));
        $invite_code = sanitize_text_field((string) wp_unslash($_POST['platform_invite_code'] ?? ''));
        $inbound_token = sanitize_text_field((string) wp_unslash($_POST['inbound_token'] ?? ''));
        $mode = sanitize_key((string) wp_unslash($_POST['settings_mode'] ?? 'save'));
        $generated_inbound_token = $mode === 'generate_inbound_token' ? wp_generate_password(64, false, false) : '';

        $settings = [
            'enabled' => !empty($_POST['platform_enabled']) ? '1' : '0',
            'endpoint' => $endpoint !== '' ? $endpoint : self::PLATFORM_DEFAULT_ENDPOINT,
            'token' => $token !== '' ? $token : (string) ($existing['token'] ?? ''),
            'invite_code' => $invite_code,
            'inbound_enabled' => !empty($_POST['inbound_enabled']) ? '1' : '0',
            'inbound_token' => $generated_inbound_token !== '' ? $generated_inbound_token : ($inbound_token !== '' ? $inbound_token : (string) ($existing['inbound_token'] ?? '')),
            'mailpit_enabled' => !empty($_POST['mailpit_enabled']) ? '1' : '0',
        ];

        update_option(self::PLATFORM_OPTION, $settings, false);

        if ($mode === 'generate_inbound_token') {
            $this->set_platform_settings_notice('success', 'New inbound data endpoint token generated. Copy it now: ' . $generated_inbound_token);
        } elseif ($mode === 'test') {
            $result = $this->test_platform_connection($settings);
            $this->set_platform_settings_notice($result['ok'] ? 'success' : 'error', $result['message']);
        } else {
            $this->set_platform_settings_notice('success', 'Platform settings saved.');
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'nutriminds-verification-settings',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_platform_retry(): void {
        $post_id = isset($_POST['application_id']) ? absint($_POST['application_id']) : 0;

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('Invalid platform retry request.', 'nutriminds-doctor-verification'));
        }

        check_admin_referer('nutriminds_platform_retry_' . $post_id);

        $platform_notice = $this->sync_application_to_platform($post_id);

        wp_safe_redirect(add_query_arg([
            'page' => 'nutriminds-verification',
            'status' => $this->get_application_status($post_id),
            'platform_notice' => $platform_notice,
        ], admin_url('admin.php')));
        exit;
    }

    public function render_platform_settings_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'nutriminds-doctor-verification'));
        }

        $settings = $this->get_platform_settings();
        $token_source = $this->get_platform_token_source();
        $has_token = $this->get_platform_token($settings) !== '';

        echo '<div class="wrap nm-admin">';
        echo '<h1>NutriMinds Platform Settings</h1>';
        $this->render_platform_settings_notice();
        echo '<p>Use this staging integration to send approved specialist applications to the Ocelot Signup mutation.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="nutriminds_save_platform_settings">';
        wp_nonce_field('nutriminds_platform_settings');
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">Enable integration</th><td><label><input type="checkbox" name="platform_enabled" value="1" ' . checked($settings['enabled'], '1', false) . '> Send approved applications to staging</label></td></tr>';
        echo '<tr><th scope="row"><label for="platform_endpoint">GraphQL endpoint</label></th><td><input type="url" id="platform_endpoint" name="platform_endpoint" class="regular-text" value="' . esc_attr($settings['endpoint']) . '" required></td></tr>';
        echo '<tr><th scope="row"><label for="platform_token">API token</label></th><td>';
        echo '<input type="password" id="platform_token" name="platform_token" class="regular-text" value="" autocomplete="new-password" placeholder="' . esc_attr($has_token ? 'Leave blank to keep the saved token' : 'Paste the staging API token') . '">';
        echo '<p class="description">' . esc_html($has_token ? 'A token is configured from ' . $token_source . '. It is never displayed here.' : 'No token is configured yet.') . '</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="platform_invite_code">Invite code</label></th><td><input type="text" id="platform_invite_code" name="platform_invite_code" class="regular-text" value="' . esc_attr($settings['invite_code']) . '"><p class="description">Optional. Leave blank to send null.</p></td></tr>';
        echo '</tbody></table>';
        echo '<h2>Inbound data endpoint</h2>';
        echo '<p>Use this endpoint when Ocelot needs approved specialist data for prefill, badges, and verification.</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">Endpoint URL</th><td><code>' . esc_html(rest_url(self::REST_NAMESPACE . '/application')) . '</code><p class="description">POST JSON to this URL. Do not send the applicant email in the query string.</p></td></tr>';
        echo '<tr><th scope="row">Enable endpoint</th><td><label><input type="checkbox" name="inbound_enabled" value="1" ' . checked($settings['inbound_enabled'], '1', false) . '> Allow Ocelot to request approved specialist data</label></td></tr>';
        echo '<tr><th scope="row"><label for="inbound_token">Endpoint token</label></th><td>';
        echo '<input type="password" id="inbound_token" name="inbound_token" class="regular-text" value="" autocomplete="new-password" placeholder="' . esc_attr($settings['inbound_token'] !== '' ? 'Leave blank to keep the saved token' : 'Paste or generate a strong token') . '">';
        echo '<p class="description">' . esc_html($settings['inbound_token'] !== '' ? 'A token is configured. It is never displayed after saving.' : 'No inbound token is configured yet.') . '</p>';
        echo '</td></tr>';
        echo '</tbody></table>';
        echo '<h2>Local email testing</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">Mailpit</th><td><label><input type="checkbox" name="mailpit_enabled" value="1" ' . checked($settings['mailpit_enabled'], '1', false) . '> Route WordPress emails to local Mailpit</label><p class="description">SMTP: 127.0.0.1:1025. Inbox: <a href="http://localhost:8025/" target="_blank" rel="noopener noreferrer">http://localhost:8025/</a>. This is intended only for local development.</p></td></tr>';
        echo '</tbody></table>';
        echo '<p class="submit">';
        echo '<button type="submit" name="settings_mode" value="save" class="button button-primary">Save settings</button> ';
        echo '<button type="submit" name="settings_mode" value="test" class="button">Save and test connection</button> ';
        echo '<button type="submit" name="settings_mode" value="generate_inbound_token" class="button">Generate inbound token</button>';
        echo '</p>';
        echo '</form>';
        echo '</div>';
    }

    public function register_rest_routes(): void {
        register_rest_route(self::REST_NAMESPACE, '/application', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_application_data_request'],
            'permission_callback' => [$this, 'authorize_application_data_request'],
            'args' => [
                'email' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);
    }

    public function authorize_application_data_request(WP_REST_Request $request): true|WP_Error {
        $settings = $this->get_platform_settings();
        $configured_token = (string) ($settings['inbound_token'] ?? '');

        if (($settings['inbound_enabled'] ?? '0') !== '1' || $configured_token === '') {
            return new WP_Error('nutriminds_endpoint_disabled', 'The NutriMinds data endpoint is not available.', ['status' => 404]);
        }

        if ($this->is_rest_rate_limited($request)) {
            return new WP_Error('nutriminds_rate_limited', 'Too many requests. Please try again later.', ['status' => 429]);
        }

        $provided_token = $this->extract_rest_bearer_token($request);
        if ($provided_token === '' || !hash_equals($configured_token, $provided_token)) {
            return new WP_Error('nutriminds_unauthorized', 'Unauthorized.', ['status' => 401]);
        }

        return true;
    }

    public function handle_application_data_request(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $email = sanitize_email((string) $request->get_param('email'));
        if (!is_email($email)) {
            return new WP_Error('nutriminds_invalid_email', 'A valid email is required.', ['status' => 400]);
        }

        $post_id = $this->find_approved_application_by_email($email);
        if (!$post_id) {
            return new WP_Error('nutriminds_not_found', 'No approved specialist application was found for this email.', ['status' => 404]);
        }

        update_post_meta($post_id, self::META_PREFIX . 'data_endpoint_last_accessed_at', current_time('mysql'));

        return rest_ensure_response($this->build_application_data_response($post_id));
    }

    public function configure_local_mailpit($phpmailer): void {
        if (!$this->should_use_mailpit()) {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host = '127.0.0.1';
        $phpmailer->Port = 1025;
        $phpmailer->SMTPAuth = false;
        $phpmailer->SMTPSecure = '';
        $phpmailer->SMTPAutoTLS = false;
    }

    public function filter_local_mailpit_from(string $from): string {
        if (!$this->should_use_mailpit()) {
            return $from;
        }

        $admin_email = sanitize_email((string) get_option('admin_email'));

        return is_email($admin_email) ? $admin_email : 'no-reply@nutriminds.test';
    }

    public function filter_local_mailpit_from_name(string $name): string {
        return $this->should_use_mailpit() ? 'no-reply' : $name;
    }

    public function register_application_meta_boxes(): void {
        add_meta_box(
            'nutriminds_application_details',
            'Application Details',
            [$this, 'render_application_details_meta_box'],
            self::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'nutriminds_application_status',
            'Review Status',
            [$this, 'render_application_status_meta_box'],
            self::POST_TYPE,
            'side',
            'high'
        );
    }

    public function render_application_details_meta_box(WP_Post $post): void {
        $fields = $this->get_application_fields($post->ID);
        $specialties = $this->get_application_specialties($post->ID);

        echo '<table class="widefat striped"><tbody>';
        $this->render_detail_row('Name', trim($fields['first_name'] . ' ' . $fields['last_name']));
        $this->render_detail_row('Email', $fields['email']);
        $this->render_detail_row('Phone', $fields['phone']);
        $this->render_detail_row('License / registration number', $fields['license_number']);
        $this->render_detail_row('Language', strtoupper($fields['language']));
        $this->render_detail_row('Submitted', $fields['submitted_at']);
        echo '</tbody></table>';

        echo '<h3>Selected Professions</h3>';
        if ($specialties === []) {
            echo '<p>No professions stored.</p>';
        } else {
            echo '<ul>';
            foreach ($specialties as $specialty) {
                $primary = $specialty['id'] === $fields['primary_specialty'] ? ' <strong>(Primary)</strong>' : '';
                echo '<li>' . esc_html($specialty['name'] ?? '') . $primary . '<br><small>' . esc_html($specialty['category'] ?? '') . '</small></li>';
            }
            echo '</ul>';
        }

        echo '<h3>Documents</h3>';
        echo '<p>' . $this->document_link((int) $fields['license_attachment_id'], 'Professional license / registration') . '</p>';
        echo '<p>' . $this->document_link((int) $fields['credential_attachment_id'], 'Diploma / credential') . '</p>';
    }

    public function render_application_status_meta_box(WP_Post $post): void {
        $status = $this->get_application_status($post->ID);
        echo '<p><strong>Status:</strong> ' . esc_html(ucfirst($status)) . '</p>';
        $decided_at = (string) get_post_meta($post->ID, self::META_PREFIX . 'decided_at', true);
        if ($decided_at !== '') {
            echo '<p><strong>Decided at:</strong><br>' . esc_html($decided_at) . '</p>';
        }
        $rejection_email_sent_at = (string) get_post_meta($post->ID, self::META_PREFIX . 'rejection_email_sent_at', true);
        $rejection_email_error = (string) get_post_meta($post->ID, self::META_PREFIX . 'rejection_email_error', true);
        if ($rejection_email_sent_at !== '') {
            echo '<p><strong>Rejection email:</strong><br>Sent at ' . esc_html($rejection_email_sent_at) . '</p>';
        } elseif ($rejection_email_error !== '') {
            echo '<p><strong>Rejection email:</strong><br>' . esc_html($rejection_email_error) . '</p>';
        }
        echo '<hr>';
        echo '<p><strong>Platform:</strong><br>' . esc_html($this->format_platform_status($post->ID)) . '</p>';
        $platform_synced_at = (string) get_post_meta($post->ID, self::META_PREFIX . 'platform_synced_at', true);
        if ($platform_synced_at !== '') {
            echo '<p><strong>Last sync:</strong><br>' . esc_html($platform_synced_at) . '</p>';
        }
        $platform_error = (string) get_post_meta($post->ID, self::META_PREFIX . 'platform_error', true);
        if ($platform_error !== '') {
            echo '<p><strong>Last error:</strong><br>' . esc_html($platform_error) . '</p>';
        }
    }

    public function filter_application_columns(array $columns): array {
        return [
            'cb' => $columns['cb'] ?? '',
            'title' => 'Application',
            'nm_status' => 'Status',
            'nm_email' => 'Email',
            'nm_professions' => 'Professions',
            'nm_platform' => 'Platform',
            'date' => $columns['date'] ?? 'Date',
        ];
    }

    public function render_application_column(string $column, int $post_id): void {
        if ($column === 'nm_status') {
            echo esc_html(ucfirst($this->get_application_status($post_id)));
            return;
        }

        if ($column === 'nm_email') {
            echo esc_html((string) get_post_meta($post_id, self::META_PREFIX . 'email', true));
            return;
        }

        if ($column === 'nm_professions') {
            echo esc_html($this->format_specialty_summary($post_id));
            return;
        }

        if ($column === 'nm_platform') {
            echo esc_html($this->format_platform_status($post_id));
        }
    }

    public function append_language_switcher_to_navigation(string $block_content, array $block): string {
        if ($this->language_switcher_inserted || ($block['blockName'] ?? '') !== 'core/navigation') {
            return $block_content;
        }

        $this->language_switcher_inserted = true;

        return preg_replace(
            '/<\/nav>$/',
            $this->render_language_switcher() . '</nav>',
            $block_content,
            1
        ) ?: $block_content;
    }

    public function t(string $key): string {
        $translations = $this->get_translations($this->get_current_language());

        return $translations[$key] ?? $this->get_translations(self::DEFAULT_LANGUAGE)[$key] ?? $key;
    }

    public function get_current_language(): string {
        if ($this->current_language !== null) {
            return $this->current_language;
        }

        $cookie_language = isset($_COOKIE[self::LANGUAGE_COOKIE]) ? sanitize_key((string) $_COOKIE[self::LANGUAGE_COOKIE]) : '';
        if ($this->is_supported_language($cookie_language)) {
            $this->current_language = $cookie_language;
            return $this->current_language;
        }

        $locale = determine_locale();
        $this->current_language = str_starts_with($locale, 'de') ? 'de' : self::DEFAULT_LANGUAGE;

        return $this->current_language;
    }

    private function render_language_switcher(): string {
        $current_language = $this->get_current_language();
        $languages = [
            'en' => ['flag' => '🇬🇧', 'label' => 'English'],
            'de' => ['flag' => '🇩🇪', 'label' => 'Deutsch'],
        ];

        $items = '';
        foreach ($languages as $language => $meta) {
            $items .= sprintf(
                '<a class="nm-language-switcher__link %s" href="%s" aria-label="%s" title="%s"><span aria-hidden="true">%s</span><span class="screen-reader-text">%s</span></a>',
                $current_language === $language ? 'is-active' : '',
                esc_url($this->get_language_url($language)),
                esc_attr($meta['label']),
                esc_attr($meta['label']),
                esc_html($meta['flag']),
                esc_html($meta['label'])
            );
        }

        return '<div class="nm-language-switcher" aria-label="Language switcher">' . $items . '</div>';
    }

    private function get_language_url(string $language): string {
        return add_query_arg('nm_lang', $language, home_url(add_query_arg([], $GLOBALS['wp']->request ?? '')));
    }

    private function get_client_config(): array {
        $language = $this->get_current_language();
        $translations = $this->get_translations($language);

        return [
            'language' => $language,
            'text' => $this->pick_translation_keys($translations, [
                'js.selected',
                'js.select',
                'js.primary',
                'js.primaryPrefix',
                'js.selectedTitle',
                'js.reviewTitle',
                'js.registrationLabel',
                'js.noRegistrationDocument',
                'js.noCredential',
                'js.frontendComplete',
                'js.noResults',
                'js.submitting',
                'js.submitError',
                'button.submit',
                'validation.required',
                'validation.email',
                'validation.phone',
                'validation.fileRequired',
                'validation.fileSize',
                'validation.fileType',
                'validation.specialtiesRequired',
                'validation.termsRequired',
            ]),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::AJAX_ACTION,
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'specialties' => $this->get_specialties($language),
        ];
    }

    private function pick_translation_keys(array $translations, array $keys): array {
        $picked = [];
        foreach ($keys as $key) {
            $picked[$key] = $translations[$key] ?? $key;
        }

        return $picked;
    }

    private function get_specialties(string $language): array {
        $translations = $this->get_translations($language);
        $fallback = $this->get_translations(self::DEFAULT_LANGUAGE);
        $specialties = [];

        $groups = $translations['specialtyGroups'] ?? $fallback['specialtyGroups'] ?? [];
        if (is_array($groups) && $groups !== []) {
            foreach ($groups as $group) {
                if (!is_array($group)) {
                    continue;
                }

                $category = isset($group['category']) ? (string) $group['category'] : '';
                $group_description = isset($group['description']) ? (string) $group['description'] : '';
                $items = $group['items'] ?? [];

                if (!is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    if (is_string($item)) {
                        $item = [
                            'id' => sanitize_title($item),
                            'name' => $item,
                        ];
                    }

                    if (!is_array($item) || empty($item['name'])) {
                        continue;
                    }

                    $id = isset($item['id']) && $item['id'] !== '' ? (string) $item['id'] : sanitize_title((string) $item['name']);
                    $tags = $item['tags'] ?? [];

                    $specialties[] = [
                        'id' => $id,
                        'name' => (string) $item['name'],
                        'category' => isset($item['category']) ? (string) $item['category'] : $category,
                        'groupDescription' => $group_description,
                        'description' => isset($item['description']) ? (string) $item['description'] : '',
                        'tags' => is_array($tags) ? array_values(array_map('strval', $tags)) : [],
                    ];
                }
            }

            return $specialties;
        }

        foreach (range(1, 8) as $index) {
            $id = $fallback["specialty.$index.id"] ?? '';
            if ($id === '') {
                continue;
            }

            $specialties[] = [
                'id' => $id,
                'name' => $translations["specialty.$index.name"] ?? $fallback["specialty.$index.name"] ?? $id,
                'category' => $translations["specialty.$index.category"] ?? $fallback["specialty.$index.category"] ?? '',
            ];
        }

        return $specialties;
    }

    private function get_translations(string $language): array {
        if (isset($this->translations[$language])) {
            return $this->translations[$language];
        }

        $path = plugin_dir_path(__FILE__) . 'languages/' . $language . '.json';
        if (!is_readable($path)) {
            $path = plugin_dir_path(__FILE__) . 'languages/' . self::DEFAULT_LANGUAGE . '.json';
        }

        $contents = is_readable($path) ? file_get_contents($path) : '{}';
        $decoded = json_decode((string) $contents, true);
        $this->translations[$language] = is_array($decoded) ? $decoded : [];

        return $this->translations[$language];
    }

    private function t_for_language(string $key, string $language): string {
        $language = $this->is_supported_language($language) ? $language : self::DEFAULT_LANGUAGE;
        $translations = $this->get_translations($language);

        return $translations[$key] ?? $this->get_translations(self::DEFAULT_LANGUAGE)[$key] ?? $key;
    }

    private function is_supported_language(string $language): bool {
        return in_array($language, ['en', 'de'], true);
    }

    private function posted_text(string $key): string {
        return sanitize_text_field((string) wp_unslash($_POST[$key] ?? ''));
    }

    private function posted_specialties(): array {
        $raw = (string) wp_unslash($_POST['selected_specialties'] ?? '[]');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $specialties = [];
        foreach ($decoded as $item) {
            if (!is_array($item) || empty($item['id']) || empty($item['name'])) {
                continue;
            }

            $tags = $item['tags'] ?? [];
            $specialties[] = [
                'id' => sanitize_key((string) $item['id']),
                'name' => sanitize_text_field((string) $item['name']),
                'category' => sanitize_text_field((string) ($item['category'] ?? '')),
                'description' => sanitize_text_field((string) ($item['description'] ?? '')),
                'tags' => is_array($tags) ? array_values(array_map('sanitize_text_field', $tags)) : [],
            ];
        }

        return $specialties;
    }

    private function handle_application_upload(string $field, int $post_id): int|WP_Error {
        if (empty($_FILES[$field]) || !isset($_FILES[$field]['error']) || (int) $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('missing_file', 'Required file is missing.');
        }

        if ((int) ($_FILES[$field]['size'] ?? 0) > 10 * MB_IN_BYTES) {
            return new WP_Error('file_too_large', 'File is too large.');
        }

        $allowed_mimes = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
        ];
        $filetype = wp_check_filetype((string) ($_FILES[$field]['name'] ?? ''), $allowed_mimes);
        if (empty($filetype['ext']) || empty($filetype['type'])) {
            return new WP_Error('invalid_file_type', 'File type is not allowed.');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_handle_upload($field, $post_id);

        return is_wp_error($attachment_id) ? $attachment_id : (int) $attachment_id;
    }

    private function send_rejection_email(int $post_id): bool {
        $fields = $this->get_application_fields($post_id);
        $recipient = sanitize_email($fields['email']);

        if (!is_email($recipient)) {
            update_post_meta($post_id, self::META_PREFIX . 'rejection_email_error', 'Missing or invalid recipient email.');
            return false;
        }

        $language = $this->is_supported_language($fields['language']) ? $fields['language'] : self::DEFAULT_LANGUAGE;
        $site_name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $name = trim($fields['first_name'] . ' ' . $fields['last_name']);
        $replacements = [
            '{name}' => $name !== '' ? $name : $this->t_for_language('email.rejection.defaultName', $language),
            '{site}' => $site_name !== '' ? $site_name : 'NutriMinds',
        ];
        $subject = strtr($this->t_for_language('email.rejection.subject', $language), $replacements);
        $body = strtr($this->t_for_language('email.rejection.body', $language), $replacements);
        $admin_email = sanitize_email((string) get_option('admin_email'));
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
        ];

        if (is_email($admin_email)) {
            $headers[] = 'From: no-reply <' . $admin_email . '>';
        }

        $sent = wp_mail($recipient, $subject, $body, $headers);

        if ($sent) {
            update_post_meta($post_id, self::META_PREFIX . 'rejection_email_sent_at', current_time('mysql'));
            update_post_meta($post_id, self::META_PREFIX . 'rejection_email_error', '');
        } else {
            update_post_meta($post_id, self::META_PREFIX . 'rejection_email_error', 'WordPress could not send the rejection email.');
        }

        return $sent;
    }

    private function render_admin_notice(): void {
        $notice = isset($_GET['nm_notice']) ? sanitize_key((string) wp_unslash($_GET['nm_notice'])) : '';
        $platform_notice = isset($_GET['platform_notice']) ? sanitize_key((string) wp_unslash($_GET['platform_notice'])) : '';
        $email_notice = isset($_GET['email_notice']) ? sanitize_key((string) wp_unslash($_GET['email_notice'])) : '';

        if ($notice === 'approved') {
            echo '<div class="notice notice-success is-dismissible"><p>Application approved. It is now available in Application Records.</p></div>';
        }
        if ($notice === 'rejected') {
            echo '<div class="notice notice-warning is-dismissible"><p>Application rejected.</p></div>';
        }
        if ($email_notice === 'sent') {
            echo '<div class="notice notice-success is-dismissible"><p>The applicant was notified by email.</p></div>';
        }
        if ($email_notice === 'failed') {
            echo '<div class="notice notice-error is-dismissible"><p>The application was rejected, but WordPress could not send the notification email. Check the application details or mail configuration.</p></div>';
        }
        if ($platform_notice === 'synced') {
            echo '<div class="notice notice-success is-dismissible"><p>Platform Signup was sent successfully to staging.</p></div>';
        }
        if ($platform_notice === 'not_configured') {
            echo '<div class="notice notice-info is-dismissible"><p>Application approved locally. Platform integration is not enabled or the token is missing.</p></div>';
        }
        if ($platform_notice === 'failed') {
            echo '<div class="notice notice-error is-dismissible"><p>Application approved locally, but the platform Signup failed. Open the application details for the saved error and retry when ready.</p></div>';
        }
    }

    private function render_status_tabs(string $current): void {
        echo '<h2 class="nav-tab-wrapper">';
        foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $status => $label) {
            $class = $current === $status ? ' nav-tab-active' : '';
            $url = add_query_arg([
                'page' => 'nutriminds-verification',
                'status' => $status,
            ], admin_url('admin.php'));
            echo '<a class="nav-tab' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label . ' (' . $this->application_count($status) . ')') . '</a>';
        }
        echo '</h2>';
    }

    private function render_admin_application_row(int $post_id): void {
        $fields = $this->get_application_fields($post_id);
        $status = $this->get_application_status($post_id);
        $name = trim($fields['first_name'] . ' ' . $fields['last_name']);

        echo '<tr>';
        echo '<td><strong><a href="' . esc_url(get_edit_post_link($post_id, '')) . '">' . esc_html($name ?: get_the_title($post_id)) . '</a></strong></td>';
        echo '<td><a href="mailto:' . esc_attr($fields['email']) . '">' . esc_html($fields['email']) . '</a></td>';
        echo '<td>' . esc_html($this->format_specialty_summary($post_id)) . '</td>';
        echo '<td>' . $this->document_link((int) $fields['license_attachment_id'], 'License') . '<br>' . $this->document_link((int) $fields['credential_attachment_id'], 'Credential') . '</td>';
        echo '<td>' . esc_html($fields['submitted_at']) . '</td>';
        echo '<td>' . esc_html(ucfirst($status)) . '</td>';
        echo '<td>' . esc_html($this->format_platform_status($post_id)) . '</td>';
        echo '<td>';
        if ($status === 'pending') {
            $this->render_decision_form($post_id, 'approved', 'Approve', 'button-primary');
            $this->render_decision_form($post_id, 'rejected', 'Reject', 'button-secondary');
        } else {
            echo '<a class="button" href="' . esc_url(get_edit_post_link($post_id, '')) . '">View details</a>';
            if ($status === 'approved') {
                echo ' ';
                $this->render_platform_retry_form($post_id);
            }
        }
        echo '</td>';
        echo '</tr>';
    }

    private function render_decision_form(int $post_id, string $decision, string $label, string $button_class): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 6px 6px 0;">';
        echo '<input type="hidden" name="action" value="nutriminds_application_decision">';
        echo '<input type="hidden" name="application_id" value="' . esc_attr((string) $post_id) . '">';
        echo '<input type="hidden" name="decision" value="' . esc_attr($decision) . '">';
        wp_nonce_field('nutriminds_application_decision_' . $post_id);
        echo '<button type="submit" class="button ' . esc_attr($button_class) . '">' . esc_html($label) . '</button>';
        echo '</form>';
    }

    private function render_platform_retry_form(int $post_id): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 0 6px 0;">';
        echo '<input type="hidden" name="action" value="nutriminds_platform_retry">';
        echo '<input type="hidden" name="application_id" value="' . esc_attr((string) $post_id) . '">';
        wp_nonce_field('nutriminds_platform_retry_' . $post_id);
        echo '<button type="submit" class="button">Retry platform</button>';
        echo '</form>';
    }

    private function get_application_fields(int $post_id): array {
        $keys = [
            'first_name',
            'last_name',
            'email',
            'phone',
            'license_number',
            'language',
            'primary_specialty',
            'license_attachment_id',
            'credential_attachment_id',
            'submitted_at',
        ];
        $fields = [];
        foreach ($keys as $key) {
            $fields[$key] = (string) get_post_meta($post_id, self::META_PREFIX . $key, true);
        }

        return $fields;
    }

    private function get_application_specialties(int $post_id): array {
        $specialties = get_post_meta($post_id, self::META_PREFIX . 'specialties', true);

        return is_array($specialties) ? $specialties : [];
    }

    private function get_application_status(int $post_id): string {
        $status = (string) get_post_meta($post_id, self::META_STATUS, true);

        return in_array($status, ['pending', 'approved', 'rejected'], true) ? $status : 'pending';
    }

    private function get_platform_settings(): array {
        $stored = get_option(self::PLATFORM_OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        return [
            'enabled' => !empty($stored['enabled']) ? '1' : '0',
            'endpoint' => isset($stored['endpoint']) && is_string($stored['endpoint']) && $stored['endpoint'] !== '' ? $stored['endpoint'] : self::PLATFORM_DEFAULT_ENDPOINT,
            'token' => isset($stored['token']) && is_string($stored['token']) ? $stored['token'] : '',
            'invite_code' => isset($stored['invite_code']) && is_string($stored['invite_code']) ? $stored['invite_code'] : '',
            'inbound_enabled' => !empty($stored['inbound_enabled']) ? '1' : '0',
            'inbound_token' => isset($stored['inbound_token']) && is_string($stored['inbound_token']) ? $stored['inbound_token'] : '',
            'mailpit_enabled' => !empty($stored['mailpit_enabled']) ? '1' : '0',
        ];
    }

    private function should_use_mailpit(): bool {
        if (defined('NUTRIMINDS_USE_MAILPIT') && NUTRIMINDS_USE_MAILPIT) {
            return true;
        }

        $settings = $this->get_platform_settings();
        if (($settings['mailpit_enabled'] ?? '0') === '1') {
            return true;
        }

        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        return in_array($host, ['localhost', '127.0.0.1'], true);
    }

    private function find_approved_application_by_email(string $email): int {
        $query = new WP_Query([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => self::META_PREFIX . 'email',
                    'value' => $email,
                    'compare' => '=',
                ],
                [
                    'key' => self::META_STATUS,
                    'value' => 'approved',
                    'compare' => '=',
                ],
            ],
        ]);

        $ids = $query->posts;

        return is_array($ids) && isset($ids[0]) ? (int) $ids[0] : 0;
    }

    private function build_application_data_response(int $post_id): array {
        $fields = $this->get_application_fields($post_id);
        $specialties = array_values(array_filter($this->get_application_specialties($post_id), 'is_array'));
        $primary_specialty = $this->find_primary_specialty($specialties, $fields['primary_specialty']);
        $display_name = trim($fields['first_name'] . ' ' . $fields['last_name']);
        $approved_at = (string) get_post_meta($post_id, self::META_PREFIX . 'decided_at', true);

        return [
            'email' => $fields['email'],
            'firstName' => $fields['first_name'],
            'lastName' => $fields['last_name'],
            'displayName' => $display_name,
            'locale' => $this->normalize_platform_locale($fields['language']),
            'verification' => [
                'status' => 'approved',
                'source' => 'nutriminds',
                'approvedAt' => $approved_at,
                'externalReference' => 'nutriminds-wp-' . $post_id,
            ],
            'primarySpecialty' => $primary_specialty,
            'specialties' => array_values(array_map([$this, 'sanitize_specialty_for_response'], $specialties)),
        ];
    }

    private function find_primary_specialty(array $specialties, string $primary_specialty_id): ?array {
        foreach ($specialties as $specialty) {
            if (!is_array($specialty)) {
                continue;
            }

            if ((string) ($specialty['id'] ?? '') === $primary_specialty_id) {
                return $this->sanitize_specialty_for_response($specialty);
            }
        }

        return null;
    }

    private function sanitize_specialty_for_response(array $specialty): array {
        $tags = $specialty['tags'] ?? [];

        return [
            'id' => (string) ($specialty['id'] ?? ''),
            'name' => (string) ($specialty['name'] ?? ''),
            'category' => (string) ($specialty['category'] ?? ''),
            'tags' => is_array($tags) ? array_values(array_map('strval', $tags)) : [],
        ];
    }

    private function extract_rest_bearer_token(WP_REST_Request $request): string {
        $authorization = (string) $request->get_header('authorization');
        if ($authorization === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authorization = (string) wp_unslash($_SERVER['HTTP_AUTHORIZATION']);
        }
        if ($authorization === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authorization = (string) wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        }

        if (preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches)) {
            return trim((string) $matches[1]);
        }

        $fallback_header = (string) $request->get_header('x-nutriminds-token');

        return trim($fallback_header);
    }

    private function is_rest_rate_limited(WP_REST_Request $request): bool {
        $client_ip = $this->get_rest_client_ip();
        $email = sanitize_email((string) $request->get_param('email'));
        $key = 'nutriminds_rest_rate_' . md5($client_ip . '|' . strtolower($email));
        $count = (int) get_transient($key);

        if ($count >= 60) {
            return true;
        }

        set_transient($key, $count + 1, 15 * MINUTE_IN_SECONDS);

        return false;
    }

    private function get_rest_client_ip(): string {
        $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        return $remote_addr !== '' ? $remote_addr : 'unknown';
    }

    private function get_platform_token(array $settings): string {
        if (defined('NUTRIMINDS_PLATFORM_TOKEN') && is_string(NUTRIMINDS_PLATFORM_TOKEN) && NUTRIMINDS_PLATFORM_TOKEN !== '') {
            return NUTRIMINDS_PLATFORM_TOKEN;
        }

        return (string) ($settings['token'] ?? '');
    }

    private function get_platform_token_source(): string {
        if (defined('NUTRIMINDS_PLATFORM_TOKEN') && is_string(NUTRIMINDS_PLATFORM_TOKEN) && NUTRIMINDS_PLATFORM_TOKEN !== '') {
            return 'wp-config.php';
        }

        return 'WordPress settings';
    }

    private function sync_application_to_platform(int $post_id): string {
        $settings = $this->get_platform_settings();
        $token = $this->get_platform_token($settings);

        if ($settings['enabled'] !== '1' || $token === '') {
            update_post_meta($post_id, self::META_PREFIX . 'platform_status', 'not_configured');
            update_post_meta($post_id, self::META_PREFIX . 'platform_error', 'Platform integration is disabled or no API token is configured.');
            return 'not_configured';
        }

        $fields = $this->get_application_fields($post_id);
        if (!is_email($fields['email'])) {
            update_post_meta($post_id, self::META_PREFIX . 'platform_status', 'failed');
            update_post_meta($post_id, self::META_PREFIX . 'platform_error', 'Cannot sync because the application email is invalid.');
            return 'failed';
        }

        $variables = [
            'email' => $fields['email'],
            'locale' => $this->normalize_platform_locale($fields['language']),
            'inviteCode' => $settings['invite_code'] !== '' ? $settings['invite_code'] : null,
        ];

        $result = $this->call_platform_graphql($settings['endpoint'], $token, [
            'query' => 'mutation Signup($email: String!, $locale: String!, $inviteCode: String) { Signup(email: $email, locale: $locale, inviteCode: $inviteCode) { createdAt email verifiedAt } }',
            'variables' => $variables,
        ]);

        if (!$result['ok']) {
            update_post_meta($post_id, self::META_PREFIX . 'platform_status', 'failed');
            update_post_meta($post_id, self::META_PREFIX . 'platform_error', $result['message']);
            update_post_meta($post_id, self::META_PREFIX . 'platform_synced_at', current_time('mysql'));
            update_post_meta($post_id, self::META_PREFIX . 'platform_response', wp_json_encode($result['response'] ?? []));
            return 'failed';
        }

        update_post_meta($post_id, self::META_PREFIX . 'platform_status', 'signup_sent');
        update_post_meta($post_id, self::META_PREFIX . 'platform_error', '');
        update_post_meta($post_id, self::META_PREFIX . 'platform_synced_at', current_time('mysql'));
        update_post_meta($post_id, self::META_PREFIX . 'platform_response', wp_json_encode($result['response'] ?? []));

        return 'synced';
    }

    private function test_platform_connection(array $settings): array {
        $token = $this->get_platform_token($settings);
        if ($token === '') {
            return [
                'ok' => false,
                'message' => 'Settings saved, but no API token is configured yet.',
            ];
        }

        return $this->call_platform_graphql($settings['endpoint'], $token, [
            'query' => 'query { __typename }',
        ]);
    }

    private function call_platform_graphql(string $endpoint, string $token, array $payload): array {
        $response = wp_remote_post($endpoint, [
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'message' => $response->get_error_message(),
                'response' => [],
            ];
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status_code < 200 || $status_code >= 300) {
            return [
                'ok' => false,
                'message' => 'Platform returned HTTP ' . $status_code . '.',
                'response' => $decoded,
            ];
        }

        if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
            $first_error = $decoded['errors'][0]['message'] ?? 'The platform returned a GraphQL error.';

            return [
                'ok' => false,
                'message' => (string) $first_error,
                'response' => $decoded,
            ];
        }

        return [
            'ok' => true,
            'message' => 'Connection to staging is working.',
            'response' => $decoded,
        ];
    }

    private function normalize_platform_locale(string $language): string {
        return $this->is_supported_language($language) ? $language : self::DEFAULT_LANGUAGE;
    }

    private function format_platform_status(int $post_id): string {
        $status = (string) get_post_meta($post_id, self::META_PREFIX . 'platform_status', true);

        return match ($status) {
            'signup_sent' => 'Signup sent',
            'failed' => 'Failed',
            'not_configured' => 'Not configured',
            default => 'Not sent',
        };
    }

    private function set_platform_settings_notice(string $type, string $message): void {
        set_transient(
            'nutriminds_platform_settings_notice_' . get_current_user_id(),
            [
                'type' => $type,
                'message' => $message,
            ],
            MINUTE_IN_SECONDS
        );
    }

    private function render_platform_settings_notice(): void {
        $notice = get_transient('nutriminds_platform_settings_notice_' . get_current_user_id());
        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }

        delete_transient('nutriminds_platform_settings_notice_' . get_current_user_id());
        $type = (string) ($notice['type'] ?? 'success');
        $class = $type === 'error' ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible';

        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html((string) $notice['message']) . '</p></div>';
    }

    private function application_count(string $status): int {
        $query = new WP_Query([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => self::META_STATUS,
                    'value' => $status,
                ],
            ],
        ]);

        return (int) $query->found_posts;
    }

    private function format_specialty_summary(int $post_id): string {
        $specialties = $this->get_application_specialties($post_id);
        if ($specialties === []) {
            return 'None selected';
        }

        $names = array_map(static fn(array $item): string => (string) ($item['name'] ?? ''), $specialties);
        $names = array_values(array_filter($names));

        if (count($names) <= 3) {
            return implode(', ', $names);
        }

        return implode(', ', array_slice($names, 0, 3)) . ' +' . (count($names) - 3) . ' more';
    }

    private function document_link(int $attachment_id, string $label): string {
        if (!$attachment_id) {
            return esc_html($label . ': missing');
        }

        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            return esc_html($label . ': unavailable');
        }

        return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($label) . '</a>';
    }

    private function render_detail_row(string $label, string $value): void {
        echo '<tr><th style="width:220px;">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }

    private function is_valid_phone(string $phone): bool {
        $normalized = preg_replace('/[^\d+]/', '', $phone) ?? '';
        $digits = preg_replace('/\D/', '', $normalized) ?? '';

        return (bool) preg_match('/^\+?[\d\s().-]{7,24}$/', $phone) && strlen($digits) >= 7 && strlen($digits) <= 20;
    }
}

new NutriMinds_Doctor_Verification();
