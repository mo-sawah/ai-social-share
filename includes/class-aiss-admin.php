<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Admin {
    private $cron, $facebook, $x;

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
        if ($hook !== 'settings_page_ai-social-share') return;
        echo <<<'CSS'
        <style>
        :root { --aiss-primary: #2563eb; --aiss-bg: #f3f4f6; --aiss-text: #111827; --aiss-muted: #6b7280; --aiss-border: #e5e7eb; }
        .aiss-wrap { max-width: 1200px; margin: 20px auto; font-family: -apple-system, system-ui, sans-serif; color: var(--aiss-text); }
        .aiss-header { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; }
        .aiss-icon { background: var(--aiss-primary); color:#fff; width:40px; height:40px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:20px; }
        .aiss-tabs { display: flex; gap: 4px; background: #fff; padding: 4px; border-radius: 10px; border: 1px solid var(--aiss-border); margin-bottom: 24px; width: fit-content; }
        .aiss-tab { padding: 8px 16px; border-radius: 6px; text-decoration: none; color: var(--aiss-muted); font-weight: 500; font-size: 14px; transition: all 0.2s; }
        .aiss-tab:hover { color: var(--aiss-text); background: #f3f4f6; } /* CSS Hover Fix */
        .aiss-tab-active { background: var(--aiss-primary); color: #fff !important; }
        .aiss-card { background: #fff; border: 1px solid var(--aiss-border); border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .aiss-card h2 { margin: 0 0 16px 0; font-size: 18px; font-weight: 600; }
        .aiss-form-table th { text-align: left; padding: 12px 24px 12px 0; width: 200px; vertical-align: top; }
        .aiss-input, .aiss-select, .aiss-textarea { width: 100%; max-width: 500px; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; }
        .aiss-textarea { font-family: monospace; line-height: 1.5; }
        .aiss-btn { display: inline-flex; align-items: center; padding: 10px 20px; border-radius: 6px; background: var(--aiss-primary); color: #fff; text-decoration: none; border: none; cursor: pointer; font-size: 14px; }
        .aiss-btn-danger { background: #dc2626; }
        .aiss-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .aiss-stat-box { background: #fff; padding: 20px; border-radius: 10px; border: 1px solid var(--aiss-border); }
        .aiss-status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; }
        .dot-green { background: #10b981; } .dot-red { background: #ef4444; } .dot-gray { background: #9ca3af; }
        .aiss-notice { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid; background: #fff; }
        .notice-success { border-color: #10b981; background: #ecfdf5; color: #065f46; }
        .notice-error { border-color: #ef4444; background: #fef2f2; color: #991b1b; }
        .settings_page_ai-social-share .notice:not(.aiss-notice) { display: none !important; }
        </style>
CSS;
    }

    public function register_settings() : void {
        register_setting('aiss_settings_group', 'aiss_settings', ['type'=>'array','sanitize_callback'=>[$this,'sanitize'],'default'=>Utils::get_settings()]);
    }

    public function sanitize($in) : array {
        $s = Utils::get_settings(); $in = (array)$in;
        // General
        if (isset($in['openrouter_api_key'])) $s['openrouter_api_key'] = trim($in['openrouter_api_key']);
        if (isset($in['openrouter_model'])) $s['openrouter_model'] = trim($in['openrouter_model']);
        // Scheduler
        if (isset($in['schedule_minutes'])) $s['schedule_minutes'] = max(5, (int)$in['schedule_minutes']);
        if (isset($in['max_posts_per_run'])) $s['max_posts_per_run'] = max(1, (int)$in['max_posts_per_run']);
        if (isset($in['filter_mode'])) $s['filter_mode'] = $in['filter_mode'];
        if (isset($in['filter_terms'])) $s['filter_terms'] = sanitize_text_field($in['filter_terms']);
        // Facebook
        if (isset($in['fb_app_id'])) $s['fb_app_id'] = sanitize_text_field($in['fb_app_id']);
        if (isset($in['fb_app_secret'])) $s['fb_app_secret'] = sanitize_text_field($in['fb_app_secret']);
        if (isset($in['prompt_facebook'])) $s['prompt_facebook'] = $in['prompt_facebook'];
        if (isset($in['fb_page_id'])) $s['fb_page_id'] = sanitize_text_field($in['fb_page_id']);
        if (isset($in['fb_page_name'])) $s['fb_page_name'] = sanitize_text_field($in['fb_page_name']);
        if (isset($in['fb_page_token'])) $s['fb_page_token'] = $in['fb_page_token'];
        // X
        $s['x_enabled'] = isset($in['x_enabled']);
        if (isset($in['x_consumer_key'])) $s['x_consumer_key'] = trim($in['x_consumer_key']);
        if (isset($in['x_consumer_secret'])) $s['x_consumer_secret'] = trim($in['x_consumer_secret']);
        if (isset($in['x_access_token'])) $s['x_access_token'] = trim($in['x_access_token']);
        if (isset($in['x_access_secret'])) $s['x_access_secret'] = trim($in['x_access_secret']);
        if (isset($in['prompt_x'])) $s['prompt_x'] = $in['prompt_x'];

        return $s;
    }

    public function render() : void {
        $tab = $_GET['tab'] ?? 'general'; $s = Utils::get_settings();
        echo '<div class="wrap aiss-wrap"><div class="aiss-header"><div class="aiss-icon"><span class="dashicons dashicons-share-alt2"></span></div><h1>AI Social Share</h1></div>';
        
        if (isset($_GET['aiss_notice'])) {
            $n = $_GET['aiss_notice']; $t='success'; $m='';
            if($n=='page_selected') $m='Facebook Connected!'; elseif($n=='page_error') {$t='error';$m=urldecode($_GET['aiss_error']??'');} 
            elseif($n=='cron_run') $m='Cron triggered manually.'; elseif($n=='disconnected') $m='Disconnected.';
            if($m) echo "<div class='aiss-notice notice-$t'>$m</div>";
        }

        echo '<div class="aiss-tabs">';
        foreach(['general'=>'Settings','facebook'=>'Facebook','x'=>'X (Twitter)','scheduler'=>'Scheduler','status'=>'Status'] as $k=>$v)
            echo '<a href="'.esc_url(Utils::admin_url_settings(['tab'=>$k])).'" class="aiss-tab '.($tab==$k?'aiss-tab-active':'').'">'.$v.'</a>';
        echo '</div>';

        if ($tab=='general') $this->tab_general($s);
        elseif ($tab=='facebook') $this->tab_facebook($s);
        elseif ($tab=='x') $this->tab_x($s);
        elseif ($tab=='scheduler') $this->tab_scheduler($s);
        else $this->tab_status($s);
        echo '</div>';
    }

    private function tab_general($s) {
        echo '<form method="post" action="options.php">'; settings_fields('aiss_settings_group');
        echo '<div class="aiss-card"><h2>AI Settings</h2><table class="aiss-form-table">';
        echo '<tr><th>API Key</th><td><input type="password" class="aiss-input" name="aiss_settings[openrouter_api_key]" value="'.esc_attr($s['openrouter_api_key']).'"></td></tr>';
        echo '<tr><th>Model</th><td><select class="aiss-select" name="aiss_settings[openrouter_model]">';
        foreach(['openai/gpt-4o-mini'=>'GPT-4o Mini','openai/gpt-4o'=>'GPT-4o','anthropic/claude-3.5-sonnet'=>'Claude 3.5'] as $k=>$v) echo '<option value="'.$k.'" '.selected($s['openrouter_model'],$k,false).'>'.$v.'</option>';
        echo '</select></td></tr></table></div><button class="aiss-btn">Save</button></form>';
    }

    private function tab_facebook($s) {
        $con = $this->facebook->is_connected();
        echo '<form method="post" action="options.php">'; settings_fields('aiss_settings_group');
        echo '<div class="aiss-card"><h2>Facebook Connection</h2>';
        if ($con) echo '<p style="color:green"><strong>✓ Connected:</strong> '.esc_html($s['fb_page_name']).'</p><a href="'.wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_disconnect'),'aiss_fb_disconnect').'" class="aiss-btn aiss-btn-danger">Disconnect</a>';
        else {
            echo '<table class="aiss-form-table"><tr><th>App ID</th><td><input class="aiss-input" name="aiss_settings[fb_app_id]" value="'.esc_attr($s['fb_app_id']).'"></td></tr>';
            echo '<tr><th>App Secret</th><td><input type="password" class="aiss-input" name="aiss_settings[fb_app_secret]" value="'.esc_attr($s['fb_app_secret']).'"></td></tr></table>';
            echo '<button class="aiss-btn">Save</button> '.($s['fb_app_id']?'<a href="'.wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_start_connect'),'aiss_fb_connect').'" class="aiss-btn">Connect</a>':'');
        }
        echo '</div><div class="aiss-card"><h2>Prompt</h2><textarea name="aiss_settings[prompt_facebook]" class="aiss-textarea" rows="4">'.esc_textarea($s['prompt_facebook']).'</textarea><br><br><button class="aiss-btn">Save Prompt</button></div>';
        
        $pages = get_transient('aiss_fb_pages_'.get_current_user_id());
        if($pages && !$con) {
            echo '<div class="aiss-card"><h2>Select Page</h2><select onchange="document.getElementById(\'pid\').value=this.value">'; foreach($pages as $p) echo '<option value="'.$p['id'].'">'.$p['name'].'</option>'; echo '</select>';
            echo '<form method="post" action="'.admin_url('admin-post.php').'" style="display:inline"><input type="hidden" name="action" value="aiss_fb_select_page">'; wp_nonce_field('aiss_fb_select_page','aiss_fb_select_page_nonce');
            echo '<input type="hidden" name="page_id" id="pid" value="'.$pages[0]['id'].'"><button class="aiss-btn">Use Page</button></form></div>';
        }
        echo '</form>';
    }

    private function tab_x($s) {
        echo '<form method="post" action="options.php">'; settings_fields('aiss_settings_group');
        echo '<div class="aiss-card"><h2>X (Twitter) Connection</h2><p><label><input type="checkbox" name="aiss_settings[x_enabled]" value="1" '.checked($s['x_enabled'],true,false).'> Enable X</label></p>';
        echo '<table class="aiss-form-table">';
        echo '<tr><th>API Key</th><td><input class="aiss-input" name="aiss_settings[x_consumer_key]" value="'.esc_attr($s['x_consumer_key']).'"></td></tr>';
        echo '<tr><th>API Secret</th><td><input type="password" class="aiss-input" name="aiss_settings[x_consumer_secret]" value="'.esc_attr($s['x_consumer_secret']).'"></td></tr>';
        echo '<tr><th>Access Token</th><td><input class="aiss-input" name="aiss_settings[x_access_token]" value="'.esc_attr($s['x_access_token']).'"></td></tr>';
        echo '<tr><th>Access Secret</th><td><input type="password" class="aiss-input" name="aiss_settings[x_access_secret]" value="'.esc_attr($s['x_access_secret']).'"></td></tr>';
        echo '</table></div><div class="aiss-card"><h2>Prompt</h2><textarea name="aiss_settings[prompt_x]" class="aiss-textarea" rows="3">'.esc_textarea($s['prompt_x']).'</textarea><br><br><button class="aiss-btn">Save X Settings</button></div></form>';
    }

    private function tab_scheduler($s) {
        echo '<form method="post" action="options.php">'; settings_fields('aiss_settings_group');
        echo '<div class="aiss-card"><h2>Schedule</h2><table class="aiss-form-table"><tr><th>Minutes</th><td><input type="number" class="aiss-input" name="aiss_settings[schedule_minutes]" value="'.(int)$s['schedule_minutes'].'"></td></tr>';
        echo '<tr><th>Posts/Run</th><td><input type="number" class="aiss-input" name="aiss_settings[max_posts_per_run]" value="'.(int)$s['max_posts_per_run'].'"></td></tr></table></div><button class="aiss-btn">Save</button></form>';
    }

    private function tab_status($s) {
        $fb=$this->facebook->is_connected(); $x=$this->x->is_connected(); $nxt=wp_next_scheduled(Cron::HOOK);
        echo '<div class="aiss-stats-grid">';
        echo '<div class="aiss-stat-box"><span class="aiss-status-dot '.($fb?'dot-green':'dot-red').'"></span> Facebook: '.($fb?'Active':'Off').'</div>';
        echo '<div class="aiss-stat-box"><span class="aiss-status-dot '.($x?'dot-green':'dot-gray').'"></span> X (Twitter): '.($x?'Active':'Disabled').'</div>';
        echo '<div class="aiss-stat-box">Next Run: '.($nxt?date_i18n('H:i',$nxt):'--').'</div>';
        echo '</div><div class="aiss-card"><h2>Debug</h2><a href="'.wp_nonce_url(admin_url('admin-post.php?action=aiss_run_cron'),'aiss_run_cron').'" class="aiss-btn">⚡ Run Cron Now</a></div>';
    }

    public function start_fb_connect(){ check_admin_referer('aiss_fb_connect'); wp_redirect($this->facebook->build_oauth_url()); exit; }
    public function select_fb_page(){ check_admin_referer('aiss_fb_select_page','aiss_fb_select_page_nonce'); $res=$this->facebook->select_page_and_store($_POST['page_id']??''); delete_transient('aiss_fb_pages_'.get_current_user_id()); wp_redirect(Utils::admin_url_settings(['tab'=>'facebook','aiss_notice'=>$res['ok']?'page_selected':'page_error'])); exit; }
    public function disconnect_facebook(){ check_admin_referer('aiss_fb_disconnect'); $s=Utils::get_settings(); $s['fb_page_id']=''; update_option('aiss_settings',$s); wp_redirect(Utils::admin_url_settings(['tab'=>'facebook','aiss_notice'=>'disconnected'])); exit; }
    public function run_cron_manually(){ check_admin_referer('aiss_run_cron'); $this->cron->run(); wp_redirect(Utils::admin_url_settings(['tab'=>'status','aiss_notice'=>'cron_run'])); exit; }
}