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
        add_action('admin_post_aiss_run_cron', [$this, 'run_cron_manually']);
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
        :root {
            --aiss-primary: #2563eb; --aiss-primary-hover: #1d4ed8;
            --aiss-bg: #f3f4f6; --aiss-card-bg: #ffffff;
            --aiss-text-main: #111827; --aiss-text-muted: #6b7280;
            --aiss-border: #e5e7eb;
            --aiss-success-bg: #ecfdf5; --aiss-success-text: #059669;
            --aiss-warning-bg: #fffbeb; --aiss-warning-text: #b45309;
            --aiss-error-bg: #fef2f2; --aiss-error-text: #b91c1c;
        }
        .aiss-wrap { max-width: 1200px; margin: 20px auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: var(--aiss-text-main); }
        .aiss-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; padding: 0 4px; }
        .aiss-header-title { display: flex; align-items: center; gap: 12px; }
        .aiss-header h1 { font-size: 24px; font-weight: 700; color: #111827; margin: 0; line-height: 1.2; }
        .aiss-header p { margin: 4px 0 0 0; color: var(--aiss-text-muted); font-size: 14px; }
        .aiss-icon-box { background: var(--aiss-primary); color: #fff; width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 20px; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2); }
        .aiss-tabs { display: flex; gap: 4px; background: #fff; padding: 4px; border-radius: 10px; border: 1px solid var(--aiss-border); margin-bottom: 24px; width: fit-content; }
        .aiss-tab { padding: 8px 16px; border-radius: 6px; text-decoration: none; color: var(--aiss-text-muted); font-weight: 500; font-size: 14px; transition: all 0.2s ease; }
        .aiss-tab:hover { color: var(--aiss-text-main); background: #f9fafb; }
        .aiss-tab-active { background: var(--aiss-primary); color: #fff !important; font-weight: 600; }
        .aiss-tab:focus { box-shadow: none; outline: none; }
        .aiss-card { background: var(--aiss-card-bg); border: 1px solid var(--aiss-border); border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1); }
        .aiss-card h2 { margin: 0 0 8px 0; font-size: 18px; font-weight: 600; color: var(--aiss-text-main); }
        .aiss-card-subtitle { color: var(--aiss-text-muted); font-size: 14px; margin: 0 0 24px 0; line-height: 1.5; max-width: 700px; }
        .aiss-form-table { width: 100%; border-collapse: separate; border-spacing: 0 16px; }
        .aiss-form-table th { text-align: left; vertical-align: top; padding-right: 24px; width: 220px; font-weight: 500; font-size: 14px; color: #374151; padding-top: 8px; }
        .aiss-input, .aiss-select, .aiss-textarea { width: 100%; max-width: 500px; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; transition: border-color 0.15s ease; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); }
        .aiss-input:focus, .aiss-select:focus, .aiss-textarea:focus { border-color: var(--aiss-primary); outline: none; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }
        .aiss-textarea { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; line-height: 1.6; max-width: 100%; }
        .aiss-help-text { font-size: 13px; color: var(--aiss-text-muted); margin-top: 6px; }
        .aiss-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 6px; font-weight: 500; font-size: 14px; text-decoration: none; cursor: pointer; border: 1px solid transparent; transition: all 0.2s; }
        .aiss-btn-primary { background: var(--aiss-primary); color: #fff; }
        .aiss-btn-primary:hover { background: var(--aiss-primary-hover); color: #fff; transform: translateY(-1px); }
        .aiss-btn-secondary { background: #fff; border-color: #d1d5db; color: #374151; }
        .aiss-btn-secondary:hover { background: #f9fafb; border-color: #9ca3af; }
        .aiss-btn-danger { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
        .aiss-btn-danger:hover { background: #fecaca; color: #991b1b; }
        .aiss-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .aiss-stat-box { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid var(--aiss-border); box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05); }
        .aiss-stat-label { font-size: 13px; font-weight: 500; color: var(--aiss-text-muted); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .aiss-stat-value { font-size: 24px; font-weight: 700; color: var(--aiss-text-main); display: flex; align-items: center; gap: 8px; }
        .aiss-status-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
        .aiss-dot-green { background: #10b981; } .aiss-dot-red { background: #ef4444; } .aiss-dot-orange { background: #f59e0b; }
        .aiss-banner { padding: 16px; border-radius: 8px; display: flex; align-items: center; gap: 12px; margin-bottom: 24px; font-size: 14px; border: 1px solid transparent; }
        .aiss-banner-success { background: var(--aiss-success-bg); color: var(--aiss-success-text); border-color: #d1fae5; }
        .aiss-banner-warning { background: var(--aiss-warning-bg); color: var(--aiss-warning-text); border-color: #fde68a; }
        .aiss-banner-error { background: var(--aiss-error-bg); color: var(--aiss-error-text); border-color: #fecaca; }
        .aiss-code { background: #1f2937; color: #e5e7eb; padding: 12px; border-radius: 6px; font-family: monospace; font-size: 12px; word-break: break-all; margin: 8px 0; }
        .aiss-radio-group label { display: block; margin-bottom: 8px; cursor: pointer; }
        .aiss-submit-wrap { margin-top: 24px; padding-top: 24px; border-top: 1px solid var(--aiss-border); }
        .settings_page_ai-social-share .notice:not(.aiss-notice) { display: none !important; }
        .aiss-notice { margin: 20px 0 0 0 !important; }
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

        if (isset($in['openrouter_api_key'])) $s['openrouter_api_key'] = trim((string)$in['openrouter_api_key']);
        if (isset($in['openrouter_model'])) $s['openrouter_model'] = trim((string)$in['openrouter_model']);
        if (isset($in['openrouter_site'])) $s['openrouter_site'] = esc_url_raw((string)$in['openrouter_site']);
        if (isset($in['openrouter_app'])) $s['openrouter_app'] = sanitize_text_field((string)$in['openrouter_app']);

        if (isset($in['schedule_minutes'])) $s['schedule_minutes'] = max(5, (int)$in['schedule_minutes']);
        if (isset($in['max_posts_per_run'])) $s['max_posts_per_run'] = max(1, (int)$in['max_posts_per_run']);
        if (isset($in['filter_mode'])) $s['filter_mode'] = (string)$in['filter_mode'];
        if (isset($in['filter_terms'])) $s['filter_terms'] = sanitize_text_field((string)$in['filter_terms']);

        if (isset($in['fb_app_id'])) $s['fb_app_id'] = sanitize_text_field((string)$in['fb_app_id']);
        if (isset($in['fb_app_secret'])) $s['fb_app_secret'] = sanitize_text_field((string)$in['fb_app_secret']);
        if (isset($in['fb_api_version'])) $s['fb_api_version'] = sanitize_text_field((string)$in['fb_api_version']);

        // Pass-through connection data
        if (isset($in['fb_page_id']))      $s['fb_page_id']      = sanitize_text_field((string)$in['fb_page_id']);
        if (isset($in['fb_page_name']))    $s['fb_page_name']    = sanitize_text_field((string)$in['fb_page_name']);
        if (isset($in['fb_page_token']))   $s['fb_page_token']   = (string)$in['fb_page_token'];
        if (isset($in['fb_connected_at'])) $s['fb_connected_at'] = (int)$in['fb_connected_at'];

        if (isset($in['prompt_facebook'])) $s['prompt_facebook'] = (string)$in['prompt_facebook'];

        return $s;
    }

    public function render() : void {
        if (!current_user_can('manage_options')) { return; }

        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        if (!in_array($tab, ['general','facebook','scheduler','status'], true)) { $tab = 'general'; }

        $settings = Utils::get_settings();
        $notices = [];

        if (isset($_GET['aiss_notice'])) {
            $n = sanitize_key($_GET['aiss_notice']);
            if ($n === 'page_selected') $notices[] = ['type'=>'success','msg'=>'Facebook Page connected!'];
            if ($n === 'page_error') $notices[] = ['type'=>'error','msg'=>'Connection Error: ' . urldecode($_GET['aiss_error']??'')];
            if ($n === 'disconnected') $notices[] = ['type'=>'success','msg'=>'Page disconnected.'];
            if ($n === 'cron_run') $notices[] = ['type'=>'success','msg'=>'Cron triggered manually! Check your Facebook page shortly.'];
        }

        if ($tab === 'facebook' && isset($_GET['aiss_fb_cb']) && isset($_GET['code'])) {
            $res = $this->facebook->handle_callback_and_fetch_pages($_GET['code'], $_GET['state']);
            if ($res['ok']) {
                set_transient('aiss_fb_pages_' . get_current_user_id(), $res['pages'], 1200);
                $notices[] = ['type'=>'success','msg'=>'Authorized! Select a Page below.'];
            } else {
                $notices[] = ['type'=>'error','msg'=>'Auth failed: ' . $res['error']];
            }
        }

        echo '<div class="wrap aiss-wrap">';
        echo '<div class="aiss-header"><div class="aiss-header-title"><div class="aiss-icon-box"><span class="dashicons dashicons-share-alt2"></span></div><div><h1>AI Social Share</h1><p>Auto-post to social media</p></div></div></div>';

        foreach ($notices as $n) {
            echo '<div class="aiss-banner ' . ($n['type']==='success'?'aiss-banner-success':'aiss-banner-error') . ' aiss-notice"><strong>' . ($n['type']==='success'?'✓':'✕') . '</strong> ' . esc_html($n['msg']) . '</div>';
        }

        echo '<div class="aiss-tabs">';
        $this->render_tab_link('general', 'Settings', $tab);
        $this->render_tab_link('facebook', 'Facebook', $tab);
        $this->render_tab_link('scheduler', 'Scheduler', $tab);
        $this->render_tab_link('status', 'System Status', $tab);
        echo '</div>';

        if ($tab === 'general') $this->render_general($settings);
        elseif ($tab === 'facebook') $this->render_facebook($settings, get_transient('aiss_fb_pages_'.get_current_user_id()) ?: []);
        elseif ($tab === 'scheduler') $this->render_scheduler($settings);
        else $this->render_status($settings);

        echo '</div>';
    }

    private function render_tab_link($k, $l, $c) {
        echo '<a class="aiss-tab' . ($c===$k?' aiss-tab-active':'') . '" href="' . esc_url(Utils::admin_url_settings(['tab'=>$k])) . '">' . $l . '</a>';
    }

    private function render_general($s) {
        echo '<form method="post" action="options.php">';
        settings_fields('aiss_settings_group');
        echo '<div class="aiss-card"><h2>OpenRouter API</h2><p class="aiss-card-subtitle">AI Configuration</p><table class="aiss-form-table">';
        echo '<tr><th>API Key <span style="color:red">*</span></th><td><input type="password" class="aiss-input" name="aiss_settings[openrouter_api_key]" value="' . esc_attr($s['openrouter_api_key']) . '" /></td></tr>';
        echo '<tr><th>Model</th><td><select class="aiss-select" name="aiss_settings[openrouter_model]">';
        foreach(['openai/gpt-4o-mini'=>'GPT-4o Mini','openai/gpt-4o'=>'GPT-4o','anthropic/claude-3.5-sonnet'=>'Claude 3.5','google/gemini-pro'=>'Gemini Pro'] as $k=>$v)
            echo '<option value="'.$k.'" '.selected($s['openrouter_model'],$k,false).'>'.$v.'</option>';
        echo '</select></td></tr>';
        echo '<tr><th>Site URL</th><td><input type="url" class="aiss-input" name="aiss_settings[openrouter_site]" value="' . esc_attr($s['openrouter_site']) . '" /></td></tr>';
        echo '<tr><th>App Name</th><td><input type="text" class="aiss-input" name="aiss_settings[openrouter_app]" value="' . esc_attr($s['openrouter_app']) . '" /></td></tr>';
        echo '</table></div>';
        echo '<div class="aiss-card"><h2>Facebook Prompt</h2><textarea class="aiss-textarea" name="aiss_settings[prompt_facebook]" rows="8">' . esc_textarea($s['prompt_facebook']) . '</textarea></div>';
        echo '<div class="aiss-submit-wrap"><button type="submit" class="aiss-btn aiss-btn-primary">Save Settings</button></div></form>';
    }

    private function render_facebook($s, $pages) {
        $con = $this->facebook->is_connected();
        echo '<div class="aiss-card"><h2>Facebook Connection</h2>';
        if ($con) {
            echo '<div class="aiss-banner aiss-banner-success">Connected to: ' . esc_html($s['fb_page_name']) . '</div>';
            echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_disconnect'), 'aiss_fb_disconnect') . '" class="aiss-btn aiss-btn-danger">Disconnect</a>';
        } else {
            echo '<div class="aiss-banner aiss-banner-warning">Not Connected</div>';
            echo '<form method="post" action="options.php">'; settings_fields('aiss_settings_group');
            echo '<table class="aiss-form-table">';
            echo '<tr><th>App ID</th><td><input type="text" class="aiss-input" name="aiss_settings[fb_app_id]" value="' . esc_attr($s['fb_app_id']) . '" /></td></tr>';
            echo '<tr><th>App Secret</th><td><input type="password" class="aiss-input" name="aiss_settings[fb_app_secret]" value="' . esc_attr($s['fb_app_secret']) . '" /></td></tr>';
            echo '</table>';
            echo '<div class="aiss-submit-wrap" style="display:flex;justify-content:space-between;"><button class="aiss-btn aiss-btn-secondary">Save Credentials</button>';
            if($s['fb_app_id'] && $s['fb_app_secret']) echo '<a href="'.wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_start_connect'),'aiss_fb_connect').'" class="aiss-btn aiss-btn-primary">Connect Facebook</a>';
            echo '</div></form>';
        }
        echo '</div>';
        if ($pages && !$con) {
            echo '<div class="aiss-card"><h2>Select Page</h2><form method="post" action="'.admin_url('admin-post.php').'"><input type="hidden" name="action" value="aiss_fb_select_page">';
            wp_nonce_field('aiss_fb_select_page','aiss_fb_select_page_nonce');
            echo '<select name="page_id" class="aiss-select">'; foreach($pages as $p) echo '<option value="'.$p['id'].'">'.$p['name'].'</option>'; echo '</select><br><br>';
            echo '<button class="aiss-btn aiss-btn-primary">Use This Page</button></form></div>';
        }
        echo '<div class="aiss-card"><h2>OAuth Redirect URI</h2><div class="aiss-code">'.$this->facebook->oauth_redirect_uri().'</div></div>';
    }

    private function render_scheduler($s) {
        echo '<form method="post" action="options.php">'; settings_fields('aiss_settings_group');
        echo '<div class="aiss-card"><h2>Schedule</h2><table class="aiss-form-table">';
        echo '<tr><th>Minutes</th><td><input type="number" class="aiss-input" name="aiss_settings[schedule_minutes]" value="' . (int)$s['schedule_minutes'] . '" min="5" /></td></tr>';
        echo '<tr><th>Posts per Run</th><td><input type="number" class="aiss-input" name="aiss_settings[max_posts_per_run]" value="' . (int)$s['max_posts_per_run'] . '" /></td></tr>';
        echo '</table></div>';
        echo '<div class="aiss-card"><h2>Filters</h2><table class="aiss-form-table">';
        echo '<tr><th>Mode</th><td><div class="aiss-radio-group"><label><input type="radio" name="aiss_settings[filter_mode]" value="all" '.checked($s['filter_mode'],'all',false).'> All</label><label><input type="radio" name="aiss_settings[filter_mode]" value="category" '.checked($s['filter_mode'],'category',false).'> Category</label><label><input type="radio" name="aiss_settings[filter_mode]" value="tag" '.checked($s['filter_mode'],'tag',false).'> Tag</label></div></td></tr>';
        echo '<tr><th>Slugs</th><td><input type="text" class="aiss-input" name="aiss_settings[filter_terms]" value="' . esc_attr($s['filter_terms']) . '" /></td></tr>';
        echo '</table></div>';
        echo '<div class="aiss-submit-wrap"><button class="aiss-btn aiss-btn-primary">Save Schedule</button></div></form>';
    }

    private function render_status($s) {
        $con = $this->facebook->is_connected();
        $key = !empty($s['openrouter_api_key']);
        $next = wp_next_scheduled(Cron::HOOK);

        echo '<div class="aiss-stats-grid">';
        echo '<div class="aiss-stat-box"><div class="aiss-stat-label">Facebook</div><div class="aiss-stat-value"><span class="aiss-status-dot '.($con?'aiss-dot-green':'aiss-dot-red').'"></span> '.($con?'Active':'Offline').'</div></div>';
        echo '<div class="aiss-stat-box"><div class="aiss-stat-label">AI</div><div class="aiss-stat-value"><span class="aiss-status-dot '.($key?'aiss-dot-green':'aiss-dot-red').'"></span> '.($key?'Ready':'No Key').'</div></div>';
        echo '<div class="aiss-stat-box"><div class="aiss-stat-label">Next Run</div><div class="aiss-stat-value" style="font-size:16px;margin-top:6px;">'.($next?date_i18n('H:i:s',$next):'Not scheduled').'</div></div>';
        echo '</div>';
        
        echo '<div class="aiss-card"><h2>Debug Actions</h2>';
        echo '<p class="aiss-card-subtitle">If posts aren\'t sharing, click the button below to force a check immediately.</p>';
        echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=aiss_run_cron'), 'aiss_run_cron') . '" class="aiss-btn aiss-btn-secondary">⚡ Run Cron Now</a>';
        echo '</div>';
    }

    public function start_fb_connect() {
        check_admin_referer('aiss_fb_connect');
        wp_redirect($this->facebook->build_oauth_url()); exit;
    }
    public function select_fb_page() {
        check_admin_referer('aiss_fb_select_page', 'aiss_fb_select_page_nonce');
        $res = $this->facebook->select_page_and_store($_POST['page_id'] ?? '');
        delete_transient('aiss_fb_pages_' . get_current_user_id());
        wp_redirect(Utils::admin_url_settings(['tab'=>'facebook','aiss_notice'=>$res['ok']?'page_selected':'page_error','aiss_error'=>urlencode($res['error']??'')])); exit;
    }
    public function disconnect_facebook() {
        check_admin_referer('aiss_fb_disconnect');
        $s = Utils::get_settings(); $s['fb_page_id']=''; $s['fb_page_token']=''; update_option('aiss_settings', $s);
        wp_redirect(Utils::admin_url_settings(['tab'=>'facebook','aiss_notice'=>'disconnected'])); exit;
    }
    public function run_cron_manually() {
        check_admin_referer('aiss_run_cron');
        $this->cron->run();
        Cron::ensure_scheduled(true); // Force reschedule while we are at it
        wp_redirect(Utils::admin_url_settings(['tab'=>'status','aiss_notice'=>'cron_run'])); exit;
    }
}