<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Admin {
    private $scheduler;
    private $facebook;
    private $x;
    private $instagram;

    public function __construct($scheduler, Facebook $facebook, X $x, Instagram $instagram = null) {
        $this->scheduler  = $scheduler;
        $this->facebook   = $facebook;
        $this->x          = $x;
        $this->instagram  = $instagram;

        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_settings']);
        // Handle OAuth callbacks returning to settings page
        add_action('admin_init', [$this, 'maybe_handle_fb_callback'], 20);
        add_action('admin_init', [$this, 'maybe_handle_ig_callback'], 20);
        add_action('admin_notices', [$this, 'admin_notices']);

        // --- Facebook Handlers ---
        add_action('admin_post_aiss_fb_start_connect', [$this, 'start_fb_connect']);
        add_action('admin_post_aiss_fb_select_page',   [$this, 'select_fb_page']);
        add_action('admin_post_aiss_fb_disconnect',    [$this, 'disconnect_facebook']);

        // --- X (Twitter) Handlers ---
        add_action('admin_post_aiss_x_connect',    [$this, 'connect_x']);
        add_action('admin_post_aiss_x_callback',   [$this, 'callback_x']);
        add_action('admin_post_aiss_x_disconnect', [$this, 'disconnect_x']);

        // --- Instagram Handlers ---
        add_action('admin_post_aiss_ig_start_connect',  [$this, 'start_ig_connect']);
        add_action('admin_post_aiss_ig_select_account', [$this, 'select_ig_account']);
        add_action('admin_post_aiss_ig_disconnect',     [$this, 'disconnect_instagram']);

        // --- Cron / Debug Handlers ---
        add_action('admin_post_aiss_run_cron',       [$this, 'run_cron_manually']);
        add_action('admin_post_aiss_reset_cron',     [$this, 'reset_cron']);
        add_action('admin_post_aiss_clear_log',      [$this, 'clear_log']);
        add_action('admin_post_aiss_test_openrouter',[$this, 'test_openrouter']);

        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function menu() : void {
        add_options_page(
            'AI Social Share',
            'AI Social Share',
            'manage_options',
            'ai-social-share',
            [$this, 'render']
        );
    }

    public function admin_notices() : void {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'settings_page_ai-social-share') return;

        $config = Utils::is_configured();
        if (!$config['configured']) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>AI Social Share:</strong> Configuration incomplete. ';
            echo implode(', ', $config['issues']);
            echo '</p></div>';
        }

        if (class_exists('AISS\SimpleScheduler')) {
            $status = \AISS\SimpleScheduler::get_status();
        } else {
            return;
        }

        if (!$status['active'] || !$status['next_run']) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>Scheduler Issue:</strong> Tasks not scheduled. ';
            echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_reset_cron'), 'aiss_reset_cron') . '">Reset Scheduler</a>';
            echo '</p></div>';
        }
    }

    public function enqueue_admin_assets($hook) : void {
        if ($hook !== 'settings_page_ai-social-share') return;
        wp_enqueue_style('aiss-admin-style', AISS_PLUGIN_URL . 'assets/css/admin.css', [], AISS_VERSION);
    }

    public function register_settings() : void {
        register_setting('aiss_settings_group', 'aiss_settings', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default'           => Utils::get_settings(),
        ]);
    }

    public function sanitize_settings($input) : array {
        $s  = Utils::get_settings();
        $in = is_array($input) ? $input : [];

        // Detect which form tab is being submitted
        $is_general_form   = isset($in['openrouter_api_key']);
        $is_facebook_form  = isset($in['fb_app_id']);
        $is_x_form         = isset($in['x_consumer_key']);
        $is_instagram_form = isset($in['ig_image_model']);
        $is_scheduler_form = isset($in['_is_scheduler_tab'])
            || isset($in['schedule_minutes'])
            || isset($in['max_posts_per_run'])
            || isset($in['filter_post_age'])
            || isset($in['filter_mode'])
            || isset($in['filter_terms'])
            || isset($in['platform_gap_minutes']);

        // --- 1. SYSTEM / OAUTH FIELDS (set programmatically, must pass through) ---
        $system_fields = [
            // Facebook
            'fb_page_id', 'fb_page_name', 'fb_page_token', 'fb_connected_at',
            // X
            'x_access_token', 'x_access_secret', 'x_username',
            // Instagram
            'ig_user_id', 'ig_username', 'ig_page_name', 'ig_page_token', 'ig_connected_at',
        ];
        foreach ($system_fields as $field) {
            if (isset($in[$field])) {
                $s[$field] = $in[$field];
            }
        }

        // --- 2. CHECKBOXES (only update for the relevant tab) ---
        if ($is_general_form) {
            $s['enable_web_search'] = isset($in['enable_web_search']);
            $s['enable_debug_logs'] = isset($in['enable_debug_logs']);
        }
        if ($is_general_form || $is_scheduler_form) {
            $s['share_on_publish'] = !empty($in['share_on_publish']);
        }
        if ($is_x_form) {
            $s['x_enabled'] = isset($in['x_enabled']);
        }

        // --- 3. GENERAL SETTINGS ---
        if (isset($in['openrouter_api_key'])) $s['openrouter_api_key'] = trim($in['openrouter_api_key']);
        if (isset($in['openrouter_model']))   $s['openrouter_model']   = trim($in['openrouter_model']);
        if (isset($in['post_language']))      $s['post_language']      = sanitize_key($in['post_language']);

        // --- 4. SCHEDULER SETTINGS ---
        if (isset($in['schedule_minutes'])) {
            $old = (int)$s['schedule_minutes'];
            $new = max(5, min(1440, (int)$in['schedule_minutes']));
            $s['schedule_minutes'] = $new;
            if ($old !== $new) set_transient('aiss_force_reschedule', true, 60);
        }
        if (isset($in['max_posts_per_run']))    $s['max_posts_per_run']    = max(1, min(20, (int)$in['max_posts_per_run']));
        if (isset($in['platform_gap_minutes'])) $s['platform_gap_minutes'] = max(1, min(60, (int)$in['platform_gap_minutes']));
        if (isset($in['filter_mode']))          $s['filter_mode']          = sanitize_key($in['filter_mode']);
        if (isset($in['filter_terms']))         $s['filter_terms']         = sanitize_text_field($in['filter_terms']);
        if (isset($in['filter_post_age']))      $s['filter_post_age']      = sanitize_key($in['filter_post_age']);

        // --- 5. FACEBOOK SETTINGS ---
        if (isset($in['fb_app_id']))      $s['fb_app_id']      = sanitize_text_field($in['fb_app_id']);
        if (isset($in['fb_app_secret']))  $s['fb_app_secret']  = sanitize_text_field($in['fb_app_secret']);
        if (isset($in['fb_api_version'])) $s['fb_api_version'] = sanitize_text_field($in['fb_api_version']);
        if (isset($in['prompt_facebook'])) $s['prompt_facebook'] = wp_unslash($in['prompt_facebook']);

        // --- 6. X (TWITTER) SETTINGS ---
        if (isset($in['x_consumer_key']))    $s['x_consumer_key']    = sanitize_text_field($in['x_consumer_key']);
        if (isset($in['x_consumer_secret'])) $s['x_consumer_secret'] = sanitize_text_field($in['x_consumer_secret']);
        if (isset($in['prompt_x']))          $s['prompt_x']          = wp_unslash($in['prompt_x']);

        // --- 7. INSTAGRAM SETTINGS ---
        if (isset($in['ig_image_model']))  $s['ig_image_model']  = sanitize_text_field($in['ig_image_model']);
        if (isset($in['ig_brand_colors'])) $s['ig_brand_colors'] = sanitize_text_field($in['ig_brand_colors']);
        if (isset($in['prompt_instagram'])) $s['prompt_instagram'] = wp_unslash($in['prompt_instagram']);

        return $s;
    }

    // ── Main Render ────────────────────────────────────────────────────────

    public function render() : void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $tab      = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'status';
        $settings = Utils::get_settings();

        echo '<div class="wrap aiss-wrap">';
        echo '<div class="aiss-header"><h1>AI Social Share</h1></div>';

        // Notices
        if (isset($_GET['aiss_notice'])) {
            $notice = sanitize_key($_GET['aiss_notice']);
            $messages = [
                'saved'             => ['Settings saved successfully', 'success'],
                // Facebook
                'page_selected'     => ['Facebook page connected successfully', 'success'],
                'page_select_failed'=> ['Facebook page connection failed', 'error'],
                'fb_pages_ready'    => ['Facebook authorized. Please choose a Page.', 'success'],
                'fb_error'          => ['Facebook connection failed: ' . (isset($_GET['aiss_error']) ? urldecode($_GET['aiss_error']) : 'Unknown error'), 'error'],
                // X
                'x_connected'       => ['X account connected successfully', 'success'],
                'x_error'           => ['X connection failed: ' . (isset($_GET['aiss_error']) ? urldecode($_GET['aiss_error']) : 'Unknown error'), 'error'],
                // Instagram
                'ig_accounts_ready' => ['Instagram authorized. Please select your Business account.', 'success'],
                'ig_connected'      => ['Instagram account connected successfully', 'success'],
                'ig_error'          => ['Instagram connection failed: ' . (isset($_GET['aiss_error']) ? urldecode($_GET['aiss_error']) : 'Unknown error'), 'error'],
                'ig_select_failed'  => ['Instagram account selection failed', 'error'],
                // General
                'disconnected'      => ['Disconnected successfully', 'success'],
                'cron_run'          => ['Cron executed successfully', 'success'],
                'cron_reset'        => ['Cron schedule reset', 'success'],
                'log_cleared'       => ['Debug log cleared', 'success'],
                'openrouter_ok'     => ['OpenRouter connection successful', 'success'],
                'openrouter_error'  => ['OpenRouter connection failed: ' . (isset($_GET['aiss_error']) ? urldecode($_GET['aiss_error']) : 'Unknown error'), 'error'],
            ];

            if (isset($messages[$notice])) {
                if ($notice === 'page_select_failed' || $notice === 'ig_select_failed') {
                    $err = (string)get_transient('aiss_admin_error_' . get_current_user_id());
                    if ($err !== '') {
                        delete_transient('aiss_admin_error_' . get_current_user_id());
                        $messages[$notice][0] = rtrim($messages[$notice][0], '.') . ': ' . $err;
                    }
                }
                echo '<div class="aiss-notice notice-' . $messages[$notice][1] . '"><p>' . esc_html($messages[$notice][0]) . '</p></div>';
            }
        }

        // Tabs
        $tabs = [
            'status'    => 'Status',
            'general'   => 'General',
            'facebook'  => 'Facebook',
            'x'         => 'X / Twitter',
            'instagram' => 'Instagram',
            'scheduler' => 'Scheduler',
            'debug'     => 'Debug',
        ];

        echo '<div class="aiss-tabs">';
        foreach ($tabs as $key => $label) {
            $active = ($tab === $key) ? 'aiss-tab-active' : '';
            $url    = Utils::admin_url_settings(['tab' => $key]);
            echo '<a href="' . esc_url($url) . '" class="aiss-tab ' . $active . '">' . esc_html($label) . '</a>';
        }
        echo '</div>';

        // Render active tab
        switch ($tab) {
            case 'general':   $this->render_general($settings);   break;
            case 'facebook':  $this->render_facebook($settings);  break;
            case 'x':         $this->render_x($settings);         break;
            case 'instagram': $this->render_instagram($settings); break;
            case 'scheduler': $this->render_scheduler($settings); break;
            case 'debug':     $this->render_debug($settings);     break;
            default:          $this->render_status($settings);
        }

        echo '</div>';
    }

    // ── Tab: General ───────────────────────────────────────────────────────

    private function render_general($s) {
        echo '<form method="post" action="options.php">';
        settings_fields('aiss_settings_group');

        echo '<div class="aiss-card"><h2>OpenRouter AI</h2>';
        echo '<p class="description">Configure AI text generation for social media posts.</p>';
        echo '<table class="aiss-form-table">';

        echo '<tr><th>API Key <span style="color:#dc2626">*</span></th><td>';
        echo '<input type="password" class="aiss-input" name="aiss_settings[openrouter_api_key]" value="' . esc_attr($s['openrouter_api_key']) . '" autocomplete="off">';
        echo '<br><small style="color:#6b7280">Get your API key from <a href="https://openrouter.ai/keys" target="_blank">OpenRouter</a></small>';
        echo '</td></tr>';

        echo '<tr><th>Text Model</th><td>';
        echo '<input type="text" class="aiss-input" name="aiss_settings[openrouter_model]" value="' . esc_attr($s['openrouter_model']) . '" placeholder="openai/gpt-4o-mini">';
        echo '<br><small style="color:#6b7280">Used for Facebook &amp; X posts. Copy model ID from <a href="https://openrouter.ai/models" target="_blank">OpenRouter Models</a></small>';
        echo '</td></tr>';

        echo '<tr><th>Post Language</th><td>';
        echo '<select class="aiss-select" name="aiss_settings[post_language]">';
        $languages = [
            'en'=>'English','el'=>'Greek','es'=>'Spanish','fr'=>'French','de'=>'German',
            'it'=>'Italian','pt'=>'Portuguese','ru'=>'Russian','ar'=>'Arabic','zh'=>'Chinese',
            'ja'=>'Japanese','ko'=>'Korean','tr'=>'Turkish','nl'=>'Dutch','pl'=>'Polish',
        ];
        foreach ($languages as $code => $name) {
            echo '<option value="' . $code . '" ' . selected($s['post_language'] ?? 'en', $code, false) . '>' . $name . '</option>';
        }
        echo '</select>';
        echo '<br><small style="color:#6b7280">AI will write posts in this language for all platforms</small>';
        echo '</td></tr>';

        echo '<tr><th>Web Search</th><td>';
        echo '<label><input type="checkbox" name="aiss_settings[enable_web_search]" value="1" ' . checked($s['enable_web_search'] ?? false, true, false) . '> ';
        echo 'Enable AI web search for extra context</label>';
        echo '<br><small style="color:#6b7280">Allows AI to search the web when generating posts (may slow generation slightly)</small>';
        echo '</td></tr>';

        echo '</table>';
        echo '<div style="margin-top:16px">';
        echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_test_openrouter'), 'aiss_test_openrouter') . '" class="aiss-btn aiss-btn-secondary">Test Connection</a>';
        echo '</div></div>';

        echo '<button type="submit" class="aiss-btn">Save General Settings</button>';
        echo '</form>';
    }

    // ── Tab: Facebook ──────────────────────────────────────────────────────

    private function render_facebook($s) {
        $is_connected    = $this->facebook->is_connected();
        $pages_transient = get_transient('aiss_fb_pages_' . get_current_user_id());

        echo '<form method="post" action="options.php">';
        settings_fields('aiss_settings_group');

        echo '<div class="aiss-card"><h2>Facebook App Credentials</h2>';
        echo '<p class="description">Create a Facebook App at <a href="https://developers.facebook.com/apps" target="_blank">Facebook Developers</a>.</p>';
        echo '<table class="aiss-form-table">';
        echo '<tr><th>App ID</th><td><input class="aiss-input" name="aiss_settings[fb_app_id]" value="' . esc_attr($s['fb_app_id']) . '"></td></tr>';
        echo '<tr><th>App Secret</th><td><input type="password" class="aiss-input" name="aiss_settings[fb_app_secret]" value="' . esc_attr($s['fb_app_secret']) . '" autocomplete="off"></td></tr>';
        echo '<tr><th>API Version</th><td><input class="aiss-input" name="aiss_settings[fb_api_version]" value="' . esc_attr($s['fb_api_version']) . '" placeholder="v24.0" style="width:150px"></td></tr>';
        echo '</table></div>';

        // Connection status
        if ($is_connected) {
            echo '<div class="aiss-card" style="background:var(--aiss-success-bg); border-color:var(--aiss-success)">';
            echo '<h2 style="color:var(--aiss-success-text)">✓ Connected to Facebook</h2>';
            echo '<p><strong>Page:</strong> ' . esc_html($s['fb_page_name']) . ' (ID: ' . esc_html($s['fb_page_id']) . ')</p>';
            echo '<p><strong>Connected:</strong> ' . date_i18n('M j, Y g:i a', $s['fb_connected_at']) . '</p>';
            echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_disconnect'), 'aiss_fb_disconnect') . '" class="aiss-btn aiss-btn-danger">Disconnect</a>';
            echo '</div>';
        } elseif ($pages_transient) {
            echo '<div class="aiss-card"><h2>Select Facebook Page</h2>';
            echo '<p>Choose which Facebook Page to post to.</p>';
            echo '<select class="aiss-select" name="page_id" form="aiss_fb_select_form" style="margin-bottom:12px">';
            foreach ($pages_transient as $page) {
                echo '<option value="' . esc_attr($page['id']) . '">' . esc_html($page['name']) . '</option>';
            }
            echo '</select>';
            echo '<br><button type="submit" class="aiss-btn" form="aiss_fb_select_form">Connect Page</button>';
            echo '</div>';
        } else {
            echo '<div class="aiss-card"><h2>Connect Facebook Page</h2>';
            echo '<p>Click below to authenticate with Facebook and select a Page.</p>';
            echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_start_connect'), 'aiss_fb_connect') . '" class="aiss-btn">Connect Facebook</a>';
            echo '</div>';
        }

        echo '<div class="aiss-card"><h2>Facebook Post Prompt</h2>';
        echo '<p class="description">Instructions for AI when generating Facebook posts. Use <code>{language}</code> as a placeholder for the language setting.</p>';
        echo '<textarea class="aiss-textarea" name="aiss_settings[prompt_facebook]" rows="15">' . esc_textarea($s['prompt_facebook']) . '</textarea>';
        echo '</div>';

        echo '<button type="submit" class="aiss-btn">Save Facebook Settings</button></form>';

        // Hidden standalone form for Page selection (avoids invalid nested forms)
        if ($pages_transient) {
            echo '<form id="aiss_fb_select_form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:none">';
            wp_nonce_field('aiss_fb_select_page', 'aiss_fb_select_page_nonce');
            echo '<input type="hidden" name="action" value="aiss_fb_select_page">';
            echo '</form>';
        }
    }

    // ── Tab: X (Twitter) ──────────────────────────────────────────────────

    private function render_x($s) {
        $is_connected = $this->x->is_connected();

        echo '<form method="post" action="options.php">';
        settings_fields('aiss_settings_group');

        echo '<div class="aiss-card"><h2>X (Twitter) App Credentials</h2>';
        echo '<p class="description">Create an X App at <a href="https://developer.twitter.com/en/apps" target="_blank">X Developer Portal</a>. Enable OAuth 1.0a with Read &amp; Write permissions.</p>';
        echo '<table class="aiss-form-table">';
        echo '<tr><th>Enable X Sharing</th><td>';
        echo '<label><input type="checkbox" name="aiss_settings[x_enabled]" value="1" ' . checked($s['x_enabled'] ?? false, true, false) . '> Enable X (Twitter) integration</label>';
        echo '</td></tr>';
        echo '<tr><th>API Key (Consumer Key)</th><td><input class="aiss-input" name="aiss_settings[x_consumer_key]" value="' . esc_attr($s['x_consumer_key']) . '"></td></tr>';
        echo '<tr><th>API Secret (Consumer Secret)</th><td><input type="password" class="aiss-input" name="aiss_settings[x_consumer_secret]" value="' . esc_attr($s['x_consumer_secret']) . '" autocomplete="off"></td></tr>';
        echo '</table></div>';

        if ($is_connected) {
            echo '<div class="aiss-card" style="background:var(--aiss-success-bg); border-color:var(--aiss-success)">';
            echo '<h2 style="color:var(--aiss-success-text)">✓ Connected to X</h2>';
            echo '<p><strong>Account:</strong> @' . esc_html($s['x_username'] ?? 'Connected') . '</p>';
            echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_x_disconnect'), 'aiss_x_disconnect') . '" class="aiss-btn aiss-btn-danger">Disconnect</a>';
            echo '</div>';
        } else {
            echo '<div class="aiss-card"><h2>Connect X Account</h2>';
            if (empty($s['x_enabled'])) {
                echo '<p style="color:var(--aiss-warning-text)">Enable X integration above and save settings first.</p>';
            } elseif (empty($s['x_consumer_key']) || empty($s['x_consumer_secret'])) {
                echo '<p style="color:var(--aiss-warning-text)">Enter your API credentials above and save settings first.</p>';
            } else {
                echo '<p>Click below to authenticate your X account via OAuth.</p>';
                echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_x_connect'), 'aiss_x_connect') . '" class="aiss-btn aiss-btn-black">Connect X Account</a>';
            }
            echo '</div>';
        }

        echo '<div class="aiss-card"><h2>X Post Prompt</h2>';
        echo '<p class="description">Instructions for AI when generating X posts. Use <code>{language}</code> as a placeholder.</p>';
        echo '<textarea class="aiss-textarea" name="aiss_settings[prompt_x]" rows="15">' . esc_textarea($s['prompt_x']) . '</textarea>';
        echo '</div>';

        echo '<button type="submit" class="aiss-btn">Save X Settings</button></form>';
    }

    // ── Tab: Instagram ─────────────────────────────────────────────────────

    private function render_instagram($s) {
        $is_connected      = $this->instagram && $this->instagram->is_connected();
        $accounts_transient = get_transient('aiss_ig_accounts_' . get_current_user_id());

        echo '<form method="post" action="options.php">';
        settings_fields('aiss_settings_group');

        // ── Requirements notice ─────────────────────────────────────────────
        echo '<div class="aiss-card" style="border-color:#6366f1; background:#eef2ff">';
        echo '<h2 style="color:#4338ca">Instagram Requirements</h2>';
        echo '<ul style="margin:8px 0 0 20px; color:#374151; line-height:1.8">';
        echo '<li>Your Instagram account must be <strong>Business</strong> or <strong>Creator</strong> type</li>';
        echo '<li>It must be <strong>linked to a Facebook Page</strong> in Instagram settings</li>';
        echo '<li>You must use the <strong>same Facebook App</strong> configured on the Facebook tab</li>';
        echo '<li>Your FB App needs these extra permissions: <code>instagram_basic</code>, <code>instagram_content_publish</code></li>';
        echo '<li>The site must be <strong>live (not localhost)</strong> — Instagram API cannot fetch images from local URLs</li>';
        echo '</ul></div>';

        // ── Image model settings ────────────────────────────────────────────
        echo '<div class="aiss-card"><h2>Image Generation (Nano Banana)</h2>';
        echo '<p class="description">Instagram posts are generated using Google Gemini\'s image model via OpenRouter. One API call produces both the image and caption.</p>';
        echo '<table class="aiss-form-table">';

        echo '<tr><th>Image Model</th><td>';
        echo '<input type="text" class="aiss-input" name="aiss_settings[ig_image_model]" value="' . esc_attr($s['ig_image_model'] ?? 'google/gemini-2.5-flash-image-preview') . '">';
        echo '<br><small style="color:#6b7280">Recommended: <code>google/gemini-2.5-flash-image-preview</code> (Nano Banana). ';
        echo 'Find image models at <a href="https://openrouter.ai/models?output_modalities=image" target="_blank">OpenRouter</a></small>';
        echo '</td></tr>';

        echo '<tr><th>Brand Colors</th><td>';
        echo '<input type="text" class="aiss-input" name="aiss_settings[ig_brand_colors]" value="' . esc_attr($s['ig_brand_colors'] ?? '') . '" placeholder="e.g. dark blue and white">';
        echo '<br><small style="color:#6b7280">Describe your brand palette — AI will apply these to generated images</small>';
        echo '</td></tr>';

        echo '</table></div>';

        // ── Connection status ───────────────────────────────────────────────
        if ($is_connected) {
            echo '<div class="aiss-card" style="background:var(--aiss-success-bg); border-color:var(--aiss-success)">';
            echo '<h2 style="color:var(--aiss-success-text)">✓ Connected to Instagram</h2>';
            echo '<p><strong>Account:</strong> @' . esc_html($s['ig_username'] ?? '') . '</p>';
            if (!empty($s['ig_page_name'])) {
                echo '<p><strong>Facebook Page:</strong> ' . esc_html($s['ig_page_name']) . '</p>';
            }
            if (!empty($s['ig_connected_at'])) {
                echo '<p><strong>Connected:</strong> ' . date_i18n('M j, Y g:i a', $s['ig_connected_at']) . '</p>';
            }
            echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_ig_disconnect'), 'aiss_ig_disconnect') . '" class="aiss-btn aiss-btn-danger">Disconnect Instagram</a>';
            echo '</div>';

        } elseif ($accounts_transient) {
            // ── Account selection step ────────────────────────────────────
            echo '<div class="aiss-card"><h2>Select Instagram Business Account</h2>';
            echo '<p>The following Instagram Business accounts were found linked to your Facebook Pages. Select which one to use for posting.</p>';

            if (empty($accounts_transient)) {
                echo '<div class="aiss-notice notice-error"><p>';
                echo 'No Instagram Business accounts found. Make sure your Instagram account is set to Business or Creator mode and is linked to a Facebook Page.';
                echo '</p></div>';
            } else {
                echo '<select class="aiss-select" name="ig_user_id" form="aiss_ig_select_form" style="margin-bottom:12px; width:100%; max-width:400px">';
                foreach ($accounts_transient as $account) {
                    echo '<option value="' . esc_attr($account['ig_user_id']) . '">';
                    echo '@' . esc_html($account['ig_username']);
                    if (!empty($account['page_name'])) {
                        echo ' (via ' . esc_html($account['page_name']) . ')';
                    }
                    echo '</option>';
                }
                echo '</select><br>';
                echo '<button type="submit" class="aiss-btn" form="aiss_ig_select_form">Connect This Account</button>';
            }
            echo '</div>';

        } else {
            // ── Initial connect step ──────────────────────────────────────
            echo '<div class="aiss-card"><h2>Connect Instagram Account</h2>';
            if (empty($s['fb_app_id']) || empty($s['fb_app_secret'])) {
                echo '<p style="color:var(--aiss-warning-text)">⚠ Configure your Facebook App ID and Secret on the <a href="' . esc_url(Utils::admin_url_settings(['tab' => 'facebook'])) . '">Facebook tab</a> first.</p>';
            } else {
                echo '<p>Click below to authorize Instagram access via your Facebook account. You\'ll select which Instagram Business account to use on the next step.</p>';
                echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_ig_start_connect'), 'aiss_ig_connect') . '" class="aiss-btn" style="background:#e1306c; border-color:#e1306c">Connect Instagram</a>';
            }
            echo '</div>';
        }

        // ── Prompt settings ─────────────────────────────────────────────────
        echo '<div class="aiss-card"><h2>Instagram Post Prompt</h2>';
        echo '<p class="description">Instructions for AI when generating the Instagram caption and image. Available placeholders: <code>{language}</code>, <code>{site_name}</code>, <code>{brand_colors}</code>.</p>';
        echo '<textarea class="aiss-textarea" name="aiss_settings[prompt_instagram]" rows="20">';
        $default_prompt = class_exists('AISS\ImageGenerator') ? ImageGenerator::default_instagram_prompt() : '';
        echo esc_textarea($s['prompt_instagram'] ?? $default_prompt);
        echo '</textarea>';
        echo '</div>';

        echo '<button type="submit" class="aiss-btn">Save Instagram Settings</button></form>';

        // Standalone hidden form for account selection
        if ($accounts_transient && !empty($accounts_transient)) {
            echo '<form id="aiss_ig_select_form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:none">';
            wp_nonce_field('aiss_ig_select_account', 'aiss_ig_select_nonce');
            echo '<input type="hidden" name="action" value="aiss_ig_select_account">';
            echo '</form>';
        }
    }

    // ── Tab: Scheduler ─────────────────────────────────────────────────────

    private function render_scheduler($s) {
        echo '<form method="post" action="options.php">';
        settings_fields('aiss_settings_group');
        echo '<input type="hidden" name="aiss_settings[_is_scheduler_tab]" value="1">';

        echo '<div class="aiss-card"><h2>Sharing Methods</h2>';
        echo '<p class="description">Choose how posts are shared to social media.</p>';
        echo '<table class="aiss-form-table">';
        echo '<tr><th>Share on Publish</th><td>';
        echo '<label><input type="checkbox" name="aiss_settings[share_on_publish]" value="1" ' . checked($s['share_on_publish'] ?? false, true, false) . '> ';
        echo '<strong>Share immediately when publishing posts</strong></label>';
        echo '<br><small style="color:#6b7280">Recommended! Posts share instantly when you click "Publish" with no waiting for cron.</small>';
        echo '</td></tr>';
        echo '</table></div>';

        echo '<div class="aiss-card"><h2>Background Schedule</h2>';
        echo '<p class="description">Controls how often the plugin checks for unshared posts. With staggered sharing, each enabled platform runs once per interval, separated by the gap below.</p>';
        echo '<table class="aiss-form-table">';

        echo '<tr><th>Check Interval</th><td>';
        echo '<input type="number" class="aiss-input" name="aiss_settings[schedule_minutes]" value="' . (int)$s['schedule_minutes'] . '" min="5" max="1440" style="width:100px"> minutes';
        echo '<br><small style="color:#6b7280">How often the queue runs for each platform. E.g. 60 = each platform shares at most once per hour.</small>';
        echo '</td></tr>';

        echo '<tr><th>Platform Gap</th><td>';
        echo '<input type="number" class="aiss-input" name="aiss_settings[platform_gap_minutes]" value="' . (int)($s['platform_gap_minutes'] ?? 10) . '" min="1" max="60" style="width:100px"> minutes';
        echo '<br><small style="color:#6b7280">Minimum wait between platforms. E.g. with 3 platforms and a 10-min gap: FB at T+0, X at T+10, Instagram at T+20.</small>';
        echo '</td></tr>';

        echo '<tr><th>Posts per Batch</th><td>';
        echo '<input type="number" class="aiss-input" name="aiss_settings[max_posts_per_run]" value="' . (int)$s['max_posts_per_run'] . '" min="1" max="20" style="width:100px">';
        echo '<br><small style="color:#6b7280">Max posts to share per platform per run</small>';
        echo '</td></tr>';

        echo '</table></div>';

        echo '<div class="aiss-card"><h2>Post Filters</h2>';
        echo '<p class="description">Limit which posts are auto-shared.</p>';
        echo '<table class="aiss-form-table">';

        echo '<tr><th>Post Age</th><td>';
        echo '<select class="aiss-select" name="aiss_settings[filter_post_age]">';
        $age_options = ['24h' => 'Last 24 Hours', '48h' => 'Last 48 Hours', '7d' => 'Last 7 Days', '30d' => 'Last 30 Days', 'all' => 'All Posts'];
        foreach ($age_options as $value => $label) {
            echo '<option value="' . $value . '" ' . selected($s['filter_post_age'] ?? '24h', $value, false) . '>' . $label . '</option>';
        }
        echo '</select>';
        echo '<br><small style="color:#6b7280">Only process posts published within this timeframe</small>';
        echo '</td></tr>';

        echo '<tr><th>Filter Mode</th><td>';
        echo '<select class="aiss-select" name="aiss_settings[filter_mode]">';
        foreach (['all' => 'All Posts', 'category' => 'Specific Category', 'tag' => 'Specific Tag'] as $k => $v) {
            echo '<option value="' . $k . '" ' . selected($s['filter_mode'], $k, false) . '>' . $v . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th>Slugs</th><td>';
        echo '<input class="aiss-input" name="aiss_settings[filter_terms]" value="' . esc_attr($s['filter_terms']) . '" placeholder="news, tech">';
        echo '<br><small style="color:#6b7280">Comma-separated category/tag slugs (used when Filter Mode is not "All")</small>';
        echo '</td></tr>';

        echo '</table></div>';

        echo '<button type="submit" class="aiss-btn">Save Schedule Settings</button></form>';
    }

    // ── Tab: Status ────────────────────────────────────────────────────────

    private function render_status($s) {
        $fb = $this->facebook->is_connected();
        $x  = $this->x->is_connected();
        $ig = $this->instagram && $this->instagram->is_connected();

        if (class_exists('AISS\SimpleScheduler')) {
            $status = \AISS\SimpleScheduler::get_status();
        } else {
            $status = ['active' => false, 'next_run' => null, 'last_run' => 0, 'platform_state' => []];
        }

        $nxt      = $status['next_run'];
        $last_run = $status['last_run'];
        $stats    = get_option('aiss_last_run_stats', []);

        // Auto-fix: reschedule if overdue or missing
        if (!$nxt || ($nxt && $nxt < time()) || !$status['active'] || get_transient('aiss_force_reschedule')) {
            if (method_exists($this->scheduler, 'ensure_scheduled')) {
                $this->scheduler->ensure_scheduled();
            }
            delete_transient('aiss_force_reschedule');
            if (class_exists('AISS\SimpleScheduler')) {
                $status = \AISS\SimpleScheduler::get_status();
            }
            $nxt = $status['next_run'];
        }

        $health_class = ($status['active'] && $nxt && $nxt >= time()) ? 'health-good' : 'health-bad';
        $health_text  = ($status['active'] && $nxt && $nxt >= time()) ? 'Healthy' : 'Issues Detected';

        echo '<div style="margin-bottom:24px">';
        echo '<span class="aiss-health-badge ' . $health_class . '">' . $health_text . '</span>';
        echo '</div>';

        // Platform connection grid
        echo '<div class="aiss-stats-grid">';

        echo '<div class="aiss-stat-box">';
        echo '<div class="aiss-stat-label">Facebook</div>';
        echo '<div class="aiss-stat-value"><span class="aiss-status-dot ' . ($fb ? 'dot-green' : 'dot-red') . '"></span>' . ($fb ? 'Connected' : 'Offline') . '</div>';
        echo '</div>';

        echo '<div class="aiss-stat-box">';
        echo '<div class="aiss-stat-label">X (Twitter)</div>';
        $x_status_class = $x ? 'dot-green' : 'dot-gray';
        $x_status_text  = $x ? 'Connected' : ($s['x_enabled'] ? 'Not Connected' : 'Disabled');
        echo '<div class="aiss-stat-value"><span class="aiss-status-dot ' . $x_status_class . '"></span>' . $x_status_text . '</div>';
        echo '</div>';

        echo '<div class="aiss-stat-box">';
        echo '<div class="aiss-stat-label">Instagram</div>';
        $ig_class = $ig ? 'dot-green' : 'dot-gray';
        $ig_text  = $ig ? ('@' . esc_html($s['ig_username'] ?? 'Connected')) : 'Not Connected';
        echo '<div class="aiss-stat-value"><span class="aiss-status-dot ' . $ig_class . '"></span>' . $ig_text . '</div>';
        echo '</div>';

        echo '<div class="aiss-stat-box">';
        echo '<div class="aiss-stat-label">Next Cron Run</div>';
        if ($nxt && $nxt > time()) {
            echo '<div class="aiss-stat-value">In ' . human_time_diff(time(), $nxt) . '</div>';
            echo '<div style="font-size:11px; color:var(--aiss-muted); margin-top:4px">' . date_i18n('M j, g:i a', $nxt) . '</div>';
        } elseif ($nxt) {
            echo '<div class="aiss-stat-value" style="color:var(--aiss-error)">Overdue</div>';
        } else {
            echo '<div class="aiss-stat-value" style="color:var(--aiss-muted)">Not Scheduled</div>';
        }
        echo '</div>';

        echo '</div>';

        // Platform rotation state
        $plat_state = $status['platform_state'] ?? [];
        if (!empty($plat_state)) {
            $interval = max(5, (int)$s['schedule_minutes']) * 60;
            $gap      = max(1, (int)($s['platform_gap_minutes'] ?? 10)) * 60;
            $now      = time();

            echo '<div class="aiss-card"><h2>Platform Rotation</h2>';
            echo '<p class="description">Shows when each platform last ran and when it\'s next due. Interval: ' . (int)$s['schedule_minutes'] . ' min | Gap between platforms: ' . (int)($s['platform_gap_minutes'] ?? 10) . ' min.</p>';
            echo '<table class="aiss-form-table">';

            $platform_labels = ['fb' => 'Facebook', 'x' => 'X (Twitter)', 'ig' => 'Instagram'];
            $platform_connected = ['fb' => $fb, 'x' => $x, 'ig' => $ig];

            foreach ($platform_labels as $key => $label) {
                $last  = (int)($plat_state[$key]['last_run'] ?? 0);
                $since = $last ? ($now - $last) : null;
                $due   = ($since !== null && $since >= $interval);

                echo '<tr><th>' . $label . '</th><td>';
                if (!$platform_connected[$key]) {
                    echo '<span style="color:var(--aiss-muted)">Not connected</span>';
                } else {
                    if ($last) {
                        echo 'Last run: ' . Utils::format_time_ago($last);
                        echo ' &nbsp; <span style="color:' . ($due ? 'var(--aiss-success-text)' : 'var(--aiss-muted)') . '">';
                        echo $due ? '● Due now' : '● Next in ' . human_time_diff($now, $last + $interval);
                        echo '</span>';
                    } else {
                        echo '<span style="color:var(--aiss-success-text)">● Ready (never run)</span>';
                    }
                }
                echo '</td></tr>';
            }
            echo '</table></div>';
        }

        // System overview
        echo '<div class="aiss-card"><h2>System Overview</h2>';
        echo '<table class="aiss-form-table">';
        echo '<tr><th>Cron Interval</th><td>Every ' . (int)$s['schedule_minutes'] . ' minutes per platform</td></tr>';
        echo '<tr><th>Platform Gap</th><td>' . (int)($s['platform_gap_minutes'] ?? 10) . ' minutes between platforms</td></tr>';
        echo '<tr><th>Batch Size</th><td>' . (int)$s['max_posts_per_run'] . ' posts per platform per run</td></tr>';
        echo '<tr><th>Post Filter</th><td>' . ucfirst($s['filter_mode']);
        if ($s['filter_mode'] !== 'all' && !empty($s['filter_terms'])) {
            echo ': ' . esc_html($s['filter_terms']);
        }
        echo '</td></tr>';

        if (!empty($stats)) {
            echo '<tr><th>Last Run Stats</th><td>';
            if (!empty($stats['platform'])) {
                $plabels = ['fb' => 'Facebook', 'x' => 'X (Twitter)', 'ig' => 'Instagram'];
                echo '<strong>Platform:</strong> ' . ($plabels[$stats['platform']] ?? $stats['platform']) . '<br>';
            }
            echo 'Processed: ' . (int)($stats['processed'] ?? 0) . ' | ';
            echo 'Shared: ' . (int)($stats['shared'] ?? 0) . ' | ';
            echo 'Skipped: ' . (int)($stats['skipped'] ?? 0) . ' | ';
            echo 'Errors: ' . (int)($stats['errors'] ?? 0);
            echo '<br>Duration: ' . number_format($stats['duration'] ?? 0, 2) . 's';
            echo '</td></tr>';
        }
        echo '</table>';
        echo '<div style="display:flex; gap:12px; margin-top:16px">';
        echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_run_cron'), 'aiss_run_cron') . '" class="aiss-btn">Run Now</a>';
        echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_reset_cron'), 'aiss_reset_cron') . '" class="aiss-btn aiss-btn-secondary">Reset Cron</a>';
        echo '</div></div>';

        // System info
        $info = Utils::get_plugin_info();
        echo '<div class="aiss-card"><h2>System Information</h2>';
        echo '<table class="aiss-form-table">';
        echo '<tr><th>Plugin Version</th><td>' . AISS_VERSION . '</td></tr>';
        echo '<tr><th>WordPress</th><td>' . $info['wp_version'] . '</td></tr>';
        echo '<tr><th>PHP</th><td>' . $info['php_version'] . '</td></tr>';
        echo '<tr><th>WP-Cron</th><td>' . ($info['wp_cron_enabled'] ? '✓ Enabled' : '✗ Disabled — ensure system cron is configured') . '</td></tr>';
        echo '</table></div>';
    }

    // ── Tab: Debug ─────────────────────────────────────────────────────────

    private function render_debug($s) {
        $log = get_option('aiss_debug_log', '');

        echo '<div class="aiss-card">';
        echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px">';
        echo '<h2 style="margin:0">Debug Log</h2>';
        echo '<div style="display:flex; gap:8px; align-items:center">';
        echo '<form method="post" action="options.php" style="display:inline">';
        settings_fields('aiss_settings_group');
        echo '<label style="display:flex; align-items:center; gap:8px">';
        echo '<input type="checkbox" name="aiss_settings[enable_debug_logs]" value="1" ' . checked($s['enable_debug_logs'] ?? false, true, false) . ' onchange="this.form.submit()">';
        echo '<span>Enable Logging</span></label>';
        echo '</form>';
        echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_clear_log'), 'aiss_clear_log') . '" class="aiss-btn aiss-btn-sm aiss-btn-secondary">Clear Log</a>';
        echo '</div></div>';

        if (empty($log)) {
            echo '<p style="color:#6b7280">No log entries yet. Enable logging and run the cron to see output.</p>';
        } else {
            echo '<div class="aiss-debug-log">';
            $lines = explode("\n", $log);
            foreach ($lines as $line) {
                if (empty(trim($line))) continue;
                $class = 'log-info';
                if (strpos($line, '[error]') !== false)   $class = 'log-error';
                elseif (strpos($line, '[warning]') !== false) $class = 'log-warning';
                echo '<div class="' . $class . '">' . esc_html($line) . '</div>';
            }
            echo '</div>';
        }
        echo '</div>';

        // Active prompts preview
        echo '<div class="aiss-card"><h2>Active Prompts</h2>';
        echo '<p class="description">These are the prompts currently sent to AI (with language setting applied).</p>';

        $language = $this->get_language_display($s['post_language'] ?? 'en');

        echo '<div style="margin-bottom:20px">';
        echo '<h3 style="font-size:14px; margin:0 0 8px; color:#374151">Facebook Prompt:</h3>';
        echo '<div style="background:#f9fafb; padding:12px; border-radius:6px; font-family:monospace; font-size:12px; white-space:pre-wrap; border:1px solid #e5e7eb">';
        echo esc_html(str_replace('{language}', $language, $s['prompt_facebook']));
        echo '</div></div>';

        echo '<div style="margin-bottom:20px">';
        echo '<h3 style="font-size:14px; margin:0 0 8px; color:#374151">X/Twitter Prompt:</h3>';
        echo '<div style="background:#f9fafb; padding:12px; border-radius:6px; font-family:monospace; font-size:12px; white-space:pre-wrap; border:1px solid #e5e7eb">';
        echo esc_html(str_replace('{language}', $language, $s['prompt_x']));
        echo '</div></div>';

        if (!empty($s['prompt_instagram'])) {
            echo '<div>';
            echo '<h3 style="font-size:14px; margin:0 0 8px; color:#374151">Instagram Prompt:</h3>';
            echo '<div style="background:#f9fafb; padding:12px; border-radius:6px; font-family:monospace; font-size:12px; white-space:pre-wrap; border:1px solid #e5e7eb">';
            $ig_prompt = str_replace(
                ['{language}', '{site_name}', '{brand_colors}'],
                [$language, get_bloginfo('name'), $s['ig_brand_colors'] ?? 'professional dark blue and white'],
                $s['prompt_instagram']
            );
            echo esc_html($ig_prompt);
            echo '</div></div>';
        }

        echo '</div>';

        // Quick actions
        echo '<div class="aiss-card"><h2>Quick Actions</h2>';
        echo '<p class="description">Debug tools for testing and troubleshooting.</p>';
        echo '<div style="display:flex; gap:12px; margin-top:16px">';
        echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_run_cron'), 'aiss_run_cron') . '" class="aiss-btn aiss-btn-secondary">Run Cron</a>';
        echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_reset_cron'), 'aiss_reset_cron') . '" class="aiss-btn aiss-btn-secondary">Reset Cron</a>';
        echo '</div></div>';
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function get_language_display($code) {
        $languages = [
            'en'=>'English','el'=>'Greek','es'=>'Spanish','fr'=>'French','de'=>'German',
            'it'=>'Italian','pt'=>'Portuguese','ru'=>'Russian','ar'=>'Arabic','zh'=>'Chinese',
            'ja'=>'Japanese','ko'=>'Korean','tr'=>'Turkish','nl'=>'Dutch','pl'=>'Polish',
        ];
        return $languages[$code] ?? 'English';
    }

    // ── Action Handlers: Facebook ──────────────────────────────────────────

    public function start_fb_connect() {
        check_admin_referer('aiss_fb_connect');
        wp_redirect($this->facebook->build_oauth_url());
        exit;
    }

    public function maybe_handle_fb_callback() : void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        if (empty($_GET['page']) || $_GET['page'] !== 'ai-social-share') return;
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
        if ($tab !== 'facebook' || !isset($_GET['aiss_fb_cb'])) return;

        $code  = isset($_GET['code'])  ? sanitize_text_field(wp_unslash($_GET['code']))  : '';
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';

        if ($code === '' || $state === '') {
            wp_redirect(Utils::admin_url_settings(['tab' => 'facebook', 'aiss_notice' => 'fb_error', 'aiss_error' => urlencode('Missing OAuth parameters.')]));
            exit;
        }

        $res = $this->facebook->handle_callback_and_fetch_pages($code, $state);

        if (!empty($res['ok'])) {
            set_transient('aiss_fb_pages_' . get_current_user_id(), $res['pages'] ?? [], 20 * MINUTE_IN_SECONDS);
            wp_redirect(Utils::admin_url_settings(['tab' => 'facebook', 'aiss_notice' => 'fb_pages_ready']));
            exit;
        }

        wp_redirect(Utils::admin_url_settings(['tab' => 'facebook', 'aiss_notice' => 'fb_error', 'aiss_error' => urlencode($res['error'] ?? 'Unknown error')]));
        exit;
    }

    public function select_fb_page() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);
        check_admin_referer('aiss_fb_select_page', 'aiss_fb_select_page_nonce');

        $page_id = isset($_POST['page_id']) ? sanitize_text_field((string)$_POST['page_id']) : '';
        $res     = $this->facebook->select_page_and_store($page_id);
        delete_transient('aiss_fb_pages_' . get_current_user_id());

        if (is_array($res) && !empty($res['ok'])) {
            wp_safe_redirect(Utils::admin_url_settings(['tab' => 'facebook', 'aiss_notice' => 'page_selected']));
            exit;
        }

        $err = (is_array($res) && !empty($res['error'])) ? $res['error'] : 'Could not connect the selected page.';
        set_transient('aiss_admin_error_' . get_current_user_id(), $err, 60);
        wp_safe_redirect(Utils::admin_url_settings(['tab' => 'facebook', 'aiss_notice' => 'page_select_failed']));
        exit;
    }

    public function disconnect_facebook() {
        check_admin_referer('aiss_fb_disconnect');
        $s = Utils::get_settings();
        $s['fb_page_id'] = $s['fb_page_name'] = $s['fb_page_token'] = '';
        Utils::update_settings($s);
        wp_redirect(Utils::admin_url_settings(['tab' => 'facebook', 'aiss_notice' => 'disconnected']));
        exit;
    }

    // ── Action Handlers: X (Twitter) ──────────────────────────────────────

    public function connect_x() {
        check_admin_referer('aiss_x_connect');
        $res = $this->x->get_auth_url();
        if (!$res['ok']) wp_die($res['error']);
        wp_redirect($res['url']);
        exit;
    }

    public function callback_x() {
        if (!isset($_GET['oauth_token']) || !isset($_GET['oauth_verifier'])) wp_die('Invalid Callback');
        $res = $this->x->handle_callback($_GET['oauth_token'], $_GET['oauth_verifier']);
        if ($res['ok']) {
            $s = Utils::get_settings();
            $s['x_access_token']  = $res['token'];
            $s['x_access_secret'] = $res['secret'];
            $s['x_username']      = $res['screen_name'];
            $s['x_enabled']       = true;
            Utils::update_settings($s);
            wp_redirect(Utils::admin_url_settings(['tab' => 'x', 'aiss_notice' => 'x_connected']));
        } else {
            wp_redirect(Utils::admin_url_settings(['tab' => 'x', 'aiss_notice' => 'x_error', 'aiss_error' => urlencode($res['error'])]));
        }
        exit;
    }

    public function disconnect_x() {
        check_admin_referer('aiss_x_disconnect');
        $s = Utils::get_settings();
        $s['x_access_token'] = $s['x_access_secret'] = $s['x_username'] = '';
        Utils::update_settings($s);
        wp_redirect(Utils::admin_url_settings(['tab' => 'x', 'aiss_notice' => 'disconnected']));
        exit;
    }

    // ── Action Handlers: Instagram ─────────────────────────────────────────

    public function start_ig_connect() {
        check_admin_referer('aiss_ig_connect');
        if (!$this->instagram) wp_die('Instagram class not loaded.');
        wp_redirect($this->instagram->build_oauth_url());
        exit;
    }

    public function maybe_handle_ig_callback() : void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        if (empty($_GET['page']) || $_GET['page'] !== 'ai-social-share') return;
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
        if ($tab !== 'instagram' || !isset($_GET['aiss_ig_cb'])) return;
        if (!$this->instagram) return;

        $code  = isset($_GET['code'])  ? sanitize_text_field(wp_unslash($_GET['code']))  : '';
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';

        if ($code === '' || $state === '') {
            wp_redirect(Utils::admin_url_settings(['tab' => 'instagram', 'aiss_notice' => 'ig_error', 'aiss_error' => urlencode('Missing OAuth parameters.')]));
            exit;
        }

        $res = $this->instagram->handle_callback_and_fetch_accounts($code, $state);

        if (!empty($res['ok'])) {
            set_transient('aiss_ig_accounts_' . get_current_user_id(), $res['accounts'] ?? [], 20 * MINUTE_IN_SECONDS);
            wp_redirect(Utils::admin_url_settings(['tab' => 'instagram', 'aiss_notice' => 'ig_accounts_ready']));
            exit;
        }

        wp_redirect(Utils::admin_url_settings(['tab' => 'instagram', 'aiss_notice' => 'ig_error', 'aiss_error' => urlencode($res['error'] ?? 'Unknown error')]));
        exit;
    }

    public function select_ig_account() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);
        check_admin_referer('aiss_ig_select_account', 'aiss_ig_select_nonce');
        if (!$this->instagram) wp_die('Instagram class not loaded.');

        $ig_user_id = isset($_POST['ig_user_id']) ? sanitize_text_field((string)$_POST['ig_user_id']) : '';
        $res        = $this->instagram->select_account_and_store($ig_user_id);
        delete_transient('aiss_ig_accounts_' . get_current_user_id());

        if (!empty($res['ok'])) {
            wp_safe_redirect(Utils::admin_url_settings(['tab' => 'instagram', 'aiss_notice' => 'ig_connected']));
            exit;
        }

        $err = $res['error'] ?? 'Could not connect the selected Instagram account.';
        set_transient('aiss_admin_error_' . get_current_user_id(), $err, 60);
        wp_safe_redirect(Utils::admin_url_settings(['tab' => 'instagram', 'aiss_notice' => 'ig_select_failed']));
        exit;
    }

    public function disconnect_instagram() {
        check_admin_referer('aiss_ig_disconnect');
        if ($this->instagram) $this->instagram->disconnect();
        wp_redirect(Utils::admin_url_settings(['tab' => 'instagram', 'aiss_notice' => 'disconnected']));
        exit;
    }

    // ── Action Handlers: Cron / Debug ──────────────────────────────────────

    public function run_cron_manually() {
        check_admin_referer('aiss_run_cron');
        $this->scheduler->process_queue();
        wp_redirect(Utils::admin_url_settings(['tab' => 'status', 'aiss_notice' => 'cron_run']));
        exit;
    }

    public function reset_cron() {
        check_admin_referer('aiss_reset_cron');
        if (method_exists($this->scheduler, 'clear_scheduled')) $this->scheduler->clear_scheduled();
        if (method_exists($this->scheduler, 'ensure_scheduled')) $this->scheduler->ensure_scheduled(true);
        wp_redirect(Utils::admin_url_settings(['tab' => 'status', 'aiss_notice' => 'cron_reset']));
        exit;
    }

    public function clear_log() {
        check_admin_referer('aiss_clear_log');
        if (class_exists('AISS\SimpleScheduler')) \AISS\SimpleScheduler::clear_log();
        wp_redirect(Utils::admin_url_settings(['tab' => 'debug', 'aiss_notice' => 'log_cleared']));
        exit;
    }

    public function test_openrouter() {
        check_admin_referer('aiss_test_openrouter');
        $openrouter = new OpenRouter();
        $result     = $openrouter->test_connection();
        if ($result['ok']) {
            wp_redirect(Utils::admin_url_settings(['tab' => 'general', 'aiss_notice' => 'openrouter_ok']));
        } else {
            wp_redirect(Utils::admin_url_settings(['tab' => 'general', 'aiss_notice' => 'openrouter_error', 'aiss_error' => urlencode($result['error'])]));
        }
        exit;
    }
}