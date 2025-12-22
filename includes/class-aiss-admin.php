<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Admin {

    /** @var Cron */
    private $cron;

    /** @var Facebook */
    private $facebook;

    public function __construct(Cron $cron, Facebook $facebook) {
        $this->cron = $cron;
        $this->facebook = $facebook;

        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_aiss_fb_start_connect', [$this, 'start_fb_connect']);
        add_action('admin_post_aiss_fb_select_page', [$this, 'select_fb_page']);
        add_action('admin_post_aiss_fb_disconnect', [$this, 'disconnect_facebook']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function menu() : void {
        add_options_page(
            __('AI Social Share', 'ai-social-share'),
            __('AI Social Share', 'ai-social-share'),
            'manage_options',
            'ai-social-share',
            [$this, 'render']
        );
    }

    public function enqueue_admin_assets($hook) : void {
        if ($hook !== 'settings_page_ai-social-share') { return; }
        
        // Inline modern styles
        echo <<<'CSS'
        <style>
        /* Modern AI Social Share Styles */
        .aiss-wrap { max-width: 1400px; margin: 20px auto; padding: 0 20px; }
        .aiss-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; padding: 32px; border-radius: 12px; margin-bottom: 32px; box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3); }
        .aiss-header h1 { font-size: 32px; font-weight: 700; margin: 0 0 8px 0; display: flex; align-items: center; gap: 12px; color: #fff; }
        .aiss-header p { margin: 0; opacity: 0.9; font-size: 16px; }
        .aiss-tabs { display: flex; gap: 8px; margin-bottom: 32px; border-bottom: 2px solid #e5e7eb; padding-bottom: 0; }
        .aiss-tab { padding: 12px 24px; background: transparent; border: none; border-bottom: 3px solid transparent; color: #6b7280; text-decoration: none; font-weight: 600; font-size: 15px; transition: all 0.3s ease; cursor: pointer; position: relative; top: 2px; }
        .aiss-tab:hover { color: #667eea; background: #f9fafb; }
        .aiss-tab-active { color: #667eea; border-bottom-color: #667eea; background: #f9fafb; }
        .aiss-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 32px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: all 0.3s ease; }
        .aiss-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .aiss-card h2 { margin: 0 0 8px 0; font-size: 22px; font-weight: 700; color: #1f2937; display: flex; align-items: center; gap: 12px; }
        .aiss-card-subtitle { color: #6b7280; font-size: 14px; margin-bottom: 24px; line-height: 1.6; }
        .aiss-status-banner { padding: 20px 24px; border-radius: 10px; margin-bottom: 24px; display: flex; align-items: center; gap: 16px; border-left: 4px solid; }
        .aiss-status-success { background: #d1fae5; border-color: #10b981; color: #065f46; }
        .aiss-status-warning { background: #fef3c7; border-color: #f59e0b; color: #92400e; }
        .aiss-status-error { background: #fee2e2; border-color: #ef4444; color: #991b1b; }
        .aiss-status-icon { font-size: 28px; line-height: 1; }
        .aiss-status-content h3 { margin: 0 0 4px 0; font-size: 16px; font-weight: 700; }
        .aiss-status-content p { margin: 0; font-size: 14px; opacity: 0.9; }
        .aiss-info-grid { display: grid; gap: 16px; margin: 24px 0; }
        .aiss-info-row { display: grid; grid-template-columns: 200px 1fr; gap: 16px; padding: 16px; background: #f9fafb; border-radius: 8px; align-items: center; }
        .aiss-info-label { font-weight: 600; color: #374151; font-size: 14px; }
        .aiss-info-value { color: #6b7280; font-size: 14px; font-family: 'SF Mono', Monaco, 'Courier New', monospace; }
        .aiss-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 24px 0; }
        .aiss-stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; padding: 28px; border-radius: 12px; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3); }
        .aiss-stat-card.green { background: linear-gradient(135deg, #10b981 0%, #059669 100%); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
        .aiss-stat-card.orange { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3); }
        .aiss-stat-label { font-size: 13px; opacity: 0.95; margin-bottom: 8px; font-weight: 500; }
        .aiss-stat-value { font-size: 32px; font-weight: 700; line-height: 1; }
        .aiss-button-group { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 24px; }
        .aiss-btn { padding: 12px 24px; border-radius: 8px; font-weight: 600; font-size: 14px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s ease; border: 2px solid transparent; cursor: pointer; }
        .aiss-btn-primary { background: #667eea; color: #fff; box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3); }
        .aiss-btn-primary:hover { background: #5a67d8; color: #fff; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4); }
        .aiss-btn-secondary { background: #fff; color: #667eea; border-color: #667eea; }
        .aiss-btn-secondary:hover { background: #667eea; color: #fff; }
        .aiss-btn-danger { background: #fff; color: #ef4444; border-color: #ef4444; }
        .aiss-btn-danger:hover { background: #ef4444; color: #fff; }
        .aiss-code-block { background: #1e293b; color: #e2e8f0; padding: 16px 20px; border-radius: 8px; font-family: 'SF Mono', Monaco, 'Courier New', monospace; font-size: 13px; word-break: break-all; margin: 16px 0; border: 2px solid #334155; }
        .aiss-help-text { font-size: 13px; color: #6b7280; margin-top: 8px; line-height: 1.6; }
        .aiss-notice { padding: 16px 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .aiss-notice-success { background: #d1fae5; border-color: #10b981; color: #065f46; }
        .aiss-notice-error { background: #fee2e2; border-color: #ef4444; color: #991b1b; }
        .aiss-notice p { margin: 0; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .aiss-page-selector { background: #f9fafb; padding: 24px; border-radius: 10px; border: 2px solid #e5e7eb; margin: 24px 0; }
        .aiss-page-selector select { width: 100%; max-width: 500px; padding: 12px 16px; border-radius: 8px; border: 2px solid #d1d5db; font-size: 14px; background: #fff; margin-bottom: 16px; }
        .aiss-form-table { width: 100%; margin: 24px 0; }
        .aiss-form-table th { text-align: left; padding: 16px 16px 16px 0; font-weight: 600; color: #374151; width: 250px; vertical-align: top; font-size: 14px; }
        .aiss-form-table td { padding: 16px 0; }
        .aiss-form-table input[type="text"], .aiss-form-table input[type="password"], .aiss-form-table input[type="url"], .aiss-form-table input[type="number"], .aiss-form-table select, .aiss-form-table textarea { width: 100%; max-width: 600px; padding: 10px 14px; border: 2px solid #d1d5db; border-radius: 8px; font-size: 14px; transition: all 0.2s ease; }
        .aiss-form-table input:focus, .aiss-form-table select:focus, .aiss-form-table textarea:focus { border-color: #667eea; outline: none; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
        .aiss-form-table textarea { font-family: 'SF Mono', Monaco, 'Courier New', monospace; line-height: 1.6; }
        .aiss-submit-btn { background: #667eea; color: #fff; border: none; padding: 14px 32px; border-radius: 8px; font-weight: 600; font-size: 15px; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3); }
        .aiss-submit-btn:hover { background: #5a67d8; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4); }
        .aiss-radio-group label { display: block; padding: 10px 0; font-size: 14px; color: #374151; }
        .aiss-radio-group input[type="radio"] { margin-right: 8px; }
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

        if (isset($in['openrouter_api_key'])) {
            $s['openrouter_api_key'] = trim((string)$in['openrouter_api_key']);
        }
        if (isset($in['openrouter_model'])) {
            $s['openrouter_model'] = trim((string)$in['openrouter_model']);
        }
        if (isset($in['openrouter_site'])) {
            $s['openrouter_site'] = esc_url_raw((string)$in['openrouter_site']);
        }
        if (isset($in['openrouter_app'])) {
            $s['openrouter_app'] = sanitize_text_field((string)$in['openrouter_app']);
        }

        if (isset($in['schedule_minutes'])) {
            $s['schedule_minutes'] = max(5, (int)$in['schedule_minutes']);
        }
        if (isset($in['max_posts_per_run'])) {
            $s['max_posts_per_run'] = max(1, (int)$in['max_posts_per_run']);
        }

        if (isset($in['filter_mode'])) {
            $mode = (string)$in['filter_mode'];
            $s['filter_mode'] = in_array($mode, ['all','category','tag'], true) ? $mode : 'all';
        }
        if (isset($in['filter_terms'])) {
            $s['filter_terms'] = sanitize_text_field((string)$in['filter_terms']);
        }

        if (isset($in['fb_app_id'])) {
            $s['fb_app_id'] = sanitize_text_field((string)$in['fb_app_id']);
        }
        if (isset($in['fb_app_secret'])) {
            $s['fb_app_secret'] = sanitize_text_field((string)$in['fb_app_secret']);
        }
        if (isset($in['fb_api_version'])) {
            $v = sanitize_text_field((string)$in['fb_api_version']);
            $s['fb_api_version'] = $v !== '' ? $v : 'v24.0';
        }

        if (isset($in['prompt_facebook'])) {
            $s['prompt_facebook'] = (string)$in['prompt_facebook'];
        }

        return $s;
    }

    public function render() : void {
        if (!current_user_can('manage_options')) { return; }

        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        if (!in_array($tab, ['general','facebook','scheduler','status'], true)) { $tab = 'general'; }

        $settings = Utils::get_settings();
        $notices = [];

        if (isset($_GET['aiss_notice'])) {
            $notice_type = sanitize_key($_GET['aiss_notice']);
            if ($notice_type === 'page_selected') {
                $notices[] = ['type' => 'success', 'msg' => '✓ Facebook Page connected successfully! Your posts will now be automatically shared.'];
            } elseif ($notice_type === 'page_error') {
                $error_msg = isset($_GET['aiss_error']) ? urldecode((string)$_GET['aiss_error']) : 'Unknown error';
                $notices[] = ['type' => 'error', 'msg' => '✗ Connection Error: ' . $error_msg];
            } elseif ($notice_type === 'disconnected') {
                $notices[] = ['type' => 'success', 'msg' => '✓ Facebook Page disconnected successfully.'];
            }
        }

        if ($tab === 'facebook' && isset($_GET['aiss_fb_cb']) && isset($_GET['code']) && isset($_GET['state'])) {
            $code  = sanitize_text_field((string)$_GET['code']);
            $state = sanitize_text_field((string)$_GET['state']);
            $result = $this->facebook->handle_callback_and_fetch_pages($code, $state);
            if ($result['ok']) {
                set_transient('aiss_fb_pages_' . get_current_user_id(), $result['pages'], 20 * MINUTE_IN_SECONDS);
                $notices[] = ['type' => 'success', 'msg' => '✓ Successfully authorized with Facebook! Please select a Page below to complete the setup.'];
            } else {
                $notices[] = ['type' => 'error', 'msg' => '✗ Authorization failed: ' . $result['error']];
            }
        }

        $pages = get_transient('aiss_fb_pages_' . get_current_user_id());
        if (!is_array($pages)) { $pages = []; }

        echo '<div class="wrap aiss-wrap">';
        echo '<div class="aiss-header">';
        echo '<h1><span class="dashicons dashicons-share" style="font-size:40px;"></span> AI Social Share</h1>';
        echo '<p>Automatically generate and share your WordPress posts to Facebook using AI</p>';
        echo '</div>';

        foreach ($notices as $n) {
            $class = $n['type'] === 'success' ? 'aiss-notice aiss-notice-success' : 'aiss-notice aiss-notice-error';
            $icon = $n['type'] === 'success' ? '✓' : '✗';
            echo '<div class="' . esc_attr($class) . '"><p><strong>' . $icon . '</strong> ' . esc_html($n['msg']) . '</p></div>';
        }

        echo '<div class="aiss-tabs">';
        $this->render_tab_link('general', '⚙️ General', $tab);
        $this->render_tab_link('facebook', '📘 Facebook', $tab);
        $this->render_tab_link('scheduler', '⏰ Scheduler', $tab);
        $this->render_tab_link('status', '📊 Status', $tab);
        echo '</div>';

        if ($tab === 'general') {
            $this->render_general($settings);
        } elseif ($tab === 'facebook') {
            $this->render_facebook($settings, $pages);
        } elseif ($tab === 'scheduler') {
            $this->render_scheduler($settings);
        } else {
            $this->render_status($settings);
        }

        echo '</div>';
    }

    private function render_tab_link(string $key, string $label, string $current) : void {
        $url = Utils::admin_url_settings(['tab' => $key]);
        $cls = 'aiss-tab' . ($current === $key ? ' aiss-tab-active' : '');
        echo '<a class="' . esc_attr($cls) . '" href="' . esc_url($url) . '">' . $label . '</a>';
    }

    private function render_general(array $settings) : void {
        echo '<form method="post" action="options.php">';
        settings_fields('aiss_settings_group');

        echo '<div class="aiss-card">';
        echo '<h2>🤖 OpenRouter API Configuration</h2>';
        echo '<p class="aiss-card-subtitle">OpenRouter provides access to multiple AI models (GPT-4, Claude, Gemini) for generating engaging social media content.</p>';
        
        echo '<table class="aiss-form-table">';
        echo '<tr><th><label for="openrouter_api_key">API Key *</label></th><td>';
        echo '<input type="password" id="openrouter_api_key" name="aiss_settings[openrouter_api_key]" value="' . esc_attr($settings['openrouter_api_key']) . '" autocomplete="off" />';
        echo '<p class="aiss-help-text">Get your free API key from <a href="https://openrouter.ai/keys" target="_blank">openrouter.ai/keys</a></p>';
        echo '</td></tr>';

        echo '<tr><th><label for="openrouter_model">AI Model</label></th><td>';
        echo '<select id="openrouter_model" name="aiss_settings[openrouter_model]">';
        $models = [
            'openai/gpt-4o-mini' => 'GPT-4o Mini (Fast & Affordable)',
            'openai/gpt-4o' => 'GPT-4o (Best Quality)',
            'anthropic/claude-3.5-sonnet' => 'Claude 3.5 Sonnet (Smart)',
            'google/gemini-pro' => 'Gemini Pro (Google)',
        ];
        foreach ($models as $value => $label) {
            $selected = $settings['openrouter_model'] === $value ? ' selected' : '';
            echo '<option value="' . esc_attr($value) . '"' . $selected . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</td></tr>';

        echo '<tr><th><label for="openrouter_site">Site URL</label></th><td>';
        echo '<input type="url" id="openrouter_site" name="aiss_settings[openrouter_site]" value="' . esc_attr($settings['openrouter_site']) . '" />';
        echo '<p class="aiss-help-text">Optional: Your website URL for OpenRouter attribution</p>';
        echo '</td></tr>';

        echo '<tr><th><label for="openrouter_app">App Name</label></th><td>';
        echo '<input type="text" id="openrouter_app" name="aiss_settings[openrouter_app]" value="' . esc_attr($settings['openrouter_app']) . '" />';
        echo '<p class="aiss-help-text">Optional: Your app name for OpenRouter tracking</p>';
        echo '</td></tr>';
        echo '</table>';
        echo '</div>';

        echo '<div class="aiss-card">';
        echo '<h2>✍️ Facebook Post Template</h2>';
        echo '<p class="aiss-card-subtitle">Customize how AI generates your Facebook posts. This prompt guides the AI\'s writing style, tone, and format.</p>';
        echo '<textarea id="prompt_facebook" name="aiss_settings[prompt_facebook]" rows="14" style="width:100%;max-width:900px;font-family:\'SF Mono\',Monaco,monospace;font-size:13px;line-height:1.6;padding:16px;border:2px solid #d1d5db;border-radius:8px;">' . esc_textarea($settings['prompt_facebook']) . '</textarea>';
        echo '</div>';

        echo '<button type="submit" class="aiss-submit-btn">💾 Save Configuration</button>';
        echo '</form>';
    }

    private function render_facebook(array $settings, array $pages) : void {
        $connected = $this->facebook->is_connected();

        echo '<div class="aiss-card">';
        echo '<h2>📘 Facebook Connection</h2>';
        
        if ($connected) {
            echo '<div class="aiss-status-banner aiss-status-success">';
            echo '<div class="aiss-status-icon">✓</div>';
            echo '<div class="aiss-status-content">';
            echo '<h3>Connected to Facebook Page</h3>';
            echo '<p>' . esc_html($settings['fb_page_name']) . ' (ID: ' . esc_html($settings['fb_page_id']) . ')</p>';
            echo '</div>';
            echo '</div>';

            echo '<div class="aiss-info-grid">';
            echo '<div class="aiss-info-row">';
            echo '<div class="aiss-info-label">Page Name</div>';
            echo '<div class="aiss-info-value">' . esc_html($settings['fb_page_name']) . '</div>';
            echo '</div>';
            echo '<div class="aiss-info-row">';
            echo '<div class="aiss-info-label">Page ID</div>';
            echo '<div class="aiss-info-value">' . esc_html($settings['fb_page_id']) . '</div>';
            echo '</div>';
            if ($settings['fb_connected_at']) {
                echo '<div class="aiss-info-row">';
                echo '<div class="aiss-info-label">Connected Since</div>';
                echo '<div class="aiss-info-value">' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int)$settings['fb_connected_at'])) . '</div>';
                echo '</div>';
            }
            echo '</div>';

            $disconnect_url = wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_disconnect'), 'aiss_fb_disconnect');
            echo '<div class="aiss-button-group">';
            echo '<a href="' . esc_url($disconnect_url) . '" class="aiss-btn aiss-btn-danger" onclick="return confirm(\'⚠️ Are you sure you want to disconnect this Facebook Page? Automatic posting will stop.\');">🔌 Disconnect Facebook</a>';
            echo '</div>';
        } else {
            echo '<div class="aiss-status-banner aiss-status-warning">';
            echo '<div class="aiss-status-icon">⚠️</div>';
            echo '<div class="aiss-status-content">';
            echo '<h3>Facebook Not Connected</h3>';
            echo '<p>Connect your Facebook Page to start automatically sharing posts</p>';
            echo '</div>';
            echo '</div>';

            echo '<form method="post" action="options.php">';
            settings_fields('aiss_settings_group');

            echo '<table class="aiss-form-table">';
            echo '<tr><th><label for="fb_app_id">Facebook App ID *</label></th><td>';
            echo '<input type="text" id="fb_app_id" name="aiss_settings[fb_app_id]" value="' . esc_attr($settings['fb_app_id']) . '" placeholder="1234567890123456" />';
            echo '<p class="aiss-help-text">Your Facebook App ID from <a href="https://developers.facebook.com/apps" target="_blank">Facebook Developers</a></p>';
            echo '</td></tr>';

            echo '<tr><th><label for="fb_app_secret">Facebook App Secret *</label></th><td>';
            echo '<input type="password" id="fb_app_secret" name="aiss_settings[fb_app_secret]" value="' . esc_attr($settings['fb_app_secret']) . '" autocomplete="off" />';
            echo '<p class="aiss-help-text">Your Facebook App Secret (keep it secure)</p>';
            echo '</td></tr>';

            echo '<tr><th><label for="fb_api_version">Graph API Version</label></th><td>';
            echo '<input type="text" id="fb_api_version" name="aiss_settings[fb_api_version]" value="' . esc_attr($settings['fb_api_version']) . '" placeholder="v24.0" />';
            echo '<p class="aiss-help-text">Facebook Graph API version (e.g., v24.0)</p>';
            echo '</td></tr>';
            echo '</table>';

            echo '<button type="submit" class="aiss-submit-btn">💾 Save Facebook App Settings</button>';
            echo '</form>';

            echo '<hr style="margin:32px 0;border:none;border-top:2px solid #e5e7eb;">';

            if (empty($settings['fb_app_id']) || empty($settings['fb_app_secret'])) {
                echo '<div class="aiss-status-banner aiss-status-warning">';
                echo '<div class="aiss-status-icon">ℹ️</div>';
                echo '<div class="aiss-status-content">';
                echo '<h3>Setup Required</h3>';
                echo '<p>Please enter your Facebook App ID and Secret above, save the settings, then click Connect to Facebook below.</p>';
                echo '</div>';
                echo '</div>';
            } else {
                $connect_url = wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_start_connect'), 'aiss_fb_connect');
                echo '<div class="aiss-button-group">';
                echo '<a class="aiss-btn aiss-btn-primary" href="' . esc_url($connect_url) . '" style="font-size:16px;padding:16px 32px;">🔗 Connect to Facebook</a>';
                echo '</div>';
            }
        }
        echo '</div>';

        if (!empty($pages) && !$connected) {
            echo '<div class="aiss-card">';
            echo '<h2>📑 Select Your Facebook Page</h2>';
            echo '<p class="aiss-card-subtitle">Choose which Facebook Page you want to use for automatic posting.</p>';
            
            echo '<div class="aiss-page-selector">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="aiss_fb_select_page" />';
            wp_nonce_field('aiss_fb_select_page', 'aiss_fb_select_page_nonce');

            echo '<select name="page_id" required>';
            foreach ($pages as $p) {
                $id = isset($p['id']) ? (string)$p['id'] : '';
                $name = isset($p['name']) ? (string)$p['name'] : $id;
                echo '<option value="' . esc_attr($id) . '">' . esc_html($name) . ' (' . esc_html($id) . ')</option>';
            }
            echo '</select><br>';
            
            echo '<button type="submit" class="aiss-btn aiss-btn-primary">✓ Use This Page</button>';
            echo '</form>';
            echo '<p class="aiss-help-text" style="margin-top:16px;">After selecting, the page list will be cleared and your selection will be saved.</p>';
            echo '</div>';
            echo '</div>';
        }

        echo '<div class="aiss-card">';
        echo '<h2>🔐 OAuth Redirect URI Setup</h2>';
        echo '<p class="aiss-card-subtitle">Add this exact URL to your Facebook App\'s OAuth Redirect URIs to complete the connection.</p>';
        echo '<div class="aiss-code-block">' . esc_html($this->facebook->oauth_redirect_uri()) . '</div>';
        echo '<p class="aiss-help-text"><strong>Where to add it:</strong> Facebook App Dashboard → Use Cases → Manage Pages → Settings → Valid OAuth Redirect URIs</p>';
        echo '<p class="aiss-help-text"><strong>Important:</strong> Make sure to also add your domain (' . esc_html(parse_url(home_url(), PHP_URL_HOST)) . ') to the "App Domains" field in your Facebook App\'s Basic Settings.</p>';
        echo '</div>';
    }

    private function render_scheduler(array $settings) : void {
        echo '<form method="post" action="options.php">';
        settings_fields('aiss_settings_group');

        echo '<div class="aiss-card">';
        echo '<h2>⏰ Automatic Posting Schedule</h2>';
        echo '<p class="aiss-card-subtitle">Configure how often the plugin should check for new posts and share them to Facebook.</p>';
        
        echo '<table class="aiss-form-table">';
        echo '<tr><th><label for="schedule_minutes">Check Every (minutes)</label></th><td>';
        echo '<input type="number" min="5" step="1" id="schedule_minutes" name="aiss_settings[schedule_minutes]" value="' . esc_attr((int)$settings['schedule_minutes']) . '" style="width:120px;" />';
        echo '<p class="aiss-help-text">Minimum: 5 minutes. The plugin will check for new posts to share at this interval.</p>';
        echo '</td></tr>';

        echo '<tr><th><label for="max_posts_per_run">Posts Per Check</label></th><td>';
        echo '<input type="number" min="1" step="1" id="max_posts_per_run" name="aiss_settings[max_posts_per_run]" value="' . esc_attr((int)$settings['max_posts_per_run']) . '" style="width:120px;" />';
        echo '<p class="aiss-help-text">Maximum number of posts to share each time the scheduler runs.</p>';
        echo '</td></tr>';
        echo '</table>';
        echo '</div>';

        echo '<div class="aiss-card">';
        echo '<h2>🎯 Content Filters</h2>';
        echo '<p class="aiss-card-subtitle">Choose which posts should be automatically shared to Facebook based on their categories or tags.</p>';
        
        echo '<table class="aiss-form-table">';
        echo '<tr><th>Filter Mode</th><td>';
        echo '<div class="aiss-radio-group">';
        $m = (string)$settings['filter_mode'];
        echo '<label><input type="radio" name="aiss_settings[filter_mode]" value="all" ' . checked($m, 'all', false) . ' /> Share all published posts</label>';
        echo '<label><input type="radio" name="aiss_settings[filter_mode]" value="category" ' . checked($m, 'category', false) . ' /> Only posts from specific categories</label>';
        echo '<label><input type="radio" name="aiss_settings[filter_mode]" value="tag" ' . checked($m, 'tag', false) . ' /> Only posts with specific tags</label>';
        echo '</div>';
        echo '</td></tr>';

        echo '<tr><th><label for="filter_terms">Category/Tag Slugs</label></th><td>';
        echo '<input type="text" id="filter_terms" name="aiss_settings[filter_terms]" value="' . esc_attr($settings['filter_terms']) . '" placeholder="news, tech, updates" />';
        echo '<p class="aiss-help-text">Enter comma-separated category or tag slugs (only used when filter mode is set to categories or tags above).</p>';
        echo '</td></tr>';
        echo '</table>';
        echo '</div>';

        echo '<button type="submit" class="aiss-submit-btn">💾 Save Scheduler Settings</button>';
        echo '</form>';
    }

    private function render_status(array $settings) : void {
        $next = wp_next_scheduled(Cron::HOOK);
        $connected = $this->facebook->is_connected();
        $has_api_key = trim((string)$settings['openrouter_api_key']) !== '';

        echo '<div class="aiss-stats">';
        
        echo '<div class="aiss-stat-card ' . ($connected ? 'green' : 'orange') . '">';
        echo '<div class="aiss-stat-label">Facebook Status</div>';
        echo '<div class="aiss-stat-value">' . ($connected ? '✓ Connected' : '✗ Not Connected') . '</div>';
        echo '</div>';

        echo '<div class="aiss-stat-card ' . ($has_api_key ? 'green' : 'orange') . '">';
        echo '<div class="aiss-stat-label">OpenRouter API</div>';
        echo '<div class="aiss-stat-value">' . ($has_api_key ? '✓ Configured' : '✗ Missing Key') . '</div>';
        echo '</div>';

        echo '<div class="aiss-stat-card">';
        echo '<div class="aiss-stat-label">Schedule Interval</div>';
        echo '<div class="aiss-stat-value">Every ' . esc_html((int)$settings['schedule_minutes']) . 'm</div>';
        echo '</div>';

        echo '<div class="aiss-stat-card">';
        echo '<div class="aiss-stat-label">Posts Per Run</div>';
        echo '<div class="aiss-stat-value">' . esc_html((int)$settings['max_posts_per_run']) . '</div>';
        echo '</div>';

        echo '</div>';

        echo '<div class="aiss-card">';
        echo '<h2>📊 System Status</h2>';
        echo '<p class="aiss-card-subtitle">Current configuration and scheduling information.</p>';
        
        echo '<div class="aiss-info-grid">';
        
        echo '<div class="aiss-info-row">';
        echo '<div class="aiss-info-label">Facebook Connection</div>';
        echo '<div class="aiss-info-value">' . ($connected ? '<span style="color:#10b981;">✓ Connected to ' . esc_html($settings['fb_page_name']) . '</span>' : '<span style="color:#ef4444;">✗ Not connected</span>') . '</div>';
        echo '</div>';

        echo '<div class="aiss-info-row">';
        echo '<div class="aiss-info-label">OpenRouter API Key</div>';
        echo '<div class="aiss-info-value">' . ($has_api_key ? '<span style="color:#10b981;">✓ Configured</span>' : '<span style="color:#ef4444;">✗ Not set</span>') . '</div>';
        echo '</div>';

        echo '<div class="aiss-info-row">';
        echo '<div class="aiss-info-label">AI Model</div>';
        echo '<div class="aiss-info-value">' . esc_html($settings['openrouter_model']) . '</div>';
        echo '</div>';

        echo '<div class="aiss-info-row">';
        echo '<div class="aiss-info-label">Graph API Version</div>';
        echo '<div class="aiss-info-value">' . esc_html($settings['fb_api_version']) . '</div>';
        echo '</div>';

        echo '<div class="aiss-info-row">';
        echo '<div class="aiss-info-label">Schedule Interval</div>';
        echo '<div class="aiss-info-value">Every ' . esc_html((int)$settings['schedule_minutes']) . ' minutes</div>';
        echo '</div>';

        echo '<div class="aiss-info-row">';
        echo '<div class="aiss-info-label">Posts Per Run</div>';
        echo '<div class="aiss-info-value">' . esc_html((int)$settings['max_posts_per_run']) . ' post(s)</div>';
        echo '</div>';

        echo '<div class="aiss-info-row">';
        echo '<div class="aiss-info-label">Content Filter</div>';
        echo '<div class="aiss-info-value">' . ucfirst(esc_html($settings['filter_mode'])) . ($settings['filter_terms'] ? ' (' . esc_html($settings['filter_terms']) . ')' : '') . '</div>';
        echo '</div>';

        echo '<div class="aiss-info-row">';
        echo '<div class="aiss-info-label">Next Scheduled Run</div>';
        echo '<div class="aiss-info-value">' . ($next ? '<span style="color:#3b82f6;">' . esc_html(date_i18n(get_option('date_format').' '.get_option('time_format'), (int)$next)) . '</span>' : '<span style="color:#ef4444;">Not scheduled</span>') . '</div>';
        echo '</div>';

        echo '</div>';
        echo '</div>';

        if (!$connected || !$has_api_key) {
            echo '<div class="aiss-card" style="background:#fef3c7;border-color:#f59e0b;">';
            echo '<h2 style="color:#92400e;">⚠️ Configuration Required</h2>';
            echo '<p class="aiss-card-subtitle" style="color:#78350f;">Complete the setup to start automatically sharing posts to Facebook.</p>';
            echo '<ul style="margin:16px 0;padding-left:24px;color:#78350f;line-height:2;">';
            if (!$has_api_key) {
                echo '<li><strong>Configure OpenRouter API key</strong> in the <a href="' . esc_url(Utils::admin_url_settings(['tab'=>'general'])) . '" style="color:#92400e;font-weight:600;">General</a> tab</li>';
            }
            if (!$connected) {
                echo '<li><strong>Connect to Facebook</strong> in the <a href="' . esc_url(Utils::admin_url_settings(['tab'=>'facebook'])) . '" style="color:#92400e;font-weight:600;">Facebook</a> tab</li>';
            }
            echo '</ul>';
            echo '</div>';
        } else {
            echo '<div class="aiss-card" style="background:#d1fae5;border-color:#10b981;">';
            echo '<h2 style="color:#065f46;">✓ All Systems Ready!</h2>';
            echo '<p class="aiss-card-subtitle" style="color:#047857;">Your plugin is fully configured and ready to automatically share posts to Facebook.</p>';
            echo '</div>';
        }
    }

    public function start_fb_connect() : void {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('aiss_fb_connect');

        $settings = Utils::get_settings();
        if (empty($settings['fb_app_id']) || empty($settings['fb_app_secret'])) {
            wp_safe_redirect(Utils::admin_url_settings(['tab'=>'facebook','aiss_notice'=>'missing_app']));
            exit;
        }

        $url = $this->facebook->build_oauth_url();
        wp_redirect($url);
        exit;
    }

    public function select_fb_page() : void {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('aiss_fb_select_page', 'aiss_fb_select_page_nonce');

        $page_id = isset($_POST['page_id']) ? sanitize_text_field((string)$_POST['page_id']) : '';
        $result = $this->facebook->select_page_and_store($page_id);

        delete_transient('aiss_fb_pages_' . get_current_user_id());

        $args = ['tab' => 'facebook'];
        $args['aiss_notice'] = $result['ok'] ? 'page_selected' : 'page_error';
        if (!$result['ok']) { $args['aiss_error'] = rawurlencode((string)$result['error']); }

        wp_safe_redirect(Utils::admin_url_settings($args));
        exit;
    }

    public function disconnect_facebook() : void {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('aiss_fb_disconnect');

        $settings = Utils::get_settings();
        $settings['fb_page_id'] = '';
        $settings['fb_page_name'] = '';
        $settings['fb_page_token'] = '';
        $settings['fb_connected_at'] = 0;
        update_option('aiss_settings', $settings, false);

        wp_safe_redirect(Utils::admin_url_settings(['tab' => 'facebook', 'aiss_notice' => 'disconnected']));
        exit;
    }
}
