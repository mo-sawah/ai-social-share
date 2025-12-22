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

        // OpenRouter
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

        // Scheduler
        if (isset($in['schedule_minutes'])) {
            $s['schedule_minutes'] = max(5, (int)$in['schedule_minutes']);
        }
        if (isset($in['max_posts_per_run'])) {
            $s['max_posts_per_run'] = max(1, (int)$in['max_posts_per_run']);
        }

        // Filters
        if (isset($in['filter_mode'])) {
            $mode = (string)$in['filter_mode'];
            $s['filter_mode'] = in_array($mode, ['all','category','tag'], true) ? $mode : 'all';
        }
        if (isset($in['filter_terms'])) {
            $s['filter_terms'] = sanitize_text_field((string)$in['filter_terms']);
        }

        // Facebook app settings (do NOT overwrite token here)
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

        // Prompt
        if (isset($in['prompt_facebook'])) {
            $s['prompt_facebook'] = (string)$in['prompt_facebook']; // allow newlines; stored as-is
        }

        return $s;
    }

    public function render() : void {
        if (!current_user_can('manage_options')) { return; }

        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        if (!in_array($tab, ['general','facebook','scheduler','logs'], true)) { $tab = 'general'; }

        $settings = Utils::get_settings();
        $notices = [];

        // Handle Facebook OAuth callback inside the Facebook tab
        if ($tab === 'facebook' && isset($_GET['aiss_fb_cb']) && isset($_GET['code']) && isset($_GET['state'])) {
            $code  = sanitize_text_field((string)$_GET['code']);
            $state = sanitize_text_field((string)$_GET['state']);
            $result = $this->facebook->handle_callback_and_fetch_pages($code, $state);
            if ($result['ok']) {
                set_transient('aiss_fb_pages_' . get_current_user_id(), $result['pages'], 20 * MINUTE_IN_SECONDS);
                $notices[] = ['type' => 'success', 'msg' => 'Connected. Select a Facebook Page below to finish.'];
            } else {
                $notices[] = ['type' => 'error', 'msg' => $result['error']];
            }
        }

        $pages = get_transient('aiss_fb_pages_' . get_current_user_id());
        if (!is_array($pages)) { $pages = []; }

        echo '<div class="wrap">';
        echo '<h1>AI Social Share</h1>';

        foreach ($notices as $n) {
            $class = $n['type'] === 'success' ? 'notice notice-success' : 'notice notice-error';
            echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($n['msg']) . '</p></div>';
        }

        echo '<h2 class="nav-tab-wrapper">';
        $this->tab_link('general', 'General', $tab);
        $this->tab_link('facebook', 'Facebook', $tab);
        $this->tab_link('scheduler', 'Scheduler & Filters', $tab);
        $this->tab_link('logs', 'Status', $tab);
        echo '</h2>';

        if ($tab === 'general') {
            $this->render_general($settings);
        } elseif ($tab === 'facebook') {
            $this->render_facebook($settings, $pages);
        } elseif ($tab === 'scheduler') {
            $this->render_scheduler($settings);
        } else {
            $this->render_logs($settings);
        }

        echo '</div>';
    }

    private function tab_link(string $key, string $label, string $current) : void {
        $url = Utils::admin_url_settings(['tab' => $key]);
        $cls = 'nav-tab' . ($current === $key ? ' nav-tab-active' : '');
        echo '<a class="' . esc_attr($cls) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    private function render_general(array $settings) : void {
        echo '<form method="post" action="options.php">';
        settings_fields('aiss_settings_group');

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="openrouter_api_key">OpenRouter API Key</label></th><td>';
        echo '<input type="password" id="openrouter_api_key" name="aiss_settings[openrouter_api_key]" value="' . esc_attr($settings['openrouter_api_key']) . '" class="regular-text" autocomplete="off" />';
        echo '<p class="description">Stored in wp_options. Use a restricted key when possible.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="openrouter_model">OpenRouter Model</label></th><td>';
        echo '<input type="text" id="openrouter_model" name="aiss_settings[openrouter_model]" value="' . esc_attr($settings['openrouter_model']) . '" class="regular-text" />';
        echo '<p class="description">Example: openai/gpt-4o-mini</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="openrouter_site">OpenRouter Referer (optional)</label></th><td>';
        echo '<input type="url" id="openrouter_site" name="aiss_settings[openrouter_site]" value="' . esc_attr($settings['openrouter_site']) . '" class="regular-text" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="openrouter_app">OpenRouter App Title (optional)</label></th><td>';
        echo '<input type="text" id="openrouter_app" name="aiss_settings[openrouter_app]" value="' . esc_attr($settings['openrouter_app']) . '" class="regular-text" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="prompt_facebook">Facebook Prompt</label></th><td>';
        echo '<textarea id="prompt_facebook" name="aiss_settings[prompt_facebook]" class="large-text" rows="8">' . esc_textarea($settings['prompt_facebook']) . '</textarea>';
        echo '<p class="description">This prompt is used as the system message sent to OpenRouter when generating Facebook text.</p>';
        echo '</td></tr>';

        echo '</table>';

        submit_button('Save Settings');
        echo '</form>';
    }

    private function render_facebook(array $settings, array $pages) : void {
        echo '<form method="post" action="options.php">';
        settings_fields('aiss_settings_group');

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="fb_app_id">Facebook App ID</label></th><td>';
        echo '<input type="text" id="fb_app_id" name="aiss_settings[fb_app_id]" value="' . esc_attr($settings['fb_app_id']) . '" class="regular-text" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="fb_app_secret">Facebook App Secret</label></th><td>';
        echo '<input type="password" id="fb_app_secret" name="aiss_settings[fb_app_secret]" value="' . esc_attr($settings['fb_app_secret']) . '" class="regular-text" autocomplete="off" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="fb_api_version">Graph API Version</label></th><td>';
        echo '<input type="text" id="fb_api_version" name="aiss_settings[fb_api_version]" value="' . esc_attr($settings['fb_api_version']) . '" class="regular-text" />';
        echo '<p class="description">Example: v24.0 (keep aligned with your app).</p>';
        echo '</td></tr>';
        echo '</table>';

        submit_button('Save Facebook App Settings');
        echo '</form>';

        echo '<hr />';

        // Connect button
        if (empty($settings['fb_app_id']) || empty($settings['fb_app_secret'])) {
            echo '<p><strong>Next:</strong> Enter App ID + Secret above, save, then connect.</p>';
        }

        $connected = $this->facebook->is_connected();
        if ($connected) {
            echo '<p style="color:#166534;"><strong>Connected Page:</strong> ' . esc_html($settings['fb_page_name']) . ' (' . esc_html($settings['fb_page_id']) . ')</p>';
            echo '<p>Connected at: ' . ($settings['fb_connected_at'] ? esc_html(date_i18n(get_option('date_format').' '.get_option('time_format'), (int)$settings['fb_connected_at'])) : '-') . '</p>';
        } else {
            echo '<p style="color:#92400e;"><strong>Status:</strong> Not connected to a Facebook Page yet.</p>';
        }

        $connect_url = wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_start_connect'), 'aiss_fb_connect');
        echo '<p><a class="button button-primary" href="' . esc_url($connect_url) . '">Connect Facebook</a></p>';

        // If we have pages list, show selection
        if (!empty($pages)) {
            echo '<h3>Select a Facebook Page</h3>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="aiss_fb_select_page" />';
            wp_nonce_field('aiss_fb_select_page', 'aiss_fb_select_page_nonce');

            echo '<select name="page_id" class="regular-text">';
            foreach ($pages as $p) {
                $id = isset($p['id']) ? (string)$p['id'] : '';
                $name = isset($p['name']) ? (string)$p['name'] : $id;
                echo '<option value="' . esc_attr($id) . '">' . esc_html($name . ' (' . $id . ')') . '</option>';
            }
            echo '</select> ';
            submit_button('Use this Page', 'primary', 'submit', false);
            echo '</form>';

            echo '<p class="description">After selecting, pages list is cleared from temporary storage.</p>';
        }

        echo '<h3>Redirect URI</h3>';
        echo '<p>Set this exact URL in your Facebook App → Facebook Login → Settings → Valid OAuth Redirect URIs:</p>';
        echo '<code style="display:block; padding:10px; background:#111827; color:#e5e7eb; border-radius:6px;">' . esc_html($this->facebook->oauth_redirect_uri()) . '</code>';
    }

    private function render_scheduler(array $settings) : void {
        echo '<form method="post" action="options.php">';
        settings_fields('aiss_settings_group');

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="schedule_minutes">Run every (minutes)</label></th><td>';
        echo '<input type="number" min="5" step="1" id="schedule_minutes" name="aiss_settings[schedule_minutes]" value="' . esc_attr((int)$settings['schedule_minutes']) . '" class="small-text" />';
        echo '<p class="description">Minimum 5 minutes. For reliable scheduling, consider a real server cron instead of WP-Cron.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="max_posts_per_run">Max posts per run</label></th><td>';
        echo '<input type="number" min="1" step="1" id="max_posts_per_run" name="aiss_settings[max_posts_per_run]" value="' . esc_attr((int)$settings['max_posts_per_run']) . '" class="small-text" />';
        echo '</td></tr>';

        echo '<tr><th scope="row">Filter posts</th><td>';
        echo '<fieldset>';
        $m = (string)$settings['filter_mode'];
        $this->radio('aiss_settings[filter_mode]', 'all', 'All posts', $m);
        echo '<br />';
        $this->radio('aiss_settings[filter_mode]', 'category', 'Only categories (slugs)', $m);
        echo '<br />';
        $this->radio('aiss_settings[filter_mode]', 'tag', 'Only tags (slugs)', $m);
        echo '</fieldset>';
        echo '<p><label for="filter_terms">Category/Tag slugs (comma-separated)</label><br />';
        echo '<input type="text" id="filter_terms" name="aiss_settings[filter_terms]" value="' . esc_attr($settings['filter_terms']) . '" class="regular-text" placeholder="news,world,tech" /></p>';
        echo '</td></tr>';
        echo '</table>';

        submit_button('Save Scheduler Settings');
        echo '</form>';
    }

    private function render_logs(array $settings) : void {
        $next = wp_next_scheduled(Cron::HOOK);
        echo '<h2>Status</h2>';

        echo '<table class="widefat striped" style="max-width:900px;">';
        echo '<tbody>';
        echo '<tr><td><strong>Facebook connected</strong></td><td>' . ($this->facebook->is_connected() ? 'Yes' : 'No') . '</td></tr>';
        echo '<tr><td><strong>OpenRouter key set</strong></td><td>' . (trim((string)$settings['openrouter_api_key']) !== '' ? 'Yes' : 'No') . '</td></tr>';
        echo '<tr><td><strong>Schedule minutes</strong></td><td>' . esc_html((int)$settings['schedule_minutes']) . '</td></tr>';
        echo '<tr><td><strong>Next run (WP-Cron)</strong></td><td>' . ($next ? esc_html(date_i18n(get_option('date_format').' '.get_option('time_format'), (int)$next)) : 'Not scheduled') . '</td></tr>';
        echo '</tbody></table>';

        echo '<p><a class="button button-secondary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=aiss_run_now'), 'aiss_run_now')) . '" style="display:none;">Run now</a></p>';
        echo '<p style="opacity:.85;">Note: This MVP uses WP-Cron. For serious production use, configure a server cron that hits wp-cron.php regularly and disable WP-Cron.</p>';
    }

    private function radio(string $name, string $value, string $label, string $current) : void {
        echo '<label><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" ' . checked($current, $value, false) . ' /> ' . esc_html($label) . '</label>';
    }

    public function start_fb_connect() : void {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('aiss_fb_connect');

        $settings = Utils::get_settings();
        if (empty($settings['fb_app_id']) || empty($settings['fb_app_secret'])) {
            wp_safe_redirect(Utils::admin_url_settings(['tab'=>'facebook','aiss_msg'=>'missing_app']));
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
}
