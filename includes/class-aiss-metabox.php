<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Metabox {
    private $openrouter;
    private $facebook;
    private $x;

    public function __construct(OpenRouter $openrouter, Facebook $facebook, X $x) {
        $this->openrouter = $openrouter;
        $this->facebook = $facebook;
        $this->x = $x;

        add_action('add_meta_boxes', [$this, 'register']);
        add_action('admin_post_aiss_manual_share', [$this, 'handle_manual_share']);
    }

    public function register() {
        add_meta_box('aiss_metabox', 'AI Social Share', [$this, 'render'], ['post'], 'side', 'high');
    }

    public function render(\WP_Post $post) {
        $platform = isset($_GET['aiss_platform']) ? sanitize_key($_GET['aiss_platform']) : 'facebook';
        $settings = Utils::get_settings();

        // Simple Tab Navigation
        echo '<div style="border-bottom:1px solid #ddd; margin-bottom:12px; padding-bottom:8px; display:flex; gap:12px;">';
        $style_fb = ($platform === 'facebook') ? 'font-weight:bold; color:#000; text-decoration:none;' : 'color:#0073aa; text-decoration:none;';
        $style_x  = ($platform === 'x') ? 'font-weight:bold; color:#000; text-decoration:none;' : 'color:#0073aa; text-decoration:none;';
        echo '<a href="' . get_edit_post_link($post->ID) . '&aiss_platform=facebook#aiss_metabox" style="' . $style_fb . '">Facebook</a>';
        echo '<a href="' . get_edit_post_link($post->ID) . '&aiss_platform=x#aiss_metabox" style="' . $style_x . '">X (Twitter)</a>';
        echo '</div>';

        // Render Platform Logic
        $connected = ($platform === 'facebook') ? $this->facebook->is_connected() : $this->x->is_connected();
        $prefix    = ($platform === 'facebook') ? '_aiss_fb_' : '_aiss_x_';
        
        if (!$connected) {
            echo '<p style="color:#d63638;"><strong>Not Connected.</strong> Please configure this network in Settings.</p>';
            return;
        }

        $shared_at = get_post_meta($post->ID, $prefix . 'shared_at', true);
        $last_err  = get_post_meta($post->ID, $prefix . 'last_error', true);
        $last_gen  = get_post_meta($post->ID, $prefix . 'last_generated', true);

        // Status Display
        if ($shared_at) {
            echo '<p style="color:#00a32a; margin-top:0;"><strong>✓ Shared:</strong> ' . date_i18n('M j, Y g:i a', $shared_at) . '</p>';
        } else {
            echo '<p style="margin-top:0;"><strong>Status:</strong> Not shared yet.</p>';
        }

        if ($last_err) {
            echo '<div style="background:#ffebeb; border-left:4px solid #d63638; padding:8px; margin:8px 0; font-size:12px;">';
            echo '<strong>Error:</strong> ' . esc_html($last_err);
            echo '</div>';
        }

        // Form
        echo '<form action="' . admin_url('admin-post.php') . '" method="post">';
        echo '<input type="hidden" name="action" value="aiss_manual_share">';
        echo '<input type="hidden" name="post_id" value="' . $post->ID . '">';
        echo '<input type="hidden" name="platform" value="' . esc_attr($platform) . '">';
        wp_nonce_field('aiss_share_nonce', 'aiss_nonce');

        echo '<label style="display:block; margin-bottom:4px; font-weight:600;">Message (Editable):</label>';
        echo '<textarea name="message" style="width:100%; height:120px; margin-bottom:8px; font-family:monospace;" placeholder="Message will appear here...">' . esc_textarea($last_gen) . '</textarea>';

        echo '<div style="display:flex; gap:8px;">';
        echo '<button name="do_action" value="generate" class="button button-secondary">Regenerate AI</button>';
        echo '<button name="do_action" value="share" class="button button-primary">Share Now</button>';
        echo '</div>';
        echo '</form>';
    }

    public function handle_manual_share() {
        check_admin_referer('aiss_share_nonce', 'aiss_nonce');
        
        $pid = (int)$_POST['post_id'];
        $platform = sanitize_key($_POST['platform']);
        $action = sanitize_key($_POST['do_action']);
        $message = wp_unslash($_POST['message']); // allow emojis/newlines
        
        $settings = Utils::get_settings();
        $prefix = ($platform === 'facebook') ? '_aiss_fb_' : '_aiss_x_';
        $prompt = ($platform === 'facebook') ? $settings['prompt_facebook'] : $settings['prompt_x'];

        // --- Action: Generate ---
        if ($action === 'generate') {
            $gen = $this->openrouter->generate_post($pid, $prompt);
            
            if ($gen['ok']) {
                update_post_meta($pid, $prefix . 'last_generated', $gen['text']);
                delete_post_meta($pid, $prefix . 'last_error');
            } else {
                update_post_meta($pid, $prefix . 'last_error', $gen['error']);
            }
        } 
        
        // --- Action: Share ---
        elseif ($action === 'share') {
            // Auto-generate if empty
            if (empty(trim($message))) {
                $gen = $this->openrouter->generate_post($pid, $prompt);
                if ($gen['ok']) {
                    $message = $gen['text'];
                } else {
                    update_post_meta($pid, $prefix . 'last_error', $gen['error']);
                    wp_safe_redirect(get_edit_post_link($pid) . '&aiss_platform=' . $platform . '#aiss_metabox');
                    exit;
                }
            }

            // Perform Share
            if ($platform === 'facebook') {
                $res = $this->facebook->share_link_post($pid, $message);
            } else {
                $res = $this->x->post_tweet($pid, $message);
            }

            if ($res['ok']) {
                update_post_meta($pid, $prefix . 'shared_at', time());
                update_post_meta($pid, $prefix . 'remote_id', $res['id']);
                update_post_meta($pid, $prefix . 'last_generated', $message);
                delete_post_meta($pid, $prefix . 'last_error');
            } else {
                update_post_meta($pid, $prefix . 'last_error', $res['error']);
            }
        }

        wp_safe_redirect(get_edit_post_link($pid) . '&aiss_platform=' . $platform . '#aiss_metabox');
        exit;
    }
}