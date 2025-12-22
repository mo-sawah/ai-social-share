<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Admin {

    /** @var Cron */
    private $cron;

    /** @var Facebook */
    private $facebook;

    /** @var X */
    private $x;

    public function __construct(Cron $cron, Facebook $facebook, X $x) {
        $this->cron = $cron;
        $this->facebook = $facebook;
        $this->x = $x;

        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_settings']);
        
        // Form Handlers
        add_action('admin_post_aiss_fb_start_connect', [$this, 'start_fb_connect']);
        add_action('admin_post_aiss_fb_select_page', [$this, 'select_fb_page']);
        add_action('admin_post_aiss_fb_disconnect', [$this, 'disconnect_facebook']);
        add_action('admin_post_aiss_run_cron', [$this, 'run_cron_manually']);
        
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

    public function enqueue_admin_assets($hook) : void {
        if ($hook !== 'settings_page_ai-social-share') {
            return;
        }
        
        // CSS Styles
        echo <<<'CSS'
        <style>
        :root {
            --aiss-primary: #2563eb;
            --aiss-primary-hover: #1d4ed8;
            --aiss-bg: #f3f4f6;
            --aiss-text-main: #111827;
            --aiss-text-muted: #6b7280;
            --aiss-border: #e5e7eb;
            --aiss-success-bg: #ecfdf5;
            --aiss-success-text: #059669;
            --aiss-error-bg: #fef2f2;
            --aiss-error-text: #b91c1c;
        }

        .aiss-wrap { 
            max-width: 1200px; 
            margin: 20px auto; 
            font-family: -apple-system, system-ui, sans-serif; 
            color: var(--aiss-text-main);
        }

        .aiss-header { 
            display: flex; 
            align-items: center; 
            gap: 16px; 
            margin-bottom: 24px; 
        }
        .aiss-icon-box { 
            background: var(--aiss-primary); 
            color: #fff; 
            width: 40px; 
            height: 40px; 
            border-radius: 8px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 20px; 
        }
        .aiss-header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
        }

        /* Tabs */
        .aiss-tabs { 
            display: flex; 
            gap: 4px; 
            background: #fff; 
            padding: 4px; 
            border-radius: 10px; 
            border: 1px solid var(--aiss-border); 
            margin-bottom: 24px; 
            width: fit-content; 
        }
        .aiss-tab { 
            padding: 8px 16px; 
            border-radius: 6px; 
            text-decoration: none; 
            color: var(--aiss-text-muted); 
            font-weight: 500; 
            font-size: 14px; 
            transition: all 0.2s; 
        }
        /* CSS FIX: Added explicit hover color */
        .aiss-tab:hover { 
            color: var(--aiss-text-main); 
            background: #f3f4f6; 
        }
        .aiss-tab-active { 
            background: var(--aiss-primary); 
            color: #fff !important; 
            font-weight: 600; 
        }

        /* Card */
        .aiss-card { 
            background: #fff; 
            border: 1px solid var(--aiss-border); 
            border-radius: 12px; 
            padding: 24px; 
            margin-bottom: 24px; 
            box-shadow: 0 1px 2px rgba(0,0,0,0.05); 
        }
        .aiss-card h2 { 
            margin: 0 0 16px 0; 
            font-size: 18px; 
            font-weight: 600; 
        }
        .aiss-card p.description {
            margin-top: 0;
            color: var(--aiss-text-muted);
            font-size: 14px;
            margin-bottom: 16px;
        }

        /* Forms */
        .aiss-form-table { width: 100%; border-collapse: collapse; }
        .aiss-form-table th { 
            text-align: left; 
            padding: 12px 24px 12px 0; 
            width: 200px; 
            vertical-align: top; 
            font-weight: 500;
        }
        .aiss-form-table td { padding: 8px 0; }
        .aiss-input, .aiss-select, .aiss-textarea { 
            width: 100%; 
            max-width: 500px; 
            padding: 10px 12px; 
            border: 1px solid #d1d5db; 
            border-radius: 6px; 
            font-size: 14px;
        }
        .aiss-textarea { 
            font-family: monospace; 
            line-height: 1.5; 
            min-height: 120px;
        }

        /* Buttons */
        .aiss-btn { 
            display: inline-flex; 
            align-items: center; 
            padding: 10px 20px; 
            border-radius: 6px; 
            background: var(--aiss-primary); 
            color: #fff; 
            text-decoration: none; 
            border: none; 
            cursor: pointer; 
            font-size: 14px; 
            transition: background 0.2s;
        }
        .aiss-btn:hover { background: var(--aiss-primary-hover); color: #fff; }
        .aiss-btn-danger { background: #dc2626; }
        .aiss-btn-danger:hover { background: #b91c1c; }
        .aiss-btn-secondary { background: #fff; border: 1px solid #d1d5db; color: #374151; }
        .aiss-btn-secondary:hover { background: #f3f4f6; color: #111827; }

        /* Status Grid */
        .aiss-stats-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 16px; 
            margin-bottom: 24px; 
        }
        .aiss-stat-box { 
            background: #fff; 
            padding: 20px; 
            border-radius: 10px; 
            border: 1px solid var(--aiss-border); 
        }
        .aiss-stat-label { 
            font-size: 12px; 
            color: var(--aiss-text-muted); 
            text-transform: uppercase; 
            letter-spacing: 0.5px; 
            font-weight: 600; 
            margin-bottom: 6px; 
        }
        .aiss-stat-value { 
            font-size: 20px; 
            font-weight: 700; 
            display: flex;
            align-items: center;
        }
        .aiss-status-dot { 
            display: inline-block; 
            width: 10px; 
            height: 10px; 
            border-radius: 50%; 
            margin-right: 8px; 
        }
        .dot-green { background: #10b981; } 
        .dot-red { background: #ef4444; } 
        .dot-gray { background: #9ca3af; }

        /* Notices */
        .aiss-notice { 
            padding: 12px 16px; 
            border-radius: 8px; 
            margin-bottom: 20px; 
            border-left: 4px solid; 
            background: #fff; 
        }
        .notice-success { border-color: #10b981; background: var(--aiss-success-bg); color: var(--aiss-success-text); }
        .notice-error { border-color: #ef4444; background: var(--aiss-error-bg); color: var(--aiss-error-text); }

        /* Hide WP defaults */
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
        $in = (array)$input;

        // --- General ---
        if (isset($in['openrouter_api_key'])) $s['openrouter_api_key'] = trim($in['openrouter_api_key']);
        if (isset($in['openrouter_model'])) $s['openrouter_model'] = trim($in['openrouter_model']);

        // --- Scheduler ---
        if (isset($in['schedule_minutes'])) $s['schedule_minutes'] = max(5, (int)$in['schedule_minutes']);
        if (isset($in['max_posts_per_run'])) $s['max_posts_per_run'] = max(1, (int)$in['max_posts_per_run']);
        if (isset($in['filter_mode'])) $s['filter_mode'] = sanitize_key($in['filter_mode']);
        if (isset($in['filter_terms'])) $s['filter_terms'] = sanitize_text_field($in['filter_terms']);

        // --- Facebook ---
        if (isset($in['fb_app_id'])) $s['fb_app_id'] = sanitize_text_field($in['fb_app_id']);
        if (isset($in['fb_app_secret'])) $s['fb_app_secret'] = sanitize_text_field($in['fb_app_secret']);
        if (isset($in['prompt_facebook'])) $s['prompt_facebook'] = wp_kses_post($in['prompt_facebook']);
        
        // Facebook Connection Data (Pass-through)
        if (isset($in['fb_page_id'])) $s['fb_page_id'] = sanitize_text_field($in['fb_page_id']);
        if (isset($in['fb_page_name'])) $s['fb_page_name'] = sanitize_text_field($in['fb_page_name']);
        if (isset($in['fb_page_token'])) $s['fb_page_token'] = $in['fb_page_token'];

        // --- X (Twitter) ---
        $s['x_enabled'] = isset($in['x_enabled']); // Checkbox handling
        if (isset($in['x_consumer_key'])) $s['x_consumer_key'] = trim($in['x_consumer_key']);
        if (isset($in['x_consumer_secret'])) $s['x_consumer_secret'] = trim($in['x_consumer_secret']);
        if (isset($in['x_access_token'])) $s['x_access_token'] = trim($in['x_access_token']);
        if (isset($in['x_access_secret'])) $s['x_access_secret'] = trim($in['x_access_secret']);
        if (isset($in['prompt_x'])) $s['prompt_x'] = wp_kses_post($in['prompt_x']);

        return $s;
    }

    public function render() : void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        $settings = Utils::get_settings();
        
        echo '<div class="wrap aiss-wrap">';
        
        // Header
        echo '<div class="aiss-header">';
        echo '<div class="aiss-icon-box"><span class="dashicons dashicons-share-alt2"></span></div>';
        echo '<h1>AI Social Share</h1>';
        echo '</div>';
        
        // Notices
        if (isset($_GET['aiss_notice'])) {
            $msg = '';
            $type = 'success';
            
            switch($_GET['aiss_notice']) {
                case 'page_selected': 
                    $msg = 'Facebook Page Connected Successfully!'; 
                    break;
                case 'cron_run': 
                    $msg = 'Cron triggered manually. The system is checking for posts now.'; 
                    break;
                case 'disconnected': 
                    $msg = 'Facebook disconnected successfully.'; 
                    break;
                case 'page_error': 
                    $type = 'error'; 
                    $msg = 'Error: ' . urldecode($_GET['aiss_error'] ?? 'Unknown'); 
                    break;
            }

            if ($msg) {
                echo '<div class="aiss-notice notice-' . $type . '"><p>' . esc_html($msg) . '</p></div>';
            }
        }

        // Tabs
        echo '<div class="aiss-tabs">';
        $this->render_tab_link('general', 'Settings', $tab);
        $this->render_tab_link('facebook', 'Facebook', $tab);
        $this->render_tab_link('x', 'X (Twitter)', $tab); // New Tab
        $this->render_tab_link('scheduler', 'Scheduler', $tab);
        $this->render_tab_link('status', 'System Status', $tab);
        echo '</div>';

        // Content
        if ($tab === 'general') {
            $this->render_general($settings);
        } elseif ($tab === 'facebook') {
            $this->render_facebook($settings);
        } elseif ($tab === 'x') {
            $this->render_x($settings);
        } elseif ($tab === 'scheduler') {
            $this->render_scheduler($settings);
        } else {
            $this->render_status($settings);
        }
        
        echo '</div>';
    }

    private function render_tab_link(string $id, string $label, string $current) : void {
        $class = 'aiss-tab' . ($id === $current ? ' aiss-tab-active' : '');
        $url = Utils::admin_url_settings(['tab' => $id]);
        echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
    }

    private function render_general(array $s) : void {
        echo '<form method="post" action="options.php">';
        settings_fields('aiss_settings_group');
        
        echo '<div class="aiss-card">';
        echo '<h2>AI Configuration</h2>';
        echo '<p class="description">Configure the OpenRouter API connection to generate content.</p>';
        
        echo '<table class="aiss-form-table">';
        echo '<tr><th>OpenRouter API Key</th><td>';
        echo '<input type="password" class="aiss-input" name="aiss_settings[openrouter_api_key]" value="' . esc_attr($s['openrouter_api_key']) . '">';
        echo '</td></tr>';
        
        echo '<tr><th>AI Model</th><td>';
        echo '<select class="aiss-select" name="aiss_settings[openrouter_model]">';
        $models = [
            'openai/gpt-4o-mini' => 'GPT-4o Mini (Fast & Cheap)',
            'openai/gpt-4o' => 'GPT-4o (High Quality)',
            'anthropic/claude-3.5-sonnet' => 'Claude 3.5 Sonnet (Recommended)',
            'google/gemini-pro' => 'Google Gemini Pro'
        ];
        foreach($models as $val => $label) {
            echo '<option value="' . esc_attr($val) . '" ' . selected($s['openrouter_model'], $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</td></tr>';
        echo '</table>';
        echo '</div>';
        
        echo '<button class="aiss-btn">Save General Settings</button>';
        echo '</form>';
    }

    private function render_facebook(array $s) : void {
        $connected = $this->facebook->is_connected();
        
        echo '<form method="post" action="options.php">';
        settings_fields('aiss_settings_group');
        
        echo '<div class="aiss-card">';
        echo '<h2>Facebook Connection</h2>';
        
        if ($connected) {
            echo '<div class="aiss-notice notice-success" style="margin-top:0;">';
            echo '<p><strong>✓ Connected to Page:</strong> ' . esc_html($s['fb_page_name']) . '</p>';
            echo '</div>';
            
            $disconnect_url = wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_disconnect'), 'aiss_fb_disconnect');
            echo '<a href="' . esc_url($disconnect_url) . '" class="aiss-btn aiss-btn-danger" onclick="return confirm(\'Disconnect?\');">Disconnect Facebook</a>';
        } else {
            echo '<table class="aiss-form-table">';
            echo '<tr><th>App ID</th><td><input type="text" class="aiss-input" name="aiss_settings[fb_app_id]" value="' . esc_attr($s['fb_app_id']) . '"></td></tr>';
            echo '<tr><th>App Secret</th><td><input type="password" class="aiss-input" name="aiss_settings[fb_app_secret]" value="' . esc_attr($s['fb_app_secret']) . '"></td></tr>';
            echo '</table>';
            
            echo '<div style="margin-top:16px;">';
            echo '<button class="aiss-btn aiss-btn-secondary">Save Credentials</button> ';
            
            if ($s['fb_app_id'] && $s['fb_app_secret']) {
                $connect_url = wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_start_connect'), 'aiss_fb_connect');
                echo '<a href="' . esc_url($connect_url) . '" class="aiss-btn">Connect Page</a>';
            }
            echo '</div>';
        }
        echo '</div>';

        // Facebook Prompt
        echo '<div class="aiss-card">';
        echo '<h2>Facebook Prompt</h2>';
        echo '<p class="description">Customize how AI writes posts specifically for Facebook.</p>';
        echo '<textarea name="aiss_settings[prompt_facebook]" class="aiss-textarea" rows="5">' . esc_textarea($s['prompt_facebook']) . '</textarea>';
        echo '</div>';

        // Page Selection Logic (if authorized but not selected)
        $pages = get_transient('aiss_fb_pages_' . get_current_user_id());
        if (!empty($pages) && !$connected) {
            echo '<div class="aiss-card">';
            echo '<h2>Select a Page</h2>';
            echo '<p class="description">We found these pages in your account:</p>';
            
            echo '<select id="fb_page_selector" class="aiss-select" style="max-width:300px; margin-bottom:10px;">';
            foreach ($pages as $p) {
                echo '<option value="' . esc_attr($p['id']) . '">' . esc_html($p['name']) . '</option>';
            }
            echo '</select>';
            
            // Hidden form to submit selection
            echo '<br>';
            echo '<button type="button" class="aiss-btn" onclick="submitPageSelection()">Use This Page</button>';
            
            echo '<script>
            function submitPageSelection() {
                var pid = document.getElementById("fb_page_selector").value;
                var form = document.createElement("form");
                form.method = "POST";
                form.action = "' . admin_url('admin-post.php') . '";
                
                var act = document.createElement("input");
                act.type = "hidden"; act.name = "action"; act.value = "aiss_fb_select_page";
                form.appendChild(act);
                
                var nonce = document.createElement("input");
                nonce.type = "hidden"; nonce.name = "aiss_fb_select_page_nonce"; nonce.value = "' . wp_create_nonce('aiss_fb_select_page') . '";
                form.appendChild(nonce);
                
                var pinput = document.createElement("input");
                pinput.type = "hidden"; pinput.name = "page_id"; pinput.value = pid;
                form.appendChild(pinput);
                
                document.body.appendChild(form);
                form.submit();
            }
            </script>';
            echo '</div>';
        }

        echo '<button class="aiss-btn">Save Facebook Settings</button>';
        echo '</form>';
    }

    private function render_x(array $s) : void {
        echo '<form method="post" action="options.php">';
        settings_fields('aiss_settings_group');
        
        echo '<div class="aiss-card">';
        echo '<h2>X (Twitter) Connection</h2>';
        echo '<p class="description">Enter your X API v2 credentials (OAuth 1.0a) from the Developer Portal.</p>';
        
        echo '<div style="margin-bottom:16px;">';
        echo '<label><input type="checkbox" name="aiss_settings[x_enabled]" value="1" ' . checked($s['x_enabled'], true, false) . '> <strong>Enable X Sharing</strong></label>';
        echo '</div>';
        
        echo '<table class="aiss-form-table">';
        echo '<tr><th>API Key (Consumer Key)</th><td><input type="text" class="aiss-input" name="aiss_settings[x_consumer_key]" value="' . esc_attr($s['x_consumer_key']) . '"></td></tr>';
        echo '<tr><th>API Secret (Consumer Secret)</th><td><input type="password" class="aiss-input" name="aiss_settings[x_consumer_secret]" value="' . esc_attr($s['x_consumer_secret']) . '"></td></tr>';
        echo '<tr><th>Access Token</th><td><input type="text" class="aiss-input" name="aiss_settings[x_access_token]" value="' . esc_attr($s['x_access_token']) . '"></td></tr>';
        echo '<tr><th>Access Token Secret</th><td><input type="password" class="aiss-input" name="aiss_settings[x_access_secret]" value="' . esc_attr($s['x_access_secret']) . '"></td></tr>';
        echo '</table>';
        echo '</div>';

        echo '<div class="aiss-card">';
        echo '<h2>X (Twitter) Prompt</h2>';
        echo '<p class="description">Customize how AI writes for X (keep it short!).</p>';
        echo '<textarea name="aiss_settings[prompt_x]" class="aiss-textarea" rows="4">' . esc_textarea($s['prompt_x']) . '</textarea>';
        echo '</div>';

        echo '<button class="aiss-btn">Save X Settings</button>';
        echo '</form>';
    }

    private function render_scheduler(array $s) : void {
        echo '<form method="post" action="options.php">';
        settings_fields('aiss_settings_group');
        
        echo '<div class="aiss-card">';
        echo '<h2>Schedule Settings</h2>';
        echo '<p class="description">Control how often the plugin checks for new posts.</p>';
        
        echo '<table class="aiss-form-table">';
        echo '<tr><th>Check Interval</th><td>';
        echo '<input type="number" class="aiss-input" name="aiss_settings[schedule_minutes]" value="' . (int)$s['schedule_minutes'] . '" min="5"> minutes';
        echo '</td></tr>';
        
        echo '<tr><th>Posts per Batch</th><td>';
        echo '<input type="number" class="aiss-input" name="aiss_settings[max_posts_per_run]" value="' . (int)$s['max_posts_per_run'] . '" min="1">';
        echo '</td></tr>';
        echo '</table>';
        echo '</div>';
        
        echo '<div class="aiss-card">';
        echo '<h2>Filters</h2>';
        echo '<p class="description">Only share posts that match these criteria.</p>';
        
        echo '<table class="aiss-form-table">';
        echo '<tr><th>Filter Mode</th><td>';
        echo '<select class="aiss-select" name="aiss_settings[filter_mode]">';
        echo '<option value="all" ' . selected($s['filter_mode'], 'all', false) . '>All Posts</option>';
        echo '<option value="category" ' . selected($s['filter_mode'], 'category', false) . '>Specific Category</option>';
        echo '<option value="tag" ' . selected($s['filter_mode'], 'tag', false) . '>Specific Tag</option>';
        echo '</select>';
        echo '</td></tr>';
        
        echo '<tr><th>Slugs</th><td>';
        echo '<input type="text" class="aiss-input" name="aiss_settings[filter_terms]" value="' . esc_attr($s['filter_terms']) . '" placeholder="e.g. news, tech">';
        echo '<p class="description">Comma-separated slugs. Only used if Category or Tag mode is selected.</p>';
        echo '</td></tr>';
        echo '</table>';
        echo '</div>';

        echo '<button class="aiss-btn">Save Schedule</button>';
        echo '</form>';
    }

    private function render_status(array $s) : void {
        $fb_active = $this->facebook->is_connected();
        $x_active  = $this->x->is_connected();
        $next_run  = wp_next_scheduled(Cron::HOOK);
        
        echo '<div class="aiss-stats-grid">';
        
        // FB Box
        echo '<div class="aiss-stat-box">';
        echo '<div class="aiss-stat-label">Facebook</div>';
        echo '<div class="aiss-stat-value">';
        echo '<span class="aiss-status-dot ' . ($fb_active ? 'dot-green' : 'dot-red') . '"></span>';
        echo $fb_active ? 'Active' : 'Offline';
        echo '</div>';
        echo '</div>';

        // X Box
        echo '<div class="aiss-stat-box">';
        echo '<div class="aiss-stat-label">X (Twitter)</div>';
        echo '<div class="aiss-stat-value">';
        echo '<span class="aiss-status-dot ' . ($x_active ? 'dot-green' : 'dot-gray') . '"></span>';
        echo $x_active ? 'Active' : 'Disabled';
        echo '</div>';
        echo '</div>';

        // Schedule Box
        echo '<div class="aiss-stat-box">';
        echo '<div class="aiss-stat-label">Next Scheduled Run</div>';
        echo '<div class="aiss-stat-value">';
        echo $next_run ? date_i18n('H:i:s', $next_run) : 'Not Scheduled';
        echo '</div>';
        echo '<div style="font-size:12px; color:#666; margin-top:5px;">Interval: ' . (int)$s['schedule_minutes'] . ' mins</div>';
        echo '</div>';

        echo '</div>'; // End Grid

        // Debug Actions
        echo '<div class="aiss-card">';
        echo '<h2>Manual Actions</h2>';
        echo '<p class="description">Useful for debugging. This will force the system to check for pending posts immediately.</p>';
        
        $cron_url = wp_nonce_url(admin_url('admin-post.php?action=aiss_run_cron'), 'aiss_run_cron');
        echo '<a href="' . esc_url($cron_url) . '" class="aiss-btn aiss-btn-secondary">⚡ Run Cron Job Now</a>';
        echo '</div>';
    }

    // --- Action Handlers ---

    public function start_fb_connect() : void {
        check_admin_referer('aiss_fb_connect');
        $url = $this->facebook->build_oauth_url();
        wp_redirect($url);
        exit;
    }

    public function select_fb_page() : void {
        check_admin_referer('aiss_fb_select_page', 'aiss_fb_select_page_nonce');
        $page_id = isset($_POST['page_id']) ? sanitize_text_field($_POST['page_id']) : '';
        
        $result = $this->facebook->select_page_and_store($page_id);
        
        // Clear cache
        delete_transient('aiss_fb_pages_' . get_current_user_id());

        $args = ['tab' => 'facebook'];
        if ($result['ok']) {
            $args['aiss_notice'] = 'page_selected';
        } else {
            $args['aiss_notice'] = 'page_error';
            $args['aiss_error'] = urlencode($result['error']);
        }
        
        wp_redirect(Utils::admin_url_settings($args));
        exit;
    }

    public function disconnect_facebook() : void {
        check_admin_referer('aiss_fb_disconnect');
        
        $settings = Utils::get_settings();
        $settings['fb_page_id'] = '';
        $settings['fb_page_token'] = '';
        $settings['fb_page_name'] = '';
        update_option('aiss_settings', $settings);
        
        wp_redirect(Utils::admin_url_settings(['tab' => 'facebook', 'aiss_notice' => 'disconnected']));
        exit;
    }

    public function run_cron_manually() : void {
        check_admin_referer('aiss_run_cron');
        $this->cron->run();
        wp_redirect(Utils::admin_url_settings(['tab' => 'status', 'aiss_notice' => 'cron_run']));
        exit;
    }
}