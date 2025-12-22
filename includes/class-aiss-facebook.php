<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Facebook {

    public function is_connected() : bool {
        $s = Utils::get_settings();
        return !empty($s['fb_page_id']) && !empty($s['fb_page_token']);
    }

    public function get_page_token_plain() : string {
        $s = Utils::get_settings();
        return Utils::decrypt((string)$s['fb_page_token']);
    }

    public function oauth_redirect_uri() : string {
        // Facebook must have this exact URL in the app settings (Valid OAuth Redirect URIs)
        return Utils::admin_url_settings(['tab' => 'facebook', 'aiss_fb_cb' => 1]);
    }

    public function build_oauth_url() : string {
        $s = Utils::get_settings();
        $app_id = trim((string)$s['fb_app_id']);
        $redirect = $this->oauth_redirect_uri();

        $state = wp_generate_password(24, false, false);
        set_transient('aiss_fb_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS);

        $scope = implode(',', [
            'public_profile',
            'pages_show_list',
            'pages_read_engagement',
            'pages_manage_posts',
        ]);

        $args = [
            'client_id' => $app_id,
            'redirect_uri' => $redirect,
            'state' => $state,
            'scope' => $scope,
            'response_type' => 'code',
        ];

        // Use a stable dialog endpoint (versioned)
        return add_query_arg($args, 'https://www.facebook.com/' . $this->get_api_version() . '/dialog/oauth');
    }

    public function get_api_version() : string {
        $s = Utils::get_settings();
        $v = trim((string)$s['fb_api_version']);
        if ($v === '') { $v = 'v24.0'; }
        return $v;
    }

    public function handle_callback_and_fetch_pages(string $code, string $state) : array {
        $user_id = get_current_user_id();
        $expected = (string)get_transient('aiss_fb_state_' . $user_id);
        delete_transient('aiss_fb_state_' . $user_id);

        if ($expected === '' || !hash_equals($expected, $state)) {
            return ['ok' => false, 'error' => 'Invalid OAuth state. Please try connecting again.'];
        }

        $s = Utils::get_settings();
        $app_id = trim((string)$s['fb_app_id']);
        $app_secret = trim((string)$s['fb_app_secret']);
        $redirect = $this->oauth_redirect_uri();

        if ($app_id === '' || $app_secret === '') {
            return ['ok' => false, 'error' => 'Facebook App ID/Secret are not set.'];
        }

        // Exchange code for short-lived user token (manual flow)
        $token = $this->exchange_code_for_token($code, $app_id, $app_secret, $redirect);
        if (!$token['ok']) { return $token; }

        // Exchange for long-lived user token (recommended)
        $ll = $this->exchange_for_long_lived_user_token($token['access_token'], $app_id, $app_secret);
        $user_token = $ll['ok'] ? $ll['access_token'] : $token['access_token'];

        // Fetch pages the user manages
        $pages = $this->get_pages($user_token);
        if (!$pages['ok']) { return $pages; }

        // Save user token temporarily (encrypted) to use when selecting a page
        set_transient('aiss_fb_user_token_' . $user_id, Utils::encrypt($user_token), 20 * MINUTE_IN_SECONDS);

        return ['ok' => true, 'pages' => $pages['pages']];
    }

    public function select_page_and_store(string $page_id) : array {
        $user_id = get_current_user_id();
        $enc = (string)get_transient('aiss_fb_user_token_' . $user_id);
        delete_transient('aiss_fb_user_token_' . $user_id);
        $user_token = Utils::decrypt($enc);

        if ($user_token === '') {
            return ['ok' => false, 'error' => 'Missing user token. Please connect again.'];
        }

        $pages = $this->get_pages($user_token);
        if (!$pages['ok']) { return $pages; }

        $picked = null;
        foreach ($pages['pages'] as $p) {
            if (!empty($p['id']) && (string)$p['id'] === (string)$page_id) {
                $picked = $p;
                break;
            }
        }
        if (!$picked) {
            return ['ok' => false, 'error' => 'Selected page not found in your managed pages list.'];
        }
        if (empty($picked['access_token'])) {
            return ['ok' => false, 'error' => 'Facebook did not return a Page access token. Ensure permissions are granted and try again.'];
        }

        $settings = Utils::get_settings();
        $settings['fb_page_id']      = (string)$picked['id'];
        $settings['fb_page_name']    = isset($picked['name']) ? (string)$picked['name'] : '';
        $settings['fb_page_token']   = Utils::encrypt((string)$picked['access_token']);
        $settings['fb_connected_at'] = time();

        update_option('aiss_settings', $settings, false);

        return ['ok' => true];
    }

    public function share_link_post(int $post_id, string $message) : array {
        $s = Utils::get_settings();
        $page_id = (string)$s['fb_page_id'];
        $token   = $this->get_page_token_plain();

        if ($page_id === '' || $token === '') {
            return ['ok' => false, 'error' => 'Facebook is not connected.'];
        }

        $url = get_permalink($post_id);
        $endpoint = 'https://graph.facebook.com/' . $this->get_api_version() . '/' . rawurlencode($page_id) . '/feed';

        $args = [
            'message' => $message,
            'link' => $url,
            'access_token' => $token,
        ];

        $resp = wp_remote_post($endpoint, [
            'timeout' => 30,
            'body' => $args,
        ]);

        if (is_wp_error($resp)) {
            return ['ok' => false, 'error' => $resp->get_error_message()];
        }

        $code = (int)wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            $msg = is_array($json) && isset($json['error']['message']) ? (string)$json['error']['message'] : $body;
            return ['ok' => false, 'error' => "Facebook error ({$code}): " . $msg];
        }

        $remote_id = is_array($json) && isset($json['id']) ? (string)$json['id'] : '';
        return ['ok' => true, 'id' => $remote_id, 'raw' => $json];
    }

    private function exchange_code_for_token(string $code, string $app_id, string $app_secret, string $redirect_uri) : array {
        $endpoint = 'https://graph.facebook.com/' . $this->get_api_version() . '/oauth/access_token';
        $url = add_query_arg([
            'client_id' => $app_id,
            'redirect_uri' => $redirect_uri,
            'client_secret' => $app_secret,
            'code' => $code,
        ], $endpoint);

        $resp = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($resp)) {
            return ['ok' => false, 'error' => $resp->get_error_message()];
        }

        $code_http = (int)wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);

        if ($code_http < 200 || $code_http >= 300) {
            $msg = is_array($json) && isset($json['error']['message']) ? (string)$json['error']['message'] : $body;
            return ['ok' => false, 'error' => "Facebook token exchange failed ({$code_http}): " . $msg];
        }

        if (!is_array($json) || empty($json['access_token'])) {
            return ['ok' => false, 'error' => 'Facebook token exchange returned an unexpected response.'];
        }

        return ['ok' => true, 'access_token' => (string)$json['access_token'], 'expires_in' => (int)($json['expires_in'] ?? 0)];
    }

    private function exchange_for_long_lived_user_token(string $short_user_token, string $app_id, string $app_secret) : array {
        $endpoint = 'https://graph.facebook.com/' . $this->get_api_version() . '/oauth/access_token';
        $url = add_query_arg([
            'grant_type' => 'fb_exchange_token',
            'client_id' => $app_id,
            'client_secret' => $app_secret,
            'fb_exchange_token' => $short_user_token,
        ], $endpoint);

        $resp = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($resp)) {
            return ['ok' => false, 'error' => $resp->get_error_message()];
        }

        $code_http = (int)wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);

        if ($code_http < 200 || $code_http >= 300) {
            return ['ok' => false, 'error' => 'Long-lived token exchange failed.'];
        }

        if (!is_array($json) || empty($json['access_token'])) {
            return ['ok' => false, 'error' => 'Long-lived token exchange returned an unexpected response.'];
        }

        return ['ok' => true, 'access_token' => (string)$json['access_token'], 'expires_in' => (int)($json['expires_in'] ?? 0)];
    }

    private function get_pages(string $user_token) : array {
        $endpoint = 'https://graph.facebook.com/' . $this->get_api_version() . '/me/accounts';
        $url = add_query_arg([
            'access_token' => $user_token,
            'fields' => 'id,name,access_token',
        ], $endpoint);

        $resp = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($resp)) {
            return ['ok' => false, 'error' => $resp->get_error_message()];
        }

        $code_http = (int)wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);

        if ($code_http < 200 || $code_http >= 300) {
            $msg = is_array($json) && isset($json['error']['message']) ? (string)$json['error']['message'] : $body;
            return ['ok' => false, 'error' => "Facebook pages fetch failed ({$code_http}): " . $msg];
        }

        $data = is_array($json) && isset($json['data']) && is_array($json['data']) ? $json['data'] : [];
        return ['ok' => true, 'pages' => $data];
    }
}
