<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Admin {
    private $cron;
    private $facebook;
    private $x;

    public function __construct(Cron $cron, Facebook $facebook, X $x) {
        $this->cron = $cron;
        $this->facebook = $facebook;
        $this->x = $x;

        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_aiss_fb_start_connect', [$this, 'start_fb_connect']);
        add_action('admin_post_aiss_fb_select_page', [$this, 'select_fb_page']);
        add_action('admin_post_aiss_fb_disconnect', [$this, 'disconnect_facebook']);
        add_action('admin_post_aiss_run_cron', [$this, 'run_cron_manually']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function menu() : void {
        add_options_page('AI Social Share','AI Social Share','manage_options','ai-social-share',[$this, 'render']);
    }

    public function enqueue_admin_assets($hook) : void {
        if ($hook !== 'settings_page_ai-social-share') { return; }
        // FIX: Added specific hover color for tabs and new status styles
        echo <<<'CSS'
        <style>
        :root {
            --aiss-primary: #2563eb; --aiss-primary-hover: #1d4ed8;
            --aiss-bg: #f3f4f6; --aiss-card-bg: #ffffff;
            --aiss-text-main: #111827; --aiss-text-muted: #6b7280;
            --aiss-border: #e5e7eb;
        }
        .aiss-wrap { max-width: 1200px; margin: 20px auto; font-family: -apple-system, system-ui, sans-serif; color: var(--aiss-text-main); }
        .aiss-header { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; }
        .aiss-icon-box { background: var(--aiss-primary); color: #fff; width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .aiss-tabs { display: flex; gap: 4px; background: #fff; padding: 4px; border-radius: 10px; border: 1px solid var(--aiss-border); margin-bottom: 24px; width: fit-content; }
        .aiss-tab { padding: 8px 16px; border-radius: 6px; text-decoration: none; color: var(--aiss-text-muted); font-weight: 500; font-size: 14px; transition: all 0.2s; }
        /* CSS FIX HERE: */
        .aiss-tab:hover { color: var(--aiss-text-main); background: #f3f4f6; }
        .aiss-tab-active { background: var(--aiss-primary); color: #fff !important; font-weight: 600; }
        .aiss-card { background: #fff; border: 1px solid var(--aiss-border); border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .aiss-card h2 { margin: 0 0 16px 0; font-size: 18px; font-weight: 600; }
        .aiss-form-table th { text-align: left; padding: 12px 24px 12px 0; width: 200px; font-weight: 500; }
        .aiss-input, .aiss-select, .aiss-textarea { width: 100%; max-width: 500px; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; }
        .aiss-textarea { font-family: monospace; line-height: 1.5; }
        .aiss-btn { display: inline-flex; align-items: center; padding: 10px 20px; border-radius: 6px; background: var(--aiss-primary); color: #fff; text-decoration: none; border: none; cursor: pointer; font-size: 14px; }
        .aiss-btn:hover { background: var(--aiss-primary-hover); color: #fff; }
        .aiss-btn-danger { background: #dc2626; } .aiss-btn-danger:hover { background: #b91c1c; }
        .aiss-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .aiss-stat-box { background: #fff; padding: 20px; border-radius: 10px; border: 1px solid var(--aiss-border); }
        .aiss-stat-label { font-size: 12px; color: var(--aiss-text-muted); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 6px; }
        .aiss-stat-value { font-size: 20px; font-weight: 700; }
        .aiss-status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; }
        .dot-green { background: #10b981; } .dot-red { background: #ef4444; } .dot-gray { background: #9ca3af; }
        .aiss-notice { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid; background: #fff; }
        .notice-success { border-color: #10b981; background: #ecfdf5; color: #065f46; }
        .notice-error { border-color: #ef4444; background: #fef2f2; color: #991b1b; }
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

        // General
        if (isset($in['openrouter_api_key'])) $s['openrouter_api_key'] = trim((string)$in['openrouter_api_key']);
        if (isset($in['openrouter_model'])) $s['openrouter_model'] = trim((string)$in['openrouter_model']);
        
        // Scheduler
        if (isset($in['schedule_minutes'])) $s['schedule_minutes'] = max(5, (int)$in['schedule_minutes']);
        if (isset($in['max_posts_per_run'])) $s['max_posts_per_run'] = max(1, (int)$in['max_posts_per_run']);
        if (isset($in['filter_mode'])) $s['filter_mode'] = (string)$in['filter_mode'];
        if (isset($in['filter_terms'])) $s['filter_terms'] = sanitize_text_field((string)$in['filter_terms']);

        // Facebook
        if (isset($in['fb_app_id'])) $s['fb_app_id'] = sanitize_text_field((string)$in['fb_app_id']);
        if (isset($in['fb_app_secret'])) $s['fb_app_secret'] = sanitize_text_field((string)$in['fb_app_secret']);
        if (isset($in['prompt_facebook'])) $s['prompt_facebook'] = (string)$in['prompt_facebook'];
        // FB Pass-throughs
        if (isset($in['fb_page_id'])) $s['fb_page_id'] = sanitize_text_field((string)$in['fb_page_id']);
        if (isset($in['fb_page_name'])) $s['fb_page_name'] = sanitize_text_field((string)$in['fb_page_name']);
        if (isset($in['fb_page_token'])) $s['fb_page_token'] = (string)$in['fb_page_token'];

        // X (Twitter)
        $s['x_enabled'] = isset($in['x_enabled']); // Checkbox
        if (isset($in['x_consumer_key'])) $s['x_consumer_key'] = trim((string)$in['x_consumer_key']);
        if (isset($in['x_consumer_secret'])) $s['x_consumer_secret'] = trim((string)$in['x_consumer_secret']);
        if (isset($in['x_access_token'])) $s['x_access_token'] = trim((string)$in['x_access_token']);
        if (isset($in['x_access_secret'])) $s['x_access_secret'] = trim((string)$in['x_access_secret']);
        if (isset($in['prompt_x'])) $s['prompt_x'] = (string)$in['prompt_x'];

        return $s;
    }

    public function render() : void {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        $s = Utils::get_settings();
        
        echo '<div class="wrap aiss-wrap">';
        echo '<div class="aiss-header"><div class="aiss-icon-box"><span class="dashicons dashicons-share-alt2"></span></div><h1>AI Social Share</h1></div>';
        
        // Notices
        if (isset($_GET['aiss_notice'])) {
            $msg = '';
            $type = 'success';
            switch($_GET['aiss_notice']) {
                case 'page_selected': $msg = 'Facebook Page Connected!'; break;
                case 'cron_run': $msg = 'Cron triggered manually.'; break;
                case 'disconnected': $msg = 'Disconnected successfully.'; break;
                case 'page_error': $type='error'; $msg = 'Error: ' . urldecode($_GET['aiss_error']??''); break;
            }
            if ($msg) echo "<div class='aiss-notice notice-$type'><p>$msg</p></div>";
        }

        echo '<div class="aiss-tabs">';
        $this->tab('general', 'General', $tab);
        $this->tab('facebook', 'Facebook', $tab);
        $this->tab('x', 'X (Twitter)', $tab);
        $this->tab('scheduler', 'Scheduler', $tab);
        $this->tab('status', 'System Status', $tab);
        echo '</div>';

        if ($tab === 'general') $this->render_general($s);
        elseif ($tab === 'facebook') $this->render_facebook($s);
        elseif ($tab === 'x') $this->render_x($s);
        elseif ($tab === 'scheduler') $this->render_scheduler($s);
        else $this->render_status($s);
        
        echo '</div>';
    }

    private function tab($id, $label, $cur) {
        $cls = 'aiss-tab' . ($id === $cur ? ' aiss-tab-active' : '');
        echo '<a href="' . esc_url(Utils::admin_url_settings(['tab'=>$id])) . '" class="' . $cls . '">' . $label . '</a>';
    }

    private function render_general($s) {
        echo '<form method="post" action="options.php">'; settings_fields('aiss_settings_group');
        echo '<div class="aiss-card"><h2>AI Configuration</h2><table class="aiss-form-table">';
        echo '<tr><th>OpenRouter API Key</th><td><input type="password" class="aiss-input" name="aiss_settings[openrouter_api_key]" value="'.esc_attr($s['openrouter_api_key']).'"></td></tr>';
        echo '<tr><th>AI Model</th><td><select class="aiss-select" name="aiss_settings[openrouter_model]">';
        foreach(['openai/gpt-4o-mini'=>'GPT-4o Mini','openai/gpt-4o'=>'GPT-4o','anthropic/claude-3.5-sonnet'=>'Claude 3.5'] as $k=>$v)
            echo '<option value="'.$k.'" '.selected($s['openrouter_model'],$k,false).'>'.$v.'</option>';
        echo '</select></td></tr>';
        echo '</table></div>';
        echo '<button class="aiss-btn">Save Changes</button></form>';
    }

    private function render_facebook($s) {
        $con = $this->facebook->is_connected();
        echo '<form method="post" action="options.php">'; settings_fields('aiss_settings_group');
        echo '<div class="aiss-card"><h2>Facebook Connection</h2>';
        if ($con) {
            echo '<p style="color:green"><strong>✓ Connected to:</strong> '.esc_html($s['fb_page_name']).'</p>';
            echo '<a href="'.wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_disconnect'),'aiss_fb_disconnect').'" class="aiss-btn aiss-btn-danger">Disconnect</a>';
        } else {
            echo '<table class="aiss-form-table">';
            echo '<tr><th>App ID</th><td><input type="text" class="aiss-input" name="aiss_settings[fb_app_id]" value="'.esc_attr($s['fb_app_id']).'"></td></tr>';
            echo '<tr><th>App Secret</th><td><input type="password" class="aiss-input" name="aiss_settings[fb_app_secret]" value="'.esc_attr($s['fb_app_secret']).'"></td></tr>';
            echo '</table>';
            echo '<button class="aiss-btn">Save Credentials</button> ';
            if($s['fb_app_id']) echo '<a href="'.wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_start_connect'),'aiss_fb_connect').'" class="aiss-btn">Connect Page</a>';
        }
        echo '</div>';

        // Facebook Specific Prompt
        echo '<div class="aiss-card"><h2>Facebook Prompt</h2>';
        echo '<p class="description">Customize how AI writes for Facebook.</p>';
        echo '<textarea name="aiss_settings[prompt_facebook]" class="aiss-textarea" rows="5">'.esc_textarea($s['prompt_facebook']).'</textarea>';
        echo '<br><br><button class="aiss-btn">Save Facebook Settings</button>';
        echo '</div>';

        // Page Selector (if authorized but not selected)
        $pages = get_transient('aiss_fb_pages_'.get_current_user_id());
        if ($pages && !$con) {
            echo '<div class="aiss-card"><h2>Select Page</h2><select onchange="document.getElementById(\'fb_pg_id\').value=this.value">';
            foreach($pages as $p) echo '<option value="'.$p['id'].'">'.$p['name'].'</option>';
            echo '</select><form method="post" action="'.admin_url('admin-post.php').'" style="display:inline"><input type="hidden" name="action" value="aiss_fb_select_page">';
            wp_nonce_field('aiss_fb_select_page','aiss_fb_select_page_nonce');
            echo '<input type="hidden" name="page_id" id="fb_pg_id" value="'.$pages[0]['id'].'"><button class="aiss-btn">Use Page</button></form></div>';
        }
        echo '</form>';
    }

    private function render_x($s) {
        echo '<form method="post" action="options.php">'; settings_fields('aiss_settings_group');
        
        echo '<div class="aiss-card"><h2>X (Twitter) Connection</h2>';
        echo '<p><label><input type="checkbox" name="aiss_settings[x_enabled]" value="1" '.checked($s['x_enabled'],true,false).'> <strong>Enable X Sharing</strong></label></p>';
        echo '<table class="aiss-form-table">';
        echo '<tr><th>API Key</th><td><input type="text" class="aiss-input" name="aiss_settings[x_consumer_key]" value="'.esc_attr($s['x_consumer_key']).'"></td></tr>';
        echo '<tr><th>API Secret</th><td><input type="password" class="aiss-input" name="aiss_settings[x_consumer_secret]" value="'.esc_attr($s['x_consumer_secret']).'"></td></tr>';
        echo '<tr><th>Access Token</th><td><input type="text" class="aiss-input" name="aiss_settings[x_access_token]" value="'.esc_attr($s['x_access_token']).'"></td></tr>';
        echo '<tr><th>Access Secret</th><td><input type="password" class="aiss-input" name="aiss_settings[x_access_secret]" value="'.esc_attr($s['x_access_secret']).'"></td></tr>';
        echo '</table></div>';

        // X Specific Prompt
        echo '<div class="aiss-card"><h2>X (Twitter) Prompt</h2>';
        echo '<p class="description">Customize how AI writes for X (keep it short!).</p>';
        echo '<textarea name="aiss_settings[prompt_x]" class="aiss-textarea" rows="4">'.esc_textarea($s['prompt_x']).'</textarea>';
        echo '</div>';

        echo '<button class="aiss-btn">Save X Settings</button></form>';
    }

    private function render_scheduler($s) {
        echo '<form method="post" action="options.php">'; settings_fields('aiss_settings_group');
        echo '<div class="aiss-card"><h2>Schedule Settings</h2><table class="aiss-form-table">';
        echo '<tr><th>Run Every</th><td><input type="number" class="aiss-input" name="aiss_settings[schedule_minutes]" value="'.(int)$s['schedule_minutes'].'"> minutes</td></tr>';
        echo '<tr><th>Posts per Batch</th><td><input type="number" class="aiss-input" name="aiss_settings[max_posts_per_run]" value="'.(int)$s['max_posts_per_run'].'"></td></tr>';
        echo '</table></div>';
        echo '<button class="aiss-btn">Save Schedule</button></form>';
    }

    private function render_status($s) {
        $fb = $this->facebook->is_connected();
        $x = $this->x->is_connected();
        $next = wp_next_scheduled(Cron::HOOK);
        
        echo '<div class="aiss-stats-grid">';
        
        // Status Boxes
        echo '<div class="aiss-stat-box"><div class="aiss-stat-label">Facebook</div><div class="aiss-stat-value">';
        echo '<span class="aiss-status-dot '.($fb?'dot-green':'dot-red').'"></span> '.($fb?'Active':'Offline');
        echo '</div></div>';

        echo '<div class="aiss-stat-box"><div class="aiss-stat-label">X (Twitter)</div><div class="aiss-stat-value">';
        echo '<span class="aiss-status-dot '.($x?'dot-green':'dot-gray').'"></span> '.($x?'Active':'Disabled');
        echo '</div></div>';

        echo '<div class="aiss-stat-box"><div class="aiss-stat-label">Next Run</div><div class="aiss-stat-value">';
        echo $next ? date_i18n('H:i:s', $next) : 'Not Scheduled';
        echo '</div><div style="font-size:12px;color:#666">Frequency: '.(int)$s['schedule_minutes'].'m</div></div>';

        echo '</div>'; // end grid

        echo '<div class="aiss-card"><h2>Detailed Status</h2>';
        echo '<table class="aiss-form-table">';
        echo '<tr><th>Cron Event:</th><td><code>'.Cron::HOOK.'</code></td></tr>';
        echo '<tr><th>Batch Size:</th><td>'.(int)$s['max_posts_per_run'].' posts per run</td></tr>';
        echo '<tr><th>AI Model:</th><td>'.esc_html($s['openrouter_model']).'</td></tr>';
        echo '<tr><th>Filter:</th><td>'.ucfirst($s['filter_mode']).' '.($s['filter_terms'] ? '('.esc_html($s['filter_terms']).')' : '').'</td></tr>';
        echo '</table>';
        
        echo '<p style="margin-top:20px"><a href="'.wp_nonce_url(admin_url('admin-post.php?action=aiss_run_cron'),'aiss_run_cron').'" class="aiss-btn">⚡ Run Cron Now</a></p>';
        echo '</div>';
    }

    // Handlers (Start connect, select page, disconnect) remain largely same
    public function start_fb_connect() { check_admin_referer('aiss_fb_connect'); wp_redirect($this->facebook->build_oauth_url()); exit; }
    public function select_fb_page() { 
        check_admin_referer('aiss_fb_select_page', 'aiss_fb_select_page_nonce');
        $res = $this->facebook->select_page_and_store($_POST['page_id'] ?? '');
        delete_transient('aiss_fb_pages_' . get_current_user_id());
        wp_redirect(Utils::admin_url_settings(['tab'=>'facebook','aiss_notice'=>$res['ok']?'page_selected':'page_error'])); exit; 
    }
    public function disconnect_facebook() { 
        check_admin_referer('aiss_fb_disconnect');
        $s = Utils::get_settings(); $s['fb_page_id']=''; $s['fb_page_token']=''; update_option('aiss_settings', $s);
        wp_redirect(Utils::admin_url_settings(['tab'=>'facebook','aiss_notice'=>'disconnected'])); exit;
    }
    public function run_cron_manually() {
        check_admin_referer('aiss_run_cron');
        $this->cron->run();
        wp_redirect(Utils::admin_url_settings(['tab'=>'status','aiss_notice'=>'cron_run'])); exit;
    }
}