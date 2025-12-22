<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Metabox {
    private $openrouter, $facebook, $x;

    public function __construct(OpenRouter $openrouter, Facebook $facebook, X $x) {
        $this->openrouter = $openrouter; $this->facebook = $facebook; $this->x = $x;
        add_action('add_meta_boxes', [$this, 'register']);
        add_action('admin_post_aiss_manual_share', [$this, 'handle_share']);
    }

    public function register() {
        add_meta_box('aiss_metabox', 'AI Social Share', [$this, 'render'], ['post'], 'side', 'high');
    }

    public function render(\WP_Post $post) {
        $platform = $_GET['aiss_platform'] ?? 'facebook'; // Simple tab toggle via link
        $s = Utils::get_settings();

        echo '<div style="border-bottom:1px solid #ddd;margin-bottom:10px;padding-bottom:5px;">';
        echo '<a href="'.get_edit_post_link($post->ID).'&aiss_platform=facebook#aiss_metabox" style="text-decoration:none;margin-right:10px;font-weight:'.($platform=='facebook'?'bold':'normal').'">Facebook</a>';
        echo '<a href="'.get_edit_post_link($post->ID).'&aiss_platform=x#aiss_metabox" style="text-decoration:none;font-weight:'.($platform=='x'?'bold':'normal').'">X (Twitter)</a>';
        echo '</div>';

        if ($platform === 'facebook') {
            $this->render_platform($post->ID, 'facebook', $this->facebook->is_connected(), $s['prompt_facebook'], '_aiss_fb_');
        } else {
            $this->render_platform($post->ID, 'x', $this->x->is_connected(), $s['prompt_x'], '_aiss_x_');
        }
    }

    private function render_platform($pid, $platform, $connected, $prompt, $prefix) {
        if (!$connected) { echo "<p style='color:red'>Not connected.</p>"; return; }
        
        $shared_at = get_post_meta($pid, $prefix.'shared_at', true);
        $last_err  = get_post_meta($pid, $prefix.'last_error', true);
        $last_gen  = get_post_meta($pid, $prefix.'last_generated', true);

        if ($shared_at) echo "<p style='color:green'>Shared: ".date_i18n('M j, H:i', $shared_at)."</p>";
        else echo "<p>Status: Not shared yet</p>";

        if ($last_err) echo "<p style='color:red;font-size:11px'>Error: ".esc_html($last_err)."</p>";

        echo '<form action="'.admin_url('admin-post.php').'" method="post">';
        echo '<input type="hidden" name="action" value="aiss_manual_share">';
        echo '<input type="hidden" name="post_id" value="'.$pid.'">';
        echo '<input type="hidden" name="platform" value="'.$platform.'">';
        wp_nonce_field('aiss_share', 'nonce');
        
        echo '<textarea name="message" style="width:100%;height:100px;margin-bottom:5px;" placeholder="Message...">'.esc_textarea($last_gen).'</textarea>';
        
        echo '<button name="do_action" value="generate" class="button">Generate AI</button> ';
        echo '<button name="do_action" value="share" class="button button-primary">Share Now</button>';
        echo '</form>';
    }

    public function handle_share() {
        check_admin_referer('aiss_share', 'nonce');
        $pid = (int)$_POST['post_id'];
        $plat = $_POST['platform'];
        $act = $_POST['do_action'];
        $msg = wp_unslash($_POST['message']);
        $prefix = ($plat === 'facebook') ? '_aiss_fb_' : '_aiss_x_';
        $prompt = ($plat === 'facebook') ? Utils::get_settings()['prompt_facebook'] : Utils::get_settings()['prompt_x'];

        if ($act === 'generate') {
            $gen = $this->openrouter->generate_post($pid, $prompt);
            update_post_meta($pid, $prefix . ($gen['ok']?'last_generated':'last_error'), $gen['ok']?$gen['text']:$gen['error']);
        } elseif ($act === 'share') {
            if (!$msg) {
                // Generate if empty
                $gen = $this->openrouter->generate_post($pid, $prompt);
                if (!$gen['ok']) { update_post_meta($pid, $prefix.'last_error', $gen['error']); wp_safe_redirect(get_edit_post_link($pid).'&aiss_platform='.$plat.'#aiss_metabox'); exit; }
                $msg = $gen['text'];
            }
            
            $res = ($plat === 'facebook') ? $this->facebook->share_link_post($pid, $msg) : $this->x->post_tweet($pid, $msg);
            
            if ($res['ok']) {
                update_post_meta($pid, $prefix.'shared_at', time());
                update_post_meta($pid, $prefix.'remote_id', $res['id']);
                update_post_meta($pid, $prefix.'last_generated', $msg); // Save what was sent
                delete_post_meta($pid, $prefix.'last_error');
            } else {
                update_post_meta($pid, $prefix.'last_error', $res['error']);
            }
        }
        wp_safe_redirect(get_edit_post_link($pid).'&aiss_platform='.$plat.'#aiss_metabox'); exit;
    }
}