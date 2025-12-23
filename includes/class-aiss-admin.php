<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Admin {
    private $scheduler;
    private $facebook;
    private $x;

    public function __construct($scheduler, Facebook $facebook, X $x) {
        $this->scheduler = $scheduler;
        $this->facebook = $facebook;
        $this->x = $x;

        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'admin_notices']);
        
        // --- Form Handlers ---
        add_action('admin_post_aiss_fb_start_connect', [$this, 'start_fb_connect']);
        add_action('admin_post_aiss_fb_select_page', [$this, 'select_fb_page']);
        add_action('admin_post_aiss_fb_disconnect', [$this, 'disconnect_facebook']);
        
        // X (Twitter) Handlers
        add_action('admin_post_aiss_x_connect', [$this, 'connect_x']);
        add_action('admin_post_aiss_x_callback', [$this, 'callback_x']);
        add_action('admin_post_aiss_x_disconnect', [$this, 'disconnect_x']);
        
        // Cron Handlers
        add_action('admin_post_aiss_run_cron', [$this, 'run_cron_manually']);
        add_action('admin_post_aiss_reset_cron', [$this, 'reset_cron']);
        add_action('admin_post_aiss_clear_log', [$this, 'clear_log']);
        
        // Test OpenRouter Connection
        add_action('admin_post_aiss_test_openrouter', [$this, 'test_openrouter']);
        
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
        // Only show on our settings page
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'settings_page_ai-social-share') {
            return;
        }

        // Check configuration status
        $config = Utils::is_configured();
        if (!$config['configured']) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>AI Social Share:</strong> Configuration incomplete. ';
            echo implode(', ', $config['issues']);
            echo '</p></div>';
        }

        // Check scheduler health
        if (class_exists('AISS\Scheduler')) {
            $status = \AISS\Scheduler::get_status();
        } elseif (class_exists('AISS\SimpleScheduler')) {
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
        if ($hook !== 'settings_page_ai-social-share') { return; }
        
        // Enhanced CSS
        echo <<<'CSS'
        <style>
        :root {
            --aiss-primary: #2563eb; --aiss-primary-hover: #1d4ed8;
            --aiss-bg: #f3f4f6; --aiss-text: #111827; --aiss-muted: #6b7280; --aiss-border: #e5e7eb;
            --aiss-success-bg: #ecfdf5; --aiss-success-text: #059669; --aiss-success: #10b981;
            --aiss-error-bg: #fef2f2; --aiss-error-text: #b91c1c; --aiss-error: #ef4444;
            --aiss-warning-bg: #fffbeb; --aiss-warning-text: #d97706; --aiss-warning: #f59e0b;
        }
        .aiss-wrap { max-width: 1200px; margin: 20px auto; font-family: -apple-system, system-ui, sans-serif; color: var(--aiss-text); }
        .aiss-header { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; }
        .aiss-header h1 { margin: 0; font-size: 24px; font-weight: 700; }
        
        /* Tabs */
        .aiss-tabs { display: flex; gap: 4px; background: #fff; padding: 4px; border-radius: 10px; border: 1px solid var(--aiss-border); margin-bottom: 24px; width: fit-content; }
        .aiss-tab { padding: 8px 16px; border-radius: 6px; text-decoration: none; color: var(--aiss-muted); font-weight: 500; font-size: 14px; transition: all 0.2s; }
        .aiss-tab:hover { color: var(--aiss-text); background: #f3f4f6; }
        .aiss-tab-active { background: var(--aiss-primary); color: #fff !important; font-weight: 600; }

        /* Cards */
        .aiss-card { background: #fff; border: 1px solid var(--aiss-border); border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .aiss-card h2 { margin: 0 0 16px 0; font-size: 18px; font-weight: 600; }
        .aiss-card p.description { margin-top: 0; color: var(--aiss-muted); font-size: 14px; margin-bottom: 16px; line-height: 1.5; }

        /* Form Elements */
        .aiss-form-table { width: 100%; border-collapse: collapse; }
        .aiss-form-table th { text-align: left; padding: 12px 24px 12px 0; width: 220px; vertical-align: top; font-weight: 500; color: #374151; }
        .aiss-form-table td { padding: 8px 0; }
        .aiss-input, .aiss-select, .aiss-textarea { width: 100%; max-width: 500px; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
        .aiss-textarea { font-family: monospace; line-height: 1.5; min-height: 120px; }
        
        /* Buttons */
        .aiss-btn { display: inline-flex; align-items: center; padding: 10px 20px; border-radius: 6px; background: var(--aiss-primary); color: #fff; text-decoration: none; border: none; cursor: pointer; font-size: 14px; font-weight: 500; transition: background 0.2s; }
        .aiss-btn:hover { background: var(--aiss-primary-hover); color: #fff; }
        .aiss-btn-danger { background: #dc2626; } .aiss-btn-danger:hover { background: #b91c1c; }
        .aiss-btn-secondary { background: #fff; border: 1px solid #d1d5db; color: #374151; } .aiss-btn-secondary:hover { background: #f3f4f6; color: #111827; }
        .aiss-btn-black { background: #000; color: #fff; } .aiss-btn-black:hover { background: #333; color: #fff; }
        .aiss-btn-sm { padding: 6px 12px; font-size: 13px; }

        /* Status Grid */
        .aiss-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .aiss-stat-box { background: #fff; padding: 20px; border-radius: 10px; border: 1px solid var(--aiss-border); }
        .aiss-stat-label { font-size: 12px; color: var(--aiss-muted); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 6px; }
        .aiss-stat-value { font-size: 20px; font-weight: 700; display: flex; align-items: center; }
        .aiss-status-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 8px; }
        .dot-green { background: var(--aiss-success); box-shadow: 0 0 0 3px var(--aiss-success-bg); }
        .dot-red { background: var(--aiss-error); box-shadow: 0 0 0 3px var(--aiss-error-bg); } 
        .dot-yellow { background: var(--aiss-warning); box-shadow: 0 0 0 3px var(--aiss-warning-bg); }
        .dot-gray { background: #9ca3af; }

        /* Notices */
        .aiss-notice { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid; background: #fff; }
        .notice-success { border-color: var(--aiss-success); background: var(--aiss-success-bg); color: var(--aiss-success-text); }
        .notice-error { border-color: var(--aiss-error); background: var(--aiss-error-bg); color: var(--aiss-error-text); }
        .notice-warning { border-color: var(--aiss-warning); background: var(--aiss-warning-bg); color: var(--aiss-warning-text); }
        
        /* Debug Log */
        .aiss-debug-log { background: #1e1e1e; color: #d4d4d4; padding: 16px; border-radius: 8px; font-family: 'Courier New', monospace; font-size: 12px; max-height: 400px; overflow-y: auto; line-height: 1.6; }
        .aiss-debug-log .log-info { color: #4ec9b0; }
        .aiss-debug-log .log-warning { color: #dcdcaa; }
        .aiss-debug-log .log-error { color: #f48771; }
        
        /* Health Indicator */
        .aiss-health-badge { display: inline-flex; align-items: center; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .health-good { background: var(--aiss-success-bg); color: var(--aiss-success-text); }
        .health-bad { background: var(--aiss-error-bg); color: var(--aiss-error-text); }
        .health-warning { background: var(--aiss-warning-bg); color: var(--aiss-warning-text); }
        
        .settings_page_ai-social-share .notice:not(.aiss-notice) { display: none !important; }
        </style>
CSS;
    }

    public function register_settings() : void {
        register_setting('aiss_settings_group', 'aiss_settings', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default' => Utils::get_settings(),
        ]);
    }

    public function sanitize_settings($input) : array {
        $s = Utils::get_settings(); 
        $in = is_array($input) ? $input : [];

        // Detect which form is being saved by checking for unique fields from each tab
        $is_general_form = isset($in['openrouter_api_key']) || isset($in['openrouter_model']) || isset($in['post_language']);
        $is_facebook_form = isset($in['fb_app_id']) || isset($in['prompt_facebook']) || isset($in['fb_api_version']);
        $is_x_form = isset($in['x_consumer_key']) || isset($in['prompt_x']);
        $is_scheduler_form = isset($in['schedule_minutes']) || isset($in['max_posts_per_run']) || isset($in['filter_mode']);

        // FIX 1: Always update checkboxes for the form being saved
        // This ensures unchecked boxes properly clear the value
        
        // General Tab - Always update these checkboxes when saving from General
        if ($is_general_form) {
            $s['enable_web_search'] = isset($in['enable_web_search']);
            $s['enable_debug_logs'] = isset($in['enable_debug_logs']);
        }

        // Scheduler Tab - Always update share_on_publish when saving from Scheduler
        if ($is_scheduler_form) {
            $s['share_on_publish'] = isset($in['share_on_publish']);
        }
        
        // X Tab - Always update x_enabled when saving from X tab
        if ($is_x_form) {
            $s['x_enabled'] = isset($in['x_enabled']);
        }

        // General Settings
        if (isset($in['openrouter_api_key'])) $s['openrouter_api_key'] = trim($in['openrouter_api_key']);
        if (isset($in['openrouter_model'])) $s['openrouter_model'] = trim($in['openrouter_model']);
        if (isset($in['post_language'])) $s['post_language'] = sanitize_key($in['post_language']);

        // Scheduler Settings
        if (isset($in['schedule_minutes'])) {
            $old_minutes = (int)$s['schedule_minutes'];
            $new_minutes = max(5, min(1440, (int)$in['schedule_minutes']));
            $s['schedule_minutes'] = $new_minutes;
            
            // If schedule changed, force reschedule
            if ($old_minutes !== $new_minutes) {
                set_transient('aiss_force_reschedule', true, 60);
            }
        }
        if (isset($in['max_posts_per_run'])) $s['max_posts_per_run'] = max(1, min(20, (int)$in['max_posts_per_run']));
        if (isset($in['filter_mode'])) $s['filter_mode'] = sanitize_key($in['filter_mode']);
        if (isset($in['filter_terms'])) $s['filter_terms'] = sanitize_text_field($in['filter_terms']);
        
        // FIX 5: New filter for post age
        if (isset($in['filter_post_age'])) $s['filter_post_age'] = sanitize_key($in['filter_post_age']);

        // Facebook Settings
        if (isset($in['fb_app_id'])) $s['fb_app_id'] = sanitize_text_field($in['fb_app_id']);
        if (isset($in['fb_app_secret'])) $s['fb_app_secret'] = sanitize_text_field($in['fb_app_secret']);
        if (isset($in['fb_api_version'])) $s['fb_api_version'] = sanitize_text_field($in['fb_api_version']);
        if (isset($in['prompt_facebook'])) $s['prompt_facebook'] = wp_unslash($in['prompt_facebook']);

        // X Settings
        if (isset($in['x_consumer_key'])) $s['x_consumer_key'] = sanitize_text_field($in['x_consumer_key']);
        if (isset($in['x_consumer_secret'])) $s['x_consumer_secret'] = sanitize_text_field($in['x_consumer_secret']);
        if (isset($in['prompt_x'])) $s['prompt_x'] = wp_unslash($in['prompt_x']);

        return $s;
    }

    public function render() : void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized'); 
        
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'status';
        $settings = Utils::get_settings();
        
        echo '<div class="wrap aiss-wrap">';
        echo '<div class="aiss-header"><h1>AI Social Share</h1></div>';
        
        // Show notices
        if (isset($_GET['aiss_notice'])) {
            $notice = sanitize_key($_GET['aiss_notice']);
            $messages = [
                'saved' => ['Settings saved successfully', 'success'],
                'page_selected' => ['Facebook page connected', 'success'],
                'disconnected' => ['Disconnected successfully', 'success'],
                'x_connected' => ['X account connected successfully', 'success'],
                'x_error' => ['X connection failed: ' . (isset($_GET['aiss_error']) ? urldecode($_GET['aiss_error']) : 'Unknown error'), 'error'],
                'cron_run' => ['Cron executed successfully', 'success'],
                'cron_reset' => ['Cron schedule reset', 'success'],
                'log_cleared' => ['Debug log cleared', 'success'],
                'openrouter_ok' => ['OpenRouter connection successful', 'success'],
                'openrouter_error' => ['OpenRouter connection failed: ' . (isset($_GET['aiss_error']) ? urldecode($_GET['aiss_error']) : 'Unknown error'), 'error'],
            ];
            if (isset($messages[$notice])) {
                echo '<div class="aiss-notice notice-' . $messages[$notice][1] . '"><p>' . esc_html($messages[$notice][0]) . '</p></div>';
            }
        }
        
        // Tabs
        $tabs = [
            'status' => 'Status',
            'general' => 'General',
            'facebook' => 'Facebook',
            'x' => 'X / Twitter',
            'scheduler' => 'Scheduler',
            'debug' => 'Debug',
        ];
        
        echo '<div class="aiss-tabs">';
        foreach ($tabs as $key => $label) {
            $active = ($tab === $key) ? 'aiss-tab-active' : '';
            $url = Utils::admin_url_settings(['tab' => $key]);
            echo '<a href="' . esc_url($url) . '" class="aiss-tab ' . $active . '">' . esc_html($label) . '</a>';
        }
        echo '</div>';
        
        // Render active tab
        switch ($tab) {
            case 'general': $this->render_general($settings); break;
            case 'facebook': $this->render_facebook($settings); break;
            case 'x': $this->render_x($settings); break;
            case 'scheduler': $this->render_scheduler($settings); break;
            case 'debug': $this->render_debug($settings); break;
            default: $this->render_status($settings);
        }
        
        echo '</div>';
    }

    private function render_general($s) {
        echo '<form method="post" action="options.php">'; 
        settings_fields('aiss_settings_group');
        
        echo '<div class="aiss-card"><h2>OpenRouter AI</h2>';
        echo '<p class="description">Configure AI text generation.</p>';
        echo '<table class="aiss-form-table">';
        echo '<tr><th>API Key <span style="color:#dc2626">*</span></th><td>';
        echo '<input type="password" class="aiss-input" name="aiss_settings[openrouter_api_key]" value="' . esc_attr($s['openrouter_api_key']) . '" autocomplete="off">';
        echo '<br><small style="color:#6b7280">Get your API key from <a href="https://openrouter.ai/keys" target="_blank">OpenRouter</a></small>';
        echo '</td></tr>';
        echo '<tr><th>Model</th><td>';
        echo '<input type="text" class="aiss-input" name="aiss_settings[openrouter_model]" value="' . esc_attr($s['openrouter_model']) . '" placeholder="openai/gpt-4o-mini">';
        echo '<br><small style="color:#6b7280">Copy model ID from <a href="https://openrouter.ai/models" target="_blank">OpenRouter Models</a></small>';
        echo '</td></tr>';
        echo '<tr><th>Post Language</th><td>';
        echo '<select class="aiss-select" name="aiss_settings[post_language]">';
        $languages = [
            'en' => 'English', 'el' => 'Greek', 'es' => 'Spanish', 'fr' => 'French', 
            'de' => 'German', 'it' => 'Italian', 'pt' => 'Portuguese', 'ru' => 'Russian',
            'ar' => 'Arabic', 'zh' => 'Chinese', 'ja' => 'Japanese', 'ko' => 'Korean',
            'tr' => 'Turkish', 'nl' => 'Dutch', 'pl' => 'Polish',
        ];
        foreach ($languages as $code => $name) {
            echo '<option value="' . $code . '" ' . selected($s['post_language'] ?? 'en', $code, false) . '>' . $name . '</option>';
        }
        echo '</select>';
        echo '<br><small style="color:#6b7280">AI will write posts in this language</small>';
        echo '</td></tr>';
        echo '<tr><th>Web Search</th><td>';
        echo '<label><input type="checkbox" name="aiss_settings[enable_web_search]" value="1" ' . checked($s['enable_web_search'] ?? false, true, false) . '> ';
        echo 'Enable AI web search for context</label>';
        echo '<br><small style="color:#6b7280">Allows AI to search the web for additional context (may slow generation)</small>';
        echo '</td></tr>';
        echo '</table>';
        
        // Test Connection Button
        echo '<div style="margin-top:16px">';
        echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_test_openrouter'), 'aiss_test_openrouter') . '" class="aiss-btn aiss-btn-secondary">Test Connection</a>';
        echo '</div>';
        
        echo '</div>';
        
        echo '<button type="submit" class="aiss-btn">Save General Settings</button>';
        echo '</form>';
    }

    private function render_facebook($s) {
        $is_connected = $this->facebook->is_connected();
        $pages_transient = get_transient('aiss_fb_pages_' . get_current_user_id());
        
        echo '<form method="post" action="options.php">'; settings_fields('aiss_settings_group');
        
        echo '<div class="aiss-card"><h2>Facebook App Credentials</h2>';
        echo '<p class="description">Create a Facebook App at <a href="https://developers.facebook.com/apps" target="_blank">Facebook Developers</a>.</p>';
        echo '<table class="aiss-form-table">';
        echo '<tr><th>App ID</th><td><input class="aiss-input" name="aiss_settings[fb_app_id]" value="' . esc_attr($s['fb_app_id']) . '"></td></tr>';
        echo '<tr><th>App Secret</th><td><input type="password" class="aiss-input" name="aiss_settings[fb_app_secret]" value="' . esc_attr($s['fb_app_secret']) . '" autocomplete="off"></td></tr>';
        echo '<tr><th>API Version</th><td><input class="aiss-input" name="aiss_settings[fb_api_version]" value="' . esc_attr($s['fb_api_version']) . '" placeholder="v24.0" style="width:150px"></td></tr>';
        echo '</table></div>';
        
        // Connection Status & Control
        if ($is_connected) {
            echo '<div class="aiss-card" style="background:var(--aiss-success-bg); border-color:var(--aiss-success)">';
            echo '<h2 style="color:var(--aiss-success-text)">✓ Connected to Facebook</h2>';
            echo '<p><strong>Page:</strong> ' . esc_html($s['fb_page_name']) . ' (ID: ' . esc_html($s['fb_page_id']) . ')</p>';
            echo '<p><strong>Connected:</strong> ' . date_i18n('M j, Y g:i a', $s['fb_connected_at']) . '</p>';
            echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_disconnect'), 'aiss_fb_disconnect') . '" class="aiss-btn aiss-btn-danger">Disconnect</a>';
            echo '</div>';
        } elseif ($pages_transient) {
            echo '<div class="aiss-card"><h2>Select Facebook Page</h2>';
            echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
            wp_nonce_field('aiss_fb_select_page', 'aiss_fb_select_page_nonce');
            echo '<input type="hidden" name="action" value="aiss_fb_select_page">';
            echo '<select class="aiss-select" name="page_id" style="margin-bottom:12px">';
            foreach ($pages_transient as $page) {
                echo '<option value="' . esc_attr($page['id']) . '">' . esc_html($page['name']) . '</option>';
            }
            echo '</select>';
            echo '<br><button type="submit" class="aiss-btn">Connect Page</button>';
            echo '</form></div>';
        } else {
            echo '<div class="aiss-card">';
            echo '<h2>Connect Facebook Page</h2>';
            echo '<p>Click below to authenticate and select a Facebook page.</p>';
            echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_start_connect'), 'aiss_fb_connect') . '" class="aiss-btn">Connect Facebook</a>';
            echo '</div>';
        }
        
        // AI Prompt
        echo '<div class="aiss-card"><h2>Facebook Post Prompt</h2>';
        echo '<p class="description">Instructions for AI when generating Facebook posts. Use <code>{language}</code> placeholder for language.</p>';
        echo '<textarea class="aiss-textarea" name="aiss_settings[prompt_facebook]" rows="15">' . esc_textarea($s['prompt_facebook']) . '</textarea>';
        echo '</div>';
        
        echo '<button type="submit" class="aiss-btn">Save Facebook Settings</button></form>';
    }

    private function render_x($s) {
        // FIX 2: Improved X connection status check
        $is_connected = $this->x->is_connected();
        
        echo '<form method="post" action="options.php">'; 
        settings_fields('aiss_settings_group');
        
        echo '<div class="aiss-card"><h2>X (Twitter) App Credentials</h2>';
        echo '<p class="description">Create an X App at <a href="https://developer.twitter.com/en/apps" target="_blank">X Developer Portal</a>. Enable OAuth 1.0a.</p>';
        echo '<table class="aiss-form-table">';
        echo '<tr><th>Enable X Sharing</th><td>';
        echo '<label><input type="checkbox" name="aiss_settings[x_enabled]" value="1" ' . checked($s['x_enabled'] ?? false, true, false) . '> ';
        echo 'Enable X (Twitter) integration</label>';
        echo '</td></tr>';
        echo '<tr><th>API Key (Consumer Key)</th><td><input class="aiss-input" name="aiss_settings[x_consumer_key]" value="' . esc_attr($s['x_consumer_key']) . '"></td></tr>';
        echo '<tr><th>API Secret (Consumer Secret)</th><td><input type="password" class="aiss-input" name="aiss_settings[x_consumer_secret]" value="' . esc_attr($s['x_consumer_secret']) . '" autocomplete="off"></td></tr>';
        echo '</table></div>';
        
        // Connection Status with improved display
        if ($is_connected) {
            echo '<div class="aiss-card" style="background:var(--aiss-success-bg); border-color:var(--aiss-success)">';
            echo '<h2 style="color:var(--aiss-success-text)">✓ Connected to X</h2>';
            echo '<p><strong>Account:</strong> @' . esc_html($s['x_username'] ?? 'Connected') . '</p>';
            echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_x_disconnect'), 'aiss_x_disconnect') . '" class="aiss-btn aiss-btn-danger">Disconnect</a>';
            echo '</div>';
        } else {
            echo '<div class="aiss-card">';
            echo '<h2>Connect X Account</h2>';
            if (empty($s['x_enabled'])) {
                echo '<p style="color:var(--aiss-warning-text)">Enable X integration above and save settings first.</p>';
            } elseif (empty($s['x_consumer_key']) || empty($s['x_consumer_secret'])) {
                echo '<p style="color:var(--aiss-warning-text)">Enter your API credentials above and save settings first.</p>';
            } else {
                echo '<p>Click below to authenticate your X account.</p>';
                echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_x_connect'), 'aiss_x_connect') . '" class="aiss-btn aiss-btn-black">Connect X Account</a>';
            }
            echo '</div>';
        }
        
        // AI Prompt
        echo '<div class="aiss-card"><h2>X Post Prompt</h2>';
        echo '<p class="description">Instructions for AI when generating X posts. Use <code>{language}</code> placeholder for language.</p>';
        echo '<textarea class="aiss-textarea" name="aiss_settings[prompt_x]" rows="15">' . esc_textarea($s['prompt_x']) . '</textarea>';
        echo '</div>';
        
        echo '<button type="submit" class="aiss-btn">Save X Settings</button></form>';
    }

    private function render_scheduler($s) {
        echo '<form method="post" action="options.php">'; 
        settings_fields('aiss_settings_group');
        
        echo '<div class="aiss-card"><h2>Sharing Methods</h2>';
        echo '<p class="description">Choose how posts are shared to social media.</p>';
        echo '<table class="aiss-form-table">';
        echo '<tr><th>Share on Publish</th><td>';
        echo '<label><input type="checkbox" name="aiss_settings[share_on_publish]" value="1" ' . checked($s['share_on_publish'] ?? false, true, false) . '> ';
        echo '<strong>Share immediately when publishing posts</strong></label>';
        echo '<br><small style="color:#6b7280">Recommended! Posts share instantly when you click "Publish" - no waiting for cron.</small>';
        echo '</td></tr>';
        echo '</table></div>';
        
        echo '<div class="aiss-card"><h2>Background Schedule</h2>';
        echo '<p class="description">For sharing existing posts that weren\'t shared yet.</p>';
        echo '<table class="aiss-form-table">';
        echo '<tr><th>Check Every</th><td>';
        echo '<input type="number" class="aiss-input" name="aiss_settings[schedule_minutes]" value="' . (int)$s['schedule_minutes'] . '" min="5" max="1440" style="width:100px"> minutes';
        echo '<br><small style="color:#6b7280">Background task checks for unshared posts every X minutes</small>';
        echo '</td></tr>';
        echo '<tr><th>Posts per Batch</th><td>';
        echo '<input type="number" class="aiss-input" name="aiss_settings[max_posts_per_run]" value="' . (int)$s['max_posts_per_run'] . '" min="1" max="20" style="width:100px">';
        echo '<br><small style="color:#6b7280">How many posts to process each time</small>';
        echo '</td></tr>';
        echo '</table></div>';
        
        echo '<div class="aiss-card"><h2>Filters</h2>';
        echo '<p class="description">Limit which posts are auto-shared.</p>';
        echo '<table class="aiss-form-table">';
        
        // FIX 5: Added Post Age Filter
        echo '<tr><th>Post Age</th><td>';
        echo '<select class="aiss-select" name="aiss_settings[filter_post_age]">';
        $age_options = [
            '24h' => 'Last 24 Hours',
            '48h' => 'Last 48 Hours',
            '7d' => 'Last 7 Days',
            '30d' => 'Last 30 Days',
            'all' => 'All Posts',
        ];
        foreach ($age_options as $value => $label) {
            echo '<option value="' . $value . '" ' . selected($s['filter_post_age'] ?? '7d', $value, false) . '>' . $label . '</option>';
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
        echo '<br><small style="color:#6b7280">Comma-separated category/tag slugs</small>';
        echo '</td></tr>';
        echo '</table></div>';
        
        echo '<button type="submit" class="aiss-btn">Save Schedule Settings</button></form>';
    }

    private function render_status($s) {
        $fb = $this->facebook->is_connected();
        $x = $this->x->is_connected();
        
        // Get status from whichever scheduler is loaded
        if (class_exists('AISS\Scheduler')) {
            $status = \AISS\Scheduler::get_status();
        } elseif (class_exists('AISS\SimpleScheduler')) {
            $status = \AISS\SimpleScheduler::get_status();
        } else {
            $status = ['active' => false, 'next_run' => null, 'last_run' => 0];
        }
        
        $nxt = $status['next_run'];
        $last_run = $status['last_run'];
        $stats = get_option('aiss_last_run_stats', []);
        
        // Auto-fix: If not scheduled OR overdue, reschedule
        $is_overdue = ($nxt && $nxt < time());
        if (!$nxt || $is_overdue || !$status['active'] || get_transient('aiss_force_reschedule')) {
            if (method_exists($this->scheduler, 'ensure_scheduled')) {
                $this->scheduler->ensure_scheduled();
            }
            delete_transient('aiss_force_reschedule');
            // Refresh status
            if (class_exists('AISS\Scheduler')) {
                $status = \AISS\Scheduler::get_status();
            } elseif (class_exists('AISS\SimpleScheduler')) {
                $status = \AISS\SimpleScheduler::get_status();
            }
            $nxt = $status['next_run'];
        }
        
        // Health Badge
        $health_class = ($status['active'] && $nxt && $nxt >= time()) ? 'health-good' : 'health-bad';
        $health_text = ($status['active'] && $nxt && $nxt >= time()) ? 'Healthy' : 'Issues Detected';
        
        echo '<div style="margin-bottom:24px">';
        echo '<span class="aiss-health-badge ' . $health_class . '">' . $health_text . '</span>';
        echo '</div>';
        
        // Stats Grid
        echo '<div class="aiss-stats-grid">';
        
        echo '<div class="aiss-stat-box">';
        echo '<div class="aiss-stat-label">Facebook</div>';
        echo '<div class="aiss-stat-value"><span class="aiss-status-dot ' . ($fb ? 'dot-green' : 'dot-red') . '"></span>' . ($fb ? 'Connected' : 'Offline') . '</div>';
        echo '</div>';
        
        echo '<div class="aiss-stat-box">';
        echo '<div class="aiss-stat-label">X (Twitter)</div>';
        // FIX 2: Better status display for X
        $x_status_class = $x ? 'dot-green' : 'dot-gray';
        $x_status_text = $x ? 'Connected' : ($s['x_enabled'] ? 'Not Connected' : 'Disabled');
        echo '<div class="aiss-stat-value"><span class="aiss-status-dot ' . $x_status_class . '"></span>' . $x_status_text . '</div>';
        echo '</div>';
        
        echo '<div class="aiss-stat-box">';
        echo '<div class="aiss-stat-label">Next Run</div>';
        // FIX 6: Fixed negative time display
        if ($nxt && $nxt > time()) {
            $time_until = human_time_diff(time(), $nxt);
            echo '<div class="aiss-stat-value">In ' . $time_until . '</div>';
            echo '<div style="font-size:11px; color:var(--aiss-muted); margin-top:4px">' . date_i18n('M j, g:i a', $nxt) . '</div>';
        } elseif ($nxt) {
            echo '<div class="aiss-stat-value" style="color:var(--aiss-error)">Overdue</div>';
        } else {
            echo '<div class="aiss-stat-value" style="color:var(--aiss-muted)">Not Scheduled</div>';
        }
        echo '</div>';
        
        echo '<div class="aiss-stat-box">';
        echo '<div class="aiss-stat-label">Last Run</div>';
        echo '<div class="aiss-stat-value">' . ($last_run ? Utils::format_time_ago($last_run) : 'Never') . '</div>';
        echo '</div>';
        
        echo '</div>';
        
        // System Overview
        echo '<div class="aiss-card"><h2>System Overview</h2>';
        echo '<table class="aiss-form-table">';
        echo '<tr><th>Cron Schedule</th><td>Every ' . (int)$s['schedule_minutes'] . ' minutes</td></tr>';
        echo '<tr><th>Batch Size</th><td>' . (int)$s['max_posts_per_run'] . ' posts per run</td></tr>';
        echo '<tr><th>Filter</th><td>' . ucfirst($s['filter_mode']);
        if ($s['filter_mode'] !== 'all' && !empty($s['filter_terms'])) {
            echo ': ' . esc_html($s['filter_terms']);
        }
        echo '</td></tr>';
        
        if (!empty($stats)) {
            echo '<tr><th>Last Run Stats</th><td>';
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
        echo '</div>';
        echo '</div>';
        
        // System Information
        $info = Utils::get_plugin_info();
        echo '<div class="aiss-card"><h2>System Information</h2>';
        echo '<table class="aiss-form-table">';
        echo '<tr><th>Plugin Version</th><td>' . AISS_VERSION . '</td></tr>';
        echo '<tr><th>WordPress</th><td>' . $info['wp_version'] . '</td></tr>';
        echo '<tr><th>PHP</th><td>' . $info['php_version'] . '</td></tr>';
        echo '<tr><th>WP-Cron</th><td>' . ($info['wp_cron_enabled'] ? '✓ Enabled' : '✗ Disabled') . '</td></tr>';
        echo '</table></div>';
    }

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
                if (strpos($line, '[error]') !== false) $class = 'log-error';
                elseif (strpos($line, '[warning]') !== false) $class = 'log-warning';
                
                echo '<div class="' . $class . '">' . esc_html($line) . '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
        
        // Prompt Preview
        echo '<div class="aiss-card"><h2>Active Prompts</h2>';
        echo '<p class="description">These are the prompts currently being sent to AI (with language setting applied).</p>';
        
        $language = $this->get_language_display($s['post_language'] ?? 'en');
        
        echo '<div style="margin-bottom:20px">';
        echo '<h3 style="font-size:14px; margin:0 0 8px 0; color:#374151">Facebook Prompt:</h3>';
        echo '<div style="background:#f9fafb; padding:12px; border-radius:6px; font-family:monospace; font-size:12px; white-space:pre-wrap; border:1px solid #e5e7eb">';
        echo esc_html(str_replace('{language}', $language, $s['prompt_facebook']));
        echo '</div></div>';
        
        echo '<div>';
        echo '<h3 style="font-size:14px; margin:0 0 8px 0; color:#374151">X/Twitter Prompt:</h3>';
        echo '<div style="background:#f9fafb; padding:12px; border-radius:6px; font-family:monospace; font-size:12px; white-space:pre-wrap; border:1px solid #e5e7eb">';
        echo esc_html(str_replace('{language}', $language, $s['prompt_x']));
        echo '</div></div>';
        echo '</div>';
        
        // Quick Actions
        echo '<div class="aiss-card"><h2>Quick Actions</h2>';
        echo '<p class="description">Debug tools for testing and troubleshooting.</p>';
        echo '<div style="display:flex; gap:12px; margin-top:16px">';
        echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_run_cron'), 'aiss_run_cron') . '" class="aiss-btn aiss-btn-secondary">Run Cron</a>';
        echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_reset_cron'), 'aiss_reset_cron') . '" class="aiss-btn aiss-btn-secondary">Reset Cron</a>';
        echo '</div></div>';
    }
    
    private function get_language_display($code) {
        $languages = [
            'en' => 'English', 'el' => 'Greek', 'es' => 'Spanish', 'fr' => 'French',
            'de' => 'German', 'it' => 'Italian', 'pt' => 'Portuguese', 'ru' => 'Russian',
            'ar' => 'Arabic', 'zh' => 'Chinese', 'ja' => 'Japanese', 'ko' => 'Korean',
            'tr' => 'Turkish', 'nl' => 'Dutch', 'pl' => 'Polish',
        ];
        return $languages[$code] ?? 'English';
    }

    // --- Action Handlers ---
    
    public function start_fb_connect() { 
        check_admin_referer('aiss_fb_connect'); 
        wp_redirect($this->facebook->build_oauth_url()); 
        exit; 
    }
    
    public function select_fb_page() { 
        check_admin_referer('aiss_fb_select_page', 'aiss_fb_select_page_nonce'); 
        $this->facebook->select_page_and_store($_POST['page_id'] ?? ''); 
        delete_transient('aiss_fb_pages_' . get_current_user_id()); 
        wp_redirect(Utils::admin_url_settings(['tab' => 'facebook', 'aiss_notice' => 'page_selected'])); 
        exit; 
    }
    
    public function disconnect_facebook() { 
        check_admin_referer('aiss_fb_disconnect'); 
        $s = Utils::get_settings(); 
        $s['fb_page_id'] = '';
        $s['fb_page_name'] = '';
        $s['fb_page_token'] = '';
        Utils::update_settings($s); 
        wp_redirect(Utils::admin_url_settings(['tab' => 'facebook', 'aiss_notice' => 'disconnected'])); 
        exit; 
    }

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
            $s['x_access_token'] = $res['token'];
            $s['x_access_secret'] = $res['secret'];
            $s['x_username'] = $res['screen_name'];
            $s['x_enabled'] = true; // FIX 2: Auto-enable X when successfully connected
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
        $s['x_access_token'] = ''; 
        $s['x_access_secret'] = ''; 
        $s['x_username'] = ''; 
        Utils::update_settings($s); 
        wp_redirect(Utils::admin_url_settings(['tab' => 'x', 'aiss_notice' => 'disconnected'])); 
        exit;
    }

    public function run_cron_manually() {
        check_admin_referer('aiss_run_cron'); 
        $this->scheduler->process_queue();
        wp_redirect(Utils::admin_url_settings(['tab' => 'status', 'aiss_notice' => 'cron_run'])); 
        exit;
    }
    
    public function reset_cron() {
        check_admin_referer('aiss_reset_cron');
        if (method_exists($this->scheduler, 'clear_scheduled')) {
            $this->scheduler->clear_scheduled();
        }
        if (method_exists($this->scheduler, 'ensure_scheduled')) {
            $this->scheduler->ensure_scheduled(true);
        }
        wp_redirect(Utils::admin_url_settings(['tab' => 'status', 'aiss_notice' => 'cron_reset'])); 
        exit;
    }
    
    public function clear_log() {
        check_admin_referer('aiss_clear_log');
        // Use static method from whichever scheduler class is loaded
        if (class_exists('AISS\Scheduler')) {
            \AISS\Scheduler::clear_log();
        } elseif (class_exists('AISS\SimpleScheduler')) {
            \AISS\SimpleScheduler::clear_log();
        }
        wp_redirect(Utils::admin_url_settings(['tab' => 'debug', 'aiss_notice' => 'log_cleared'])); 
        exit;
    }
    
    // FIX 3: Test OpenRouter Connection
    public function test_openrouter() {
        check_admin_referer('aiss_test_openrouter');
        
        $openrouter = new OpenRouter();
        $result = $openrouter->test_connection();
        
        if ($result['ok']) {
            wp_redirect(Utils::admin_url_settings(['tab' => 'general', 'aiss_notice' => 'openrouter_ok']));
        } else {
            wp_redirect(Utils::admin_url_settings(['tab' => 'general', 'aiss_notice' => 'openrouter_error', 'aiss_error' => urlencode($result['error'])]));
        }
        exit;
    }
}