<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Metabox {

    /** @var OpenRouter */
    private $openrouter;

    /** @var Facebook */
    private $facebook;

    public function __construct(OpenRouter $openrouter, Facebook $facebook) {
        $this->openrouter = $openrouter;
        $this->facebook = $facebook;

        add_action('add_meta_boxes', [$this, 'register_metabox']);
        add_action('admin_post_aiss_fb_share_now', [$this, 'handle_share_now']);
        add_action('admin_post_aiss_fb_generate', [$this, 'handle_generate_only']);
    }

    public function register_metabox() : void {
        add_meta_box(
            'aiss_metabox',
            __('AI Social Share', 'ai-social-share'),
            [$this, 'render_metabox'],
            ['post'],
            'side',
            'high'
        );
    }

    public function render_metabox(\WP_Post $post) : void {
        $shared_at = (int)get_post_meta($post->ID, '_aiss_fb_shared_at', true);
        $remote_id = (string)get_post_meta($post->ID, '_aiss_fb_remote_id', true);
        $last_err  = (string)get_post_meta($post->ID, '_aiss_fb_last_error', true);
        $last_gen  = (string)get_post_meta($post->ID, '_aiss_fb_last_generated', true);

        wp_nonce_field('aiss_metabox_action', 'aiss_metabox_nonce');

        echo '<p><strong>Facebook</strong></p>';

        if (!$this->facebook->is_connected()) {
            echo '<p style="color:#b91c1c;">Not connected.</p>';
            echo '<p><a class="button button-secondary" href="' . esc_url(Utils::admin_url_settings(['tab'=>'facebook'])) . '">Connect in settings</a></p>';
            return;
        }

        if ($shared_at) {
            echo '<p style="color:#166534;">Shared: ' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $shared_at)) . '</p>';
            if ($remote_id) {
                echo '<p>Remote ID:<br><code style="font-size:11px;">' . esc_html($remote_id) . '</code></p>';
            }
        } else {
            echo '<p>Status: <span style="color:#92400e;">Not shared yet</span></p>';
        }

        if ($last_err) {
            echo '<p style="color:#b91c1c;"><strong>Last error:</strong><br>' . esc_html($last_err) . '</p>';
        }

        echo '<p><label for="aiss_fb_message"><strong>Message (editable)</strong></label></p>';
        echo '<textarea id="aiss_fb_message" name="aiss_fb_message" style="width:100%;min-height:120px;">' . esc_textarea($last_gen) . '</textarea>';

        $gen_url = wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_generate&post_id=' . (int)$post->ID), 'aiss_metabox_action', 'aiss_metabox_nonce');
        $share_url = wp_nonce_url(admin_url('admin-post.php?action=aiss_fb_share_now&post_id=' . (int)$post->ID), 'aiss_metabox_action', 'aiss_metabox_nonce');

        echo '<p style="display:flex; gap:8px; flex-wrap:wrap;">';
        echo '<a class="button button-secondary" href="' . esc_url($gen_url) . '">Generate</a>';
        echo '<a class="button button-primary" href="' . esc_url($share_url) . '">Share Now</a>';
        echo '</p>';

        echo '<p style="font-size:12px;opacity:.85;">Tip: Generate first to preview, then edit the text and Share Now.</p>';
    }

    public function handle_generate_only() : void {
        if (!current_user_can('edit_posts')) { wp_die('Forbidden'); }
        $post_id = isset($_GET['post_id']) ? (int)$_GET['post_id'] : 0;
        if (!$post_id) { wp_die('Missing post_id'); }
        check_admin_referer('aiss_metabox_action', 'aiss_metabox_nonce');

        $gen = $this->openrouter->generate_facebook_post($post_id);
        if ($gen['ok']) {
            update_post_meta($post_id, '_aiss_fb_last_generated', wp_kses_post((string)$gen['text']));
            delete_post_meta($post_id, '_aiss_fb_last_error');
            wp_safe_redirect(get_edit_post_link($post_id, 'url') . '#aiss_metabox');
            exit;
        }

        update_post_meta($post_id, '_aiss_fb_last_error', sanitize_text_field((string)$gen['error']));
        wp_safe_redirect(get_edit_post_link($post_id, 'url') . '#aiss_metabox');
        exit;
    }

    public function handle_share_now() : void {
        if (!current_user_can('edit_posts')) { wp_die('Forbidden'); }
        $post_id = isset($_GET['post_id']) ? (int)$_GET['post_id'] : 0;
        if (!$post_id) { wp_die('Missing post_id'); }
        check_admin_referer('aiss_metabox_action', 'aiss_metabox_nonce');

        // If admin edited message in the box, capture it from POST when available (classic editor)
        $msg = '';
        if (isset($_POST['aiss_fb_message'])) {
            $msg = wp_unslash((string)$_POST['aiss_fb_message']);
        } else {
            // If no POST (Gutenberg sidebar doesn't submit), use last generated or generate now
            $msg = (string)get_post_meta($post_id, '_aiss_fb_last_generated', true);
        }

        $msg = trim($msg);
        if ($msg === '') {
            $gen = $this->openrouter->generate_facebook_post($post_id);
            if (!$gen['ok']) {
                update_post_meta($post_id, '_aiss_fb_last_error', sanitize_text_field((string)$gen['error']));
                wp_safe_redirect(get_edit_post_link($post_id, 'url') . '#aiss_metabox');
                exit;
            }
            $msg = (string)$gen['text'];
            update_post_meta($post_id, '_aiss_fb_last_generated', wp_kses_post($msg));
        }

        $share = $this->facebook->share_link_post($post_id, $msg);
        if (!$share['ok']) {
            update_post_meta($post_id, '_aiss_fb_last_error', sanitize_text_field((string)$share['error']));
            wp_safe_redirect(get_edit_post_link($post_id, 'url') . '#aiss_metabox');
            exit;
        }

        update_post_meta($post_id, '_aiss_fb_shared_at', time());
        if (!empty($share['id'])) {
            update_post_meta($post_id, '_aiss_fb_remote_id', sanitize_text_field((string)$share['id']));
        }
        delete_post_meta($post_id, '_aiss_fb_last_error');

        wp_safe_redirect(get_edit_post_link($post_id, 'url') . '#aiss_metabox');
        exit;
    }
}
