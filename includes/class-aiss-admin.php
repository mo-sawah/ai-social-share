<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Admin {
    private $cron, $facebook, $x;

    public function __construct(Cron $c, Facebook $f, X $x) {
        $this->cron = $c; $this->facebook = $f; $this->x = $x;
        add_action('admin_menu', [$this,'menu']);
        add_action('admin_init', [$this,'register_settings']);
        add_action('admin_post_aiss_fb_start_connect', [$this,'start_fb_connect']);
        add_action('admin_post_aiss_fb_select_page', [$this,'select_fb_page']);
        add_action('admin_post_aiss_fb_disconnect', [$this,'disconnect_facebook']);
        add_action('admin_post_aiss_run_cron', [$this,'run_cron_manually']);
        add_action('admin_enqueue_scripts', [$this,'assets']);
    }

    public function menu() { add_options_page('AI Social Share','AI Social Share','manage_options','ai-social-share',[$this,'render']); }
    
    public function assets($hook) {
        if($hook!=='settings_page_ai-social-share') return;
        echo '<style>
        :root{--aiss-p:#2563eb;--aiss-bg:#f3f4f6;--aiss-txt:#111827;}
        .aiss-wrap{max-width:1100px;margin:20px auto;font-family:-apple-system,system-ui,sans-serif;color:var(--aiss-txt)}
        .aiss-header{display:flex;align-items:center;gap:15px;margin-bottom:25px}
        .aiss-icon{background:var(--aiss-p);color:#fff;width:42px;height:42px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:22px}
        .aiss-tabs{display:flex;gap:5px;background:#fff;padding:5px;border-radius:10px;border:1px solid #e5e7eb;width:fit-content;margin-bottom:25px}
        .aiss-tab{padding:8px 18px;border-radius:6px;text-decoration:none;color:#6b7280;font-weight:500;font-size:14px;transition:0.2s}
        .aiss-tab:hover{background:#f3f4f6;color:#111827}
        .aiss-tab-active{background:var(--aiss-p);color:#fff!important}
        .aiss-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:25px;margin-bottom:20px;box-shadow:0 1px 2px rgba(0,0,0,0.03)}
        .aiss-input{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px}
        .aiss-btn{display:inline-flex;align-items:center;padding:10px 20px;background:var(--aiss-p);color:#fff;border-radius:6px;text-decoration:none;border:none;cursor:pointer;font-size:14px}
        .aiss-stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin-bottom:25px}
        .aiss-stat-box{background:#fff;padding:20px;border-radius:10px;border:1px solid #e5e7eb}
        .aiss-stat-val{font-size:22px;font-weight:700;display:flex;align-items:center;gap:10px}
        .dot{width:10px;height:10px;border-radius:50%;display:inline-block}
        .dot-g{background:#10b981} .dot-r{background:#ef4444} .dot-y{background:#9ca3af}
        </style>';
    }

    public function register_settings() {
        register_setting('aiss_settings_group', 'aiss_settings', ['sanitize_callback'=>[$this,'sanitize']]);
    }

    public function sanitize($in) {
        $s = Utils::get_settings(); $in=(array)$in;
        // General
        $s['openrouter_api_key'] = trim($in['openrouter_api_key']??'');
        $s['openrouter_model'] = trim($in['openrouter_model']??'');
        $s['enable_web_search'] = isset($in['enable_web_search']); // Checkbox
        // FB
        $s['fb_app_id']=trim($in['fb_app_id']??''); $s['fb_app_secret']=trim($in['fb_app_secret']??'');
        $s['prompt_facebook']=wp_kses_post($in['prompt_facebook']??'');
        // FB hidden
        if(isset($in['fb_page_id'])) $s['fb_page_id']=$in['fb_page_id'];
        if(isset($in['fb_page_token'])) $s['fb_page_token']=$in['fb_page_token'];
        if(isset($in['fb_page_name'])) $s['fb_page_name']=$in['fb_page_name'];
        // X
        $s['x_enabled']=isset($in['x_enabled']);
        $s['x_consumer_key']=trim($in['x_consumer_key']??''); $s['x_consumer_secret']=trim($in['x_consumer_secret']??'');
        $s['x_access_token']=trim($in['x_access_token']??''); $s['x_access_secret']=trim($in['x_access_secret']??'');
        $s['prompt_x']=wp_kses_post($in['prompt_x']??'');
        // Schedule
        $s['schedule_minutes']=max(5,(int)($in['schedule_minutes']??30));
        $s['max_posts_per_run']=max(1,(int)($in['max_posts_per_run']??1));
        $s['filter_mode']=$in['filter_mode']??'all';
        $s['filter_terms']=sanitize_text_field($in['filter_terms']??'');
        
        return $s;
    }

    public function render() {
        $t = $_GET['tab']??'general'; $s = Utils::get_settings();
        echo '<div class="wrap aiss-wrap"><div class="aiss-header"><div class="aiss-icon"><span class="dashicons dashicons-share-alt2"></span></div><h1>AI Social Share</h1></div>';
        echo '<div class="aiss-tabs">';
        foreach(['general'=>'General','facebook'=>'Facebook','x'=>'X (Twitter)','scheduler'=>'Scheduler','status'=>'System Status'] as $k=>$v)
            echo '<a href="'.esc_url(Utils::admin_url_settings(['tab'=>$k])).'" class="aiss-tab '.($t==$k?'aiss-tab-active':'').'">'.$v.'</a>';
        echo '</div>';

        if($t=='general') {
            echo '<form method="post" action="options.php">'; settings_fields('aiss_settings_group');
            echo '<div class="aiss-card"><h2>AI Configuration</h2><p>
                <label><strong>API Key:</strong><br><input type="password" class="aiss-input" name="aiss_settings[openrouter_api_key]" value="'.esc_attr($s['openrouter_api_key']).'"></label></p>';
            echo '<p><label><strong>Model:</strong><br><select class="aiss-input" name="aiss_settings[openrouter_model]">';
            foreach(['openai/gpt-4o-mini'=>'GPT-4o Mini','openai/gpt-4o'=>'GPT-4o','anthropic/claude-3.5-sonnet'=>'Claude 3.5','perplexity/sonar-small-online'=>'Perplexity Online'] as $k=>$v)
                echo '<option value="'.$k.'" '.selected($s['openrouter_model'],$k,false).'>'.$v.'</option>';
            echo '</select></label></p>';
            // Web Search Checkbox
            echo '<p><label><input type="checkbox" name="aiss_settings[enable_web_search]" value="1" '.checked($s['enable_web_search'],true,false).'> <strong>Enable Web Search</strong> (Gives AI access to fresh internet data)</label></p>';
            echo '</div><button class="aiss-btn">Save Settings</button></form>';
        }
        elseif($t=='facebook') {
            echo '<form method="post" action="options.php">'; settings_fields('aiss_settings_group');
            $con=$this->facebook->is_connected();
            echo '<div class="aiss-card"><h2>Facebook</h2>';
            if($con) echo '<div style="color:green;margin-bottom:10px">✓ Connected: <b>'.esc_html($s['fb_page_name']).'</b></div><a href="'.wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_disconnect'),'aiss_fb_disconnect').'" style="color:red">Disconnect</a>';
            else echo '<p>App ID: <input class="aiss-input" name="aiss_settings[fb_app_id]" value="'.esc_attr($s['fb_app_id']).'"></p><p>Secret: <input type="password" class="aiss-input" name="aiss_settings[fb_app_secret]" value="'.esc_attr($s['fb_app_secret']).'"></p><button class="aiss-btn">Save</button> ';
            if($s['fb_app_id'] && !$con) echo '<a href="'.wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_start_connect'),'aiss_fb_connect').'" class="aiss-btn">Connect Page</a>';
            echo '</div>';
            echo '<div class="aiss-card"><h2>Prompt</h2><textarea class="aiss-input" rows="5" name="aiss_settings[prompt_facebook]">'.esc_textarea($s['prompt_facebook']).'</textarea></div><button class="aiss-btn">Save</button></form>';
            
            // Page selector
            $pages = get_transient('aiss_fb_pages_'.get_current_user_id());
            if($pages && !$con) {
                echo '<div class="aiss-card"><h3>Select Page</h3><form method="post" action="'.admin_url('admin-post.php').'"><input type="hidden" name="action" value="aiss_fb_select_page">';
                wp_nonce_field('aiss_fb_select_page','aiss_fb_select_page_nonce');
                echo '<select name="page_id" class="aiss-input">'; foreach($pages as $p) echo '<option value="'.$p['id'].'">'.$p['name'].'</option>';
                echo '</select><br><br><button class="aiss-btn">Use This Page</button></form></div>';
            }
        }
        elseif($t=='x') {
            echo '<form method="post" action="options.php">'; settings_fields('aiss_settings_group');
            echo '<div class="aiss-card"><h2>X (Twitter)</h2><label><input type="checkbox" name="aiss_settings[x_enabled]" value="1" '.checked($s['x_enabled'],true,false).'> Enable X Sharing</label>';
            echo '<p>API Key: <input class="aiss-input" name="aiss_settings[x_consumer_key]" value="'.esc_attr($s['x_consumer_key']).'"></p>';
            echo '<p>API Secret: <input type="password" class="aiss-input" name="aiss_settings[x_consumer_secret]" value="'.esc_attr($s['x_consumer_secret']).'"></p>';
            echo '<p>Access Token: <input class="aiss-input" name="aiss_settings[x_access_token]" value="'.esc_attr($s['x_access_token']).'"></p>';
            echo '<p>Access Secret: <input type="password" class="aiss-input" name="aiss_settings[x_access_secret]" value="'.esc_attr($s['x_access_secret']).'"></p></div>';
            echo '<div class="aiss-card"><h2>Prompt</h2><textarea class="aiss-input" rows="4" name="aiss_settings[prompt_x]">'.esc_textarea($s['prompt_x']).'</textarea></div><button class="aiss-btn">Save</button></form>';
        }
        elseif($t=='scheduler') {
            echo '<form method="post" action="options.php">'; settings_fields('aiss_settings_group');
            echo '<div class="aiss-card"><h2>Schedule</h2><p>Run every <input type="number" style="width:60px" name="aiss_settings[schedule_minutes]" value="'.(int)$s['schedule_minutes'].'"> minutes</p>';
            echo '<p>Process <input type="number" style="width:60px" name="aiss_settings[max_posts_per_run]" value="'.(int)$s['max_posts_per_run'].'"> posts per run</p></div>';
            echo '<div class="aiss-card"><h2>Filters</h2><p>Mode: <select name="aiss_settings[filter_mode]"><option value="all" '.selected($s['filter_mode'],'all',false).'>All</option><option value="category" '.selected($s['filter_mode'],'category',false).'>Category</option><option value="tag" '.selected($s['filter_mode'],'tag',false).'>Tag</option></select></p>';
            echo '<p>Slugs: <input class="aiss-input" name="aiss_settings[filter_terms]" value="'.esc_attr($s['filter_terms']).'" placeholder="e.g. news, tech"></p></div><button class="aiss-btn">Save</button></form>';
        }
        else { // STATUS TAB
            $fb=$this->facebook->is_connected(); $x=$this->x->is_connected(); $nxt=wp_next_scheduled(Cron::HOOK);
            echo '<div class="aiss-stats-grid">';
            echo '<div class="aiss-stat-box"><div class="aiss-stat-val"><span class="dot '.($fb?'dot-g':'dot-r').'"></span> Facebook</div>'.($fb?'Active':'Offline').'</div>';
            echo '<div class="aiss-stat-box"><div class="aiss-stat-val"><span class="dot '.($x?'dot-g':'dot-y').'"></span> X (Twitter)</div>'.($x?'Active':'Disabled').'</div>';
            echo '<div class="aiss-stat-box"><div class="aiss-stat-val">Next Run</div>'.($nxt?date_i18n('H:i',$nxt):'Not scheduled').'</div>';
            echo '</div>';
            
            // Detailed Stats Table
            echo '<div class="aiss-card"><h2>Configuration Overview</h2><table style="width:100%;text-align:left;border-collapse:collapse">';
            echo '<tr><th style="padding:8px;border-bottom:1px solid #eee">Setting</th><th style="padding:8px;border-bottom:1px solid #eee">Value</th></tr>';
            echo '<tr><td style="padding:8px">Schedule Interval</td><td style="padding:8px">Every <b>'.(int)$s['schedule_minutes'].'</b> minutes</td></tr>';
            echo '<tr><td style="padding:8px">Batch Size</td><td style="padding:8px"><b>'.(int)$s['max_posts_per_run'].'</b> posts per run</td></tr>';
            echo '<tr><td style="padding:8px">Filter Mode</td><td style="padding:8px">'.ucfirst($s['filter_mode']).' '.($s['filter_terms'] ? '('.$s['filter_terms'].')' : '').'</td></tr>';
            echo '<tr><td style="padding:8px">Web Search</td><td style="padding:8px">'.($s['enable_web_search'] ? '<span style="color:green">Enabled</span>' : 'Disabled').'</td></tr>';
            
            $last = get_option('aiss_last_run_time');
            echo '<tr><td style="padding:8px">Last Run Execution</td><td style="padding:8px">'.($last ? date_i18n('Y-m-d H:i:s', $last) : 'Never').'</td></tr>';
            echo '</table>';
            
            echo '<p style="margin-top:20px"><a href="'.wp_nonce_url(admin_url('admin-post.php?action=aiss_run_cron'),'aiss_run_cron').'" class="aiss-btn" style="background:#4b5563">⚡ Force Run Cron Now</a></p></div>';
        }
        echo '</div>';
    }

    public function start_fb_connect(){ check_admin_referer('aiss_fb_connect'); wp_redirect($this->facebook->build_oauth_url()); exit; }
    public function select_fb_page(){ check_admin_referer('aiss_fb_select_page','aiss_fb_select_page_nonce'); $this->facebook->select_page_and_store($_POST['page_id']??''); delete_transient('aiss_fb_pages_'.get_current_user_id()); wp_redirect(Utils::admin_url_settings(['tab'=>'facebook'])); exit; }
    public function disconnect_facebook(){ check_admin_referer('aiss_fb_disconnect'); $s=Utils::get_settings(); $s['fb_page_id']=''; update_option('aiss_settings',$s); wp_redirect(Utils::admin_url_settings(['tab'=>'facebook'])); exit; }
    public function run_cron_manually(){ check_admin_referer('aiss_run_cron'); $this->cron->run(); wp_redirect(Utils::admin_url_settings(['tab'=>'status'])); exit; }
}