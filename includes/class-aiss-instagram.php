<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

/**
 * Instagram Integration
 *
 * Uses the Facebook Graph API (Instagram Graph API) to publish image posts.
 * Requires: Instagram Business or Creator account linked to a Facebook Page.
 * Connection is done via the existing Facebook OAuth flow - same FB app, extra permissions.
 *
 * Permissions needed in your FB App:
 *   - instagram_basic
 *   - instagram_content_publish
 *   - pages_read_engagement (already have this from FB setup)
 *   - pages_show_list       (already have this from FB setup)
 */
final class Instagram {

    // ── Connection ──────────────────────────────────────────────────────────

    public function is_connected() : bool {
        $s = Utils::get_settings();
        return !empty($s['ig_user_id']) && !empty($s['ig_page_token']);
    }

    public function get_token_plain() : string {
        $s = Utils::get_settings();
        return Utils::decrypt((string)($s['ig_page_token'] ?? ''));
    }

    public function get_connected_username() : string {
        $s = Utils::get_settings();
        return (string)($s['ig_username'] ?? '');
    }

    private function get_api_version() : string {
        $s = Utils::get_settings();
        $v = trim((string)($s['fb_api_version'] ?? ''));
        return $v ?: 'v24.0';
    }

    /**
     * Build OAuth URL — same FB OAuth but with instagram_* scopes added.
     * Point the user to this URL to re-authorise with IG permissions.
     */
    public function build_oauth_url() : string {
        $s      = Utils::get_settings();
        $app_id = trim((string)$s['fb_app_id']);

        $state = wp_generate_password(24, false, false);
        set_transient('aiss_ig_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS);

        $scope = implode(',', [
            'public_profile',
            'pages_show_list',
            'pages_read_engagement',
            'pages_manage_posts',
            'instagram_content_publish',
        ]);

        $redirect = $this->oauth_redirect_uri();

        return 'https://www.facebook.com/' . $this->get_api_version() . '/dialog/oauth?' .
            http_build_query([
                'client_id'     => $app_id,
                'redirect_uri'  => $redirect,
                'state'         => $state,
                'scope'         => $scope,
                'response_type' => 'code',
            ], '', '&', PHP_QUERY_RFC3986);
    }

    public function oauth_redirect_uri() : string {
        return admin_url('options-general.php?page=ai-social-share&tab=instagram&aiss_ig_cb=1');
    }

    /**
     * Handle callback: exchange code → user token → fetch IG accounts
     */
    public function handle_callback_and_fetch_accounts(string $code, string $state) : array {
        $user_id  = get_current_user_id();
        $expected = (string)get_transient('aiss_ig_state_' . $user_id);
        delete_transient('aiss_ig_state_' . $user_id);

        if ($expected === '' || !hash_equals($expected, $state)) {
            return ['ok' => false, 'error' => 'Invalid OAuth state. Please try connecting again.'];
        }

        $s          = Utils::get_settings();
        $app_id     = trim((string)$s['fb_app_id']);
        $app_secret = trim((string)$s['fb_app_secret']);

        if ($app_id === '' || $app_secret === '') {
            return ['ok' => false, 'error' => 'Facebook App ID/Secret are not set. Configure Facebook first.'];
        }

        // Exchange code for short-lived token
        $short = $this->exchange_code_for_token($code, $app_id, $app_secret);
        if (!$short['ok']) return $short;

        // Exchange for long-lived token
        $ll           = $this->exchange_for_long_lived_token($short['access_token'], $app_id, $app_secret);
        $user_token   = $ll['ok'] ? $ll['access_token'] : $short['access_token'];

        // Find Instagram Business accounts linked to Facebook Pages
        $accounts = $this->fetch_instagram_accounts($user_token);
        if (!$accounts['ok']) return $accounts;

        // Store token temporarily for the "select account" step
        set_transient('aiss_ig_user_token_' . $user_id, Utils::encrypt($user_token), 20 * MINUTE_IN_SECONDS);

        return ['ok' => true, 'accounts' => $accounts['accounts']];
    }

    /**
     * Store the chosen Instagram account
     */
    public function select_account_and_store(string $ig_user_id) : array {
        $user_id = get_current_user_id();
        $enc     = (string)get_transient('aiss_ig_user_token_' . $user_id);
        delete_transient('aiss_ig_user_token_' . $user_id);

        $user_token = Utils::decrypt($enc);
        if ($user_token === '') {
            return ['ok' => false, 'error' => 'Session expired. Please connect again.'];
        }

        $accounts = $this->fetch_instagram_accounts($user_token);
        if (!$accounts['ok']) return $accounts;

        $picked = null;
        foreach ($accounts['accounts'] as $a) {
            if ((string)$a['ig_user_id'] === (string)$ig_user_id) {
                $picked = $a;
                break;
            }
        }

        if (!$picked) {
            return ['ok' => false, 'error' => 'Selected account not found.'];
        }

        $settings                    = Utils::get_settings();
        $settings['ig_user_id']      = $picked['ig_user_id'];
        $settings['ig_username']     = $picked['ig_username'];
        $settings['ig_page_name']    = $picked['page_name'];
        $settings['ig_page_token']   = Utils::encrypt($picked['page_token']);
        $settings['ig_connected_at'] = time();

        Utils::update_settings($settings);
        return ['ok' => true];
    }

    /**
     * Disconnect Instagram
     */
    public function disconnect() : void {
        $settings = Utils::get_settings();
        $settings['ig_user_id']      = '';
        $settings['ig_username']     = '';
        $settings['ig_page_name']    = '';
        $settings['ig_page_token']   = '';
        $settings['ig_connected_at'] = 0;
        Utils::update_settings($settings);
    }

    // ── Posting ─────────────────────────────────────────────────────────────

    /**
     * Post an image to Instagram (two-step: create container → publish)
     *
     * @param int    $post_id      WordPress post ID (for logging/meta)
     * @param string $image_url    Public URL of the image (must be https)
     * @param string $caption      Instagram caption including hashtags
     * @param int    $attachment_id WP media attachment ID (stored for reference)
     */
    public function post_image(int $post_id, string $image_url, string $caption, int $attachment_id = 0) : array {
        if (!$this->is_connected()) {
            return ['ok' => false, 'error' => 'Instagram is not connected.'];
        }

        $ig_user_id = Utils::get_settings()['ig_user_id'];
        $token      = $this->get_token_plain();
        $version    = $this->get_api_version();

        SimpleScheduler::log("Instagram: Posting image to IG user {$ig_user_id}");

        // ── Step 1: Create media container ───────────────────────────────────
        $container_endpoint = "https://graph.facebook.com/{$version}/{$ig_user_id}/media";

        $container_resp = wp_remote_post($container_endpoint, [
            'timeout' => 60,
            'body'    => [
                'image_url'    => $image_url,
                'caption'      => mb_substr($caption, 0, 2200), // IG caption limit
                'access_token' => $token,
            ],
        ]);

        if (is_wp_error($container_resp)) {
            return ['ok' => false, 'error' => 'Container request failed: ' . $container_resp->get_error_message()];
        }

        $container_code = (int)wp_remote_retrieve_response_code($container_resp);
        $container_json = json_decode(wp_remote_retrieve_body($container_resp), true);

        if ($container_code < 200 || $container_code >= 300) {
            $msg = $container_json['error']['message'] ?? "HTTP {$container_code}";
            return ['ok' => false, 'error' => "Instagram container error: {$msg}"];
        }

        $creation_id = $container_json['id'] ?? '';
        if (empty($creation_id)) {
            return ['ok' => false, 'error' => 'No creation_id returned from Instagram.'];
        }

        SimpleScheduler::log("Instagram: Container created (ID: {$creation_id}), waiting 5s...");

        // Instagram needs a moment to process the image before publishing
        sleep(5);

        // ── Step 2: Publish the container ───────────────────────────────────
        $publish_endpoint = "https://graph.facebook.com/{$version}/{$ig_user_id}/media_publish";

        $publish_resp = wp_remote_post($publish_endpoint, [
            'timeout' => 30,
            'body'    => [
                'creation_id'  => $creation_id,
                'access_token' => $token,
            ],
        ]);

        if (is_wp_error($publish_resp)) {
            return ['ok' => false, 'error' => 'Publish request failed: ' . $publish_resp->get_error_message()];
        }

        $publish_code = (int)wp_remote_retrieve_response_code($publish_resp);
        $publish_json = json_decode(wp_remote_retrieve_body($publish_resp), true);

        if ($publish_code < 200 || $publish_code >= 300) {
            $msg = $publish_json['error']['message'] ?? "HTTP {$publish_code}";
            return ['ok' => false, 'error' => "Instagram publish error: {$msg}"];
        }

        $media_id = $publish_json['id'] ?? '';
        SimpleScheduler::log("Instagram: ✓ Published (Media ID: {$media_id})");

        return [
            'ok'            => true,
            'id'            => $media_id,
            'attachment_id' => $attachment_id,
        ];
    }

    // ── Health Check ─────────────────────────────────────────────────────────

    public function check_connection() : array {
        if (!$this->is_connected()) {
            return ['ok' => false, 'error' => 'Not connected'];
        }

        $s       = Utils::get_settings();
        $token   = $this->get_token_plain();
        $ig_id   = $s['ig_user_id'];
        $version = $this->get_api_version();

        $url  = "https://graph.facebook.com/{$version}/{$ig_id}?" . http_build_query([
            'access_token' => $token,
            'fields'       => 'id,username,name',
        ]);
        $resp = wp_remote_get($url, ['timeout' => 15]);

        if (is_wp_error($resp)) {
            return ['ok' => false, 'error' => $resp->get_error_message()];
        }

        $code = (int)wp_remote_retrieve_response_code($resp);
        $json = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code !== 200) {
            return ['ok' => false, 'error' => $json['error']['message'] ?? "HTTP {$code}"];
        }

        return ['ok' => true, 'username' => $json['username'] ?? '', 'name' => $json['name'] ?? ''];
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    /**
     * Fetch Instagram Business accounts linked to Facebook Pages
     */
    private function fetch_instagram_accounts(string $user_token) : array {
        $version = $this->get_api_version();

        // Get all Facebook Pages the user manages
        $url = "https://graph.facebook.com/{$version}/me/accounts?" . http_build_query([
            'access_token' => $user_token,
            'fields'       => 'id,name,access_token,instagram_business_account',
        ]);

        $resp = wp_remote_get($url, ['timeout' => 30]);

        if (is_wp_error($resp)) {
            return ['ok' => false, 'error' => $resp->get_error_message()];
        }

        $code = (int)wp_remote_retrieve_response_code($resp);
        $json = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code !== 200) {
            $msg = $json['error']['message'] ?? "HTTP {$code}";
            return ['ok' => false, 'error' => "Facebook API error: {$msg}"];
        }

        $pages    = $json['data'] ?? [];
        $accounts = [];

        foreach ($pages as $page) {
            if (empty($page['instagram_business_account']['id'])) {
                continue;
            }

            $ig_id         = $page['instagram_business_account']['id'];
            $page_token    = $page['access_token'] ?? '';

            // Fetch IG account details
            $ig_url  = "https://graph.facebook.com/{$version}/{$ig_id}?" . http_build_query([
                'access_token' => $page_token,
                'fields'       => 'id,username,name',
            ]);
            $ig_resp = wp_remote_get($ig_url, ['timeout' => 15]);

            if (is_wp_error($ig_resp)) continue;

            $ig_json = json_decode(wp_remote_retrieve_body($ig_resp), true);

            if (empty($ig_json['id'])) continue;

            $accounts[] = [
                'ig_user_id'  => $ig_json['id'],
                'ig_username' => $ig_json['username'] ?? '',
                'ig_name'     => $ig_json['name'] ?? '',
                'page_id'     => $page['id'],
                'page_name'   => $page['name'] ?? '',
                'page_token'  => $page_token,
            ];
        }

        if (empty($accounts)) {
            return [
                'ok'    => false,
                'error' => 'No Instagram Business or Creator accounts found linked to your Facebook Pages. '
                         . 'Make sure your Instagram account is set to Business or Creator mode and is connected to a Facebook Page in Instagram settings.',
            ];
        }

        return ['ok' => true, 'accounts' => $accounts];
    }

    private function exchange_code_for_token(string $code, string $app_id, string $app_secret) : array {
        $version = $this->get_api_version();
        $url     = "https://graph.facebook.com/{$version}/oauth/access_token?" . http_build_query([
            'client_id'     => $app_id,
            'redirect_uri'  => $this->oauth_redirect_uri(),
            'client_secret' => $app_secret,
            'code'          => $code,
        ], '', '&', PHP_QUERY_RFC3986);

        $resp = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($resp)) return ['ok' => false, 'error' => $resp->get_error_message()];

        $code_http = (int)wp_remote_retrieve_response_code($resp);
        $json      = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code_http >= 300 || empty($json['access_token'])) {
            $msg = $json['error']['message'] ?? "HTTP {$code_http}";
            return ['ok' => false, 'error' => "Token exchange failed: {$msg}"];
        }

        return ['ok' => true, 'access_token' => (string)$json['access_token']];
    }

    private function exchange_for_long_lived_token(string $short_token, string $app_id, string $app_secret) : array {
        $version = $this->get_api_version();
        $url     = "https://graph.facebook.com/{$version}/oauth/access_token?" . http_build_query([
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $app_id,
            'client_secret'     => $app_secret,
            'fb_exchange_token' => $short_token,
        ]);

        $resp = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($resp)) return ['ok' => false, 'error' => $resp->get_error_message()];

        $json = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($json['access_token'])) return ['ok' => false, 'error' => 'Long-lived token exchange failed.'];

        return ['ok' => true, 'access_token' => (string)$json['access_token']];
    }
}