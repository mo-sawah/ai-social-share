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
        
        // --- Form Handlers ---
        add_action('admin_post_aiss_fb_start_connect', [$this, 'start_fb_connect']);
        add_action('admin_post_aiss_fb_select_page', [$this, 'select_fb_page']);
        add_action('admin_post_aiss_fb_disconnect', [$this, 'disconnect_facebook']);
        
        // X (Twitter) Handlers
        add_action('admin_post_aiss_x_connect', [$this, 'connect_x']);
        add_action('admin_post_aiss_x_callback', [$this, 'callback_x']);
        add_action('admin_post_aiss_x_disconnect', [$this, 'disconnect_x']);
        
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
        if ($hook !== 'settings_page_ai-social-share') { return; }
        
        // Full, rich CSS for the settings page
        echo <<<'CSS'
        <style>
        :root {
            --aiss-primary: #2563eb; --aiss-primary-hover: #1d4ed8;
            --aiss-bg: #f3f4f6; --aiss-text: #111827; --aiss-muted: #6b7280; --aiss-border: #e5e7eb;
            --aiss-success-bg: #ecfdf5; --aiss-success-text: #059669;
            --aiss-error-bg: #fef2f2; --aiss-error-text: #b91c1c;
        }
        .aiss-wrap { max-width: 1200px; margin: 20px auto; font-family: -apple-system, system-ui, sans-serif; color: var(--aiss-text); }
        .aiss-header { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; }
        .aiss-icon-box { background: var(--aiss-primary); color: #fff; width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
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

        /* Status Grid */
        .aiss-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .aiss-stat-box { background: #fff; padding: 20px; border-radius: 10px; border: 1px solid var(--aiss-border); }
        .aiss-stat-label { font-size: 12px; color: var(--aiss-muted); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 6px; }
        .aiss-stat-value { font-size: 20px; font-weight: 700; display: flex; align-items: center; }
        .aiss-status-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 8px; }
        .dot-green { background: #10b981; } .dot-red { background: #ef4444; } .dot-gray { background: #9ca3af; }

        /* Notices */
        .aiss-notice { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid; background: #fff; }
        .notice-success { border-color: #10b981; background: var(--aiss-success-bg); color: var(--aiss-success-text); }
        .notice-error { border-color: #ef4444; background: var(--aiss-error-bg); color: var(--aiss-error-text); }
        
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
        $s = Utils::get_settings(); $in = (array)$input;

        // General
        if (isset($in['openrouter_api_key'])) $s['openrouter_api_key'] = trim($in['openrouter_api_key']);
        if (isset($in['openrouter_model'])) $s['openrouter_model'] = trim($in['openrouter_model']);
        $s['enable_web_search'] = isset($in['enable_web_search']); // Checkbox

        // Scheduler
        if (isset($in['schedule_minutes'])) $s['schedule_minutes'] = max(5, (int)$in['schedule_minutes']);
        if (isset($in['max_posts_per_run'])) $s['max_posts_per_run'] = max(1, (int)$in['max_posts_per_run']);
        if (isset($in['filter_mode'])) $s['filter_mode'] = sanitize_key($in['filter_mode']);
        if (isset($in['filter_terms'])) $s['filter_terms'] = sanitize_text_field($in['filter_terms']);

        // Facebook
        if (isset($in['fb_app_id'])) $s['fb_app_id'] = sanitize_text_field($in['fb_app_id']);
        if (isset($in['fb_app_secret'])) $s['fb_app_secret'] = sanitize_text_field($in['fb_app_secret']);
        if (isset($in['prompt_facebook'])) $s['prompt_facebook'] = wp_kses_post($in['prompt_facebook']);
        // Pass-through connection data
        if (isset($in['fb_page_id'])) $s['fb_page_id'] = sanitize_text_field($in['fb_page_id']);
        if (isset($in['fb_page_name'])) $s['fb_page_name'] = sanitize_text_field($in['fb_page_name']);
        if (isset($in['fb_page_token'])) $s['fb_page_token'] = $in['fb_page_token'];

        // X (Twitter)
        $s['x_enabled'] = isset($in['x_enabled']);
        if (isset($in['x_consumer_key'])) $s['x_consumer_key'] = trim($in['x_consumer_key']);
        if (isset($in['x_consumer_secret'])) $s['x_consumer_secret'] = trim($in['x_consumer_secret']);
        if (isset($in['prompt_x'])) $s['prompt_x'] = wp_kses_post($in['prompt_x']);
        // Pass-through X User tokens
        if (isset($in['x_access_token'])) $s['x_access_token'] = $in['x_access_token'];
        if (isset($in['x_access_secret'])) $s['x_access_secret'] = $in['x_access_secret'];
        if (isset($in['x_username'])) $s['x_username'] = $in['x_username'];

        return $s;
    }

    public function render() : void {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        $s = Utils::get_settings();
        
        echo '<div class="wrap aiss-wrap">';
        echo '<div class="aiss-header"><div class="aiss-icon-box"><span class="dashicons dashicons-share-alt2"></span></div><h1>AI Social Share</h1></div>';
        
        // --- Notices ---
        if (isset($_GET['aiss_notice'])) {
            $n = $_GET['aiss_notice']; $m=''; $t='success';
            if ($n==='x_connected') $m = 'X (Twitter) Connected Successfully!';
            elseif ($n==='page_selected') $m = 'Facebook Page Connected Successfully!';
            elseif ($n==='cron_run') $m = 'Cron triggered manually. Checking for posts...';
            elseif ($n==='disconnected') $m = 'Account disconnected successfully.';
            elseif ($n==='x_error' || $n==='page_error') { $t='error'; $m='Error: '.urldecode($_GET['aiss_error']??'Unknown'); }
            
            if ($m) echo "<div class='aiss-notice notice-$t'>$m</div>";
        }

        // --- Tabs ---
        echo '<div class="aiss-tabs">';
        $tabs = ['general'=>'Settings', 'facebook'=>'Facebook', 'x'=>'X (Twitter)', 'scheduler'=>'Scheduler', 'status'=>'System Status'];
        foreach ($tabs as $k => $v) {
            $cls = 'aiss-tab' . ($tab === $k ? ' aiss-tab-active' : '');
            echo '<a href="' . esc_url(Utils::admin_url_settings(['tab'=>$k])) . '" class="' . $cls . '">' . $v . '</a>';
        }
        echo '</div>';

        if ($tab === 'general') $this->render_general($s);
        elseif ($tab === 'facebook') $this->render_facebook($s);
        elseif ($tab === 'x') $this->render_x($s);
        elseif ($tab === 'scheduler') $this->render_scheduler($s);
        else $this->render_status($s);
        
        echo '</div>';
    }

    private function render_general($s) {
        echo '<form method="post" action="options.php">'; settings_fields('aiss_settings_group');
        echo '<div class="aiss-card"><h2>AI Configuration</h2><p class="description">Configure the OpenRouter API connection.</p>';
        echo '<table class="aiss-form-table">';
        echo '<tr><th>API Key</th><td><input type="password" class="aiss-input" name="aiss_settings[openrouter_api_key]" value="'.esc_attr($s['openrouter_api_key']).'"></td></tr>';
        echo '<tr><th>Model</th><td><select class="aiss-select" name="aiss_settings[openrouter_model]">';
        $models = ['openai/gpt-4o-mini'=>'GPT-4o Mini','openai/gpt-4o'=>'GPT-4o','anthropic/claude-3.5-sonnet'=>'Claude 3.5 Sonnet','perplexity/sonar-small-online'=>'Perplexity (Online)'];
        foreach($models as $k=>$v) echo '<option value="'.$k.'" '.selected($s['openrouter_model'],$k,false).'>'.$v.'</option>';
        echo '</select></td></tr>';
        echo '<tr><th>Web Search</th><td><label><input type="checkbox" name="aiss_settings[enable_web_search]" value="1" '.checked($s['enable_web_search'],true,false).'> <strong>Enable Web Search</strong> (AI will read recent news)</label></td></tr>';
        echo '</table></div><button class="aiss-btn">Save General Settings</button></form>';
    }

    private function render_facebook($s) {
        $con = $this->facebook->is_connected();
        echo '<form method="post" action="options.php">'; settings_fields('aiss_settings_group');
        
        echo '<div class="aiss-card"><h2>Facebook Connection</h2>';
        if ($con) {
            echo '<div class="aiss-notice notice-success" style="margin-top:0"><p><strong>✓ Connected to Page:</strong> '.esc_html($s['fb_page_name']).'</p></div>';
            echo '<a href="'.wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_disconnect'),'aiss_fb_disconnect').'" class="aiss-btn aiss-btn-danger">Disconnect Facebook</a>';
        } else {
            echo '<table class="aiss-form-table">';
            echo '<tr><th>App ID</th><td><input class="aiss-input" name="aiss_settings[fb_app_id]" value="'.esc_attr($s['fb_app_id']).'"></td></tr>';
            echo '<tr><th>App Secret</th><td><input type="password" class="aiss-input" name="aiss_settings[fb_app_secret]" value="'.esc_attr($s['fb_app_secret']).'"></td></tr>';
            echo '</table><br><button class="aiss-btn aiss-btn-secondary">Save Credentials</button> ';
            if ($s['fb_app_id']) echo '<a href="'.wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_start_connect'),'aiss_fb_connect').'" class="aiss-btn">Connect Page</a>';
        }
        echo '</div>';
        
        echo '<div class="aiss-card"><h2>Prompt</h2><p class="description">Customize how AI writes for Facebook.</p><textarea name="aiss_settings[prompt_facebook]" class="aiss-textarea" rows="5">'.esc_textarea($s['prompt_facebook']).'</textarea></div>';
        
        // Page Selection if Authorized
        $pages = get_transient('aiss_fb_pages_'.get_current_user_id());
        if ($pages && !$con) {
            echo '<div class="aiss-card"><h3>Select Page</h3><select id="fb_pg_sel" class="aiss-select">'; foreach($pages as $p) echo '<option value="'.$p['id'].'">'.$p['name'].'</option>'; echo '</select>';
            echo '<br><br><button type="button" class="aiss-btn" onclick="document.getElementById(\'fb_pg_id\').value=document.getElementById(\'fb_pg_sel\').value;document.getElementById(\'fb_pg_form\').submit();">Use This Page</button>';
            echo '<form id="fb_pg_form" method="post" action="'.admin_url('admin-post.php').'"><input type="hidden" name="action" value="aiss_fb_select_page">';
            wp_nonce_field('aiss_fb_select_page','aiss_fb_select_page_nonce'); echo '<input type="hidden" name="page_id" id="fb_pg_id" value="'.$pages[0]['id'].'"></form></div>';
        }
        echo '<button class="aiss-btn">Save Facebook Settings</button></form>';
    }

    private function render_x($s) {
        $con = $this->x->is_connected();
        echo '<form method="post" action="options.php">'; settings_fields('aiss_settings_group');
        
        echo '<div class="aiss-card"><h2>X (Twitter) Connection</h2>';
        echo '<p style="margin-bottom:16px"><label><input type="checkbox" name="aiss_settings[x_enabled]" value="1" '.checked($s['x_enabled'],true,false).'> <strong>Enable X Sharing</strong></label></p>';
        
        echo '<div style="background:#f9fafb; padding:16px; border-radius:8px; border:1px solid #e5e7eb; margin-bottom:20px;">';
        echo '<h3 style="margin-top:0">App Credentials (Developer)</h3>';
        echo '<table class="aiss-form-table">';
        echo '<tr><th>App Key (Consumer)</th><td><input class="aiss-input" name="aiss_settings[x_consumer_key]" value="'.esc_attr($s['x_consumer_key']).'"></td></tr>';
        echo '<tr><th>App Secret</th><td><input type="password" class="aiss-input" name="aiss_settings[x_consumer_secret]" value="'.esc_attr($s['x_consumer_secret']).'"></td></tr>';
        echo '</table>';
        // Hidden User Tokens
        echo '<input type="hidden" name="aiss_settings[x_access_token]" value="'.esc_attr($s['x_access_token']).'">';
        echo '<input type="hidden" name="aiss_settings[x_access_secret]" value="'.esc_attr($s['x_access_secret']).'">';
        echo '<input type="hidden" name="aiss_settings[x_username]" value="'.esc_attr($s['x_username']??'').'">';
        echo '<br><button class="aiss-btn aiss-btn-secondary">Save App Keys</button>';
        echo '</div>';

        echo '<h3>Account Connection</h3>';
        if ($con) {
            echo '<div class="aiss-notice notice-success"><p><strong>✓ Connected as @'.esc_html($s['x_username']).'</strong></p></div>';
            echo '<a href="'.wp_nonce_url(admin_url('admin-post.php?action=aiss_x_disconnect'),'aiss_x_disconnect').'" class="aiss-btn aiss-btn-danger">Disconnect Account</a>';
        } elseif ($s['x_consumer_key'] && $s['x_consumer_secret']) {
            echo '<p class="description">App keys saved. You can now connect the client account.</p>';
            echo '<a href="'.wp_nonce_url(admin_url('admin-post.php?action=aiss_x_connect'),'aiss_x_connect').'" class="aiss-btn aiss-btn-black">Connect with X (Twitter)</a>';
        } else {
            echo '<p style="color:#666">Please save your App Key & Secret above first.</p>';
        }
        echo '</div>';

        echo '<div class="aiss-card"><h2>Prompt</h2><p class="description">Customize how AI writes for X.</p><textarea name="aiss_settings[prompt_x]" class="aiss-textarea" rows="4">'.esc_textarea($s['prompt_x']).'</textarea></div>';
        echo '<button class="aiss-btn">Save X Settings</button></form>';
    }

    private function render_scheduler($s) {
        echo '<form method="post" action="options.php">'; settings_fields('aiss_settings_group');
        echo '<div class="aiss-card"><h2>Schedule Settings</h2><p class="description">Control frequency and batch size.</p><table class="aiss-form-table">';
        echo '<tr><th>Check Every</th><td><input type="number" class="aiss-input" name="aiss_settings[schedule_minutes]" value="'.(int)$s['schedule_minutes'].'" min="5" style="width:100px"> minutes</td></tr>';
        echo '<tr><th>Posts per Batch</th><td><input type="number" class="aiss-input" name="aiss_settings[max_posts_per_run]" value="'.(int)$s['max_posts_per_run'].'" min="1" style="width:100px"></td></tr>';
        echo '</table></div>';
        
        echo '<div class="aiss-card"><h2>Filters</h2><p class="description">Limit which posts are auto-shared.</p><table class="aiss-form-table">';
        echo '<tr><th>Filter Mode</th><td><select class="aiss-select" name="aiss_settings[filter_mode]">';
        foreach(['all'=>'All Posts','category'=>'Specific Category','tag'=>'Specific Tag'] as $k=>$v) echo '<option value="'.$k.'" '.selected($s['filter_mode'],$k,false).'>'.$v.'</option>';
        echo '</select></td></tr>';
        echo '<tr><th>Slugs</th><td><input class="aiss-input" name="aiss_settings[filter_terms]" value="'.esc_attr($s['filter_terms']).'" placeholder="news, tech"></td></tr>';
        echo '</table></div><button class="aiss-btn">Save Schedule</button></form>';
    }

    private function render_status($s) {
        $fb=$this->facebook->is_connected(); $x=$this->x->is_connected(); $nxt=wp_next_scheduled(Cron::HOOK);
        echo '<div class="aiss-stats-grid">';
        echo '<div class="aiss-stat-box"><div class="aiss-stat-label">Facebook</div><div class="aiss-stat-value"><span class="aiss-status-dot '.($fb?'dot-green':'dot-red').'"></span>'.($fb?'Active':'Offline').'</div></div>';
        echo '<div class="aiss-stat-box"><div class="aiss-stat-label">X (Twitter)</div><div class="aiss-stat-value"><span class="aiss-status-dot '.($x?'dot-green':'dot-gray').'"></span>'.($x?'Active':'Disabled').'</div></div>';
        echo '<div class="aiss-stat-box"><div class="aiss-stat-label">Next Run</div><div class="aiss-stat-value">'.($nxt?date_i18n('H:i:s',$nxt):'Not Scheduled').'</div></div>';
        echo '</div>';

        echo '<div class="aiss-card"><h2>System Overview</h2>';
        echo '<table class="aiss-form-table" style="border-top:1px solid #eee">';
        echo '<tr><th>Cron Schedule</th><td>Every <strong>'.(int)$s['schedule_minutes'].'</strong> minutes</td></tr>';
        echo '<tr><th>Batch Size</th><td><strong>'.(int)$s['max_posts_per_run'].'</strong> posts per run</td></tr>';
        echo '<tr><th>Filter</th><td>'.ucfirst($s['filter_mode']).' '.($s['filter_terms']?'('.esc_html($s['filter_terms']).')':'').'</td></tr>';
        $last = get_option('aiss_last_run_time');
        echo '<tr><th>Last Run</th><td>'.($last ? date_i18n('Y-m-d H:i:s', $last) : 'Never').'</td></tr>';
        echo '</table>';
        echo '<p style="margin-top:24px"><a href="'.wp_nonce_url(admin_url('admin-post.php?action=aiss_run_cron'),'aiss_run_cron').'" class="aiss-btn aiss-btn-secondary">⚡ Run Cron Now</a></p>';
        echo '</div>';
    }

    // --- Action Handlers ---
    
    // Facebook
    public function start_fb_connect() { check_admin_referer('aiss_fb_connect'); wp_redirect($this->facebook->build_oauth_url()); exit; }
    public function select_fb_page() { 
        check_admin_referer('aiss_fb_select_page','aiss_fb_select_page_nonce'); 
        $this->facebook->select_page_and_store($_POST['page_id']??''); 
        delete_transient('aiss_fb_pages_'.get_current_user_id()); 
        wp_redirect(Utils::admin_url_settings(['tab'=>'facebook','aiss_notice'=>'page_selected'])); exit; 
    }
    public function disconnect_facebook() { 
        check_admin_referer('aiss_fb_disconnect'); $s=Utils::get_settings(); $s['fb_page_id']=''; update_option('aiss_settings',$s); 
        wp_redirect(Utils::admin_url_settings(['tab'=>'facebook','aiss_notice'=>'disconnected'])); exit; 
    }

    // X (Twitter)
    public function connect_x() {
        check_admin_referer('aiss_x_connect');
        $res = $this->x->get_auth_url();
        if (!$res['ok']) wp_die($res['error']);
        wp_redirect($res['url']); exit;
    }
    public function callback_x() {
        if (!isset($_GET['oauth_token']) || !isset($_GET['oauth_verifier'])) wp_die('Invalid Callback');
        $res = $this->x->handle_callback($_GET['oauth_token'], $_GET['oauth_verifier']);
        if ($res['ok']) {
            $s = Utils::get_settings();
            $s['x_access_token'] = $res['token'];
            $s['x_access_secret'] = $res['secret'];
            $s['x_username'] = $res['screen_name'];
            Utils::update_settings($s);
            wp_redirect(Utils::admin_url_settings(['tab'=>'x','aiss_notice'=>'x_connected']));
        } else {
            wp_redirect(Utils::admin_url_settings(['tab'=>'x','aiss_notice'=>'x_error','aiss_error'=>urlencode($res['error'])]));
        }
        exit;
    }
    public function disconnect_x() {
        check_admin_referer('aiss_x_disconnect'); $s=Utils::get_settings(); 
        $s['x_access_token']=''; $s['x_access_secret']=''; $s['x_username']=''; 
        Utils::update_settings($s); 
        wp_redirect(Utils::admin_url_settings(['tab'=>'x','aiss_notice'=>'disconnected'])); exit;
    }

    // Cron
    public function run_cron_manually() {
        check_admin_referer('aiss_run_cron'); $this->cron->run();
        wp_redirect(Utils::admin_url_settings(['tab'=>'status','aiss_notice'=>'cron_run'])); exit;
    }
}