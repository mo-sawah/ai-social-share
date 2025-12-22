<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class X {

    // Endpoints
    const URL_REQUEST_TOKEN = 'https://api.twitter.com/oauth/request_token';
    const URL_AUTHORIZE     = 'https://api.twitter.com/oauth/authorize';
    const URL_ACCESS_TOKEN  = 'https://api.twitter.com/oauth/access_token';
    const URL_POST_TWEET    = 'https://api.twitter.com/2/tweets';

    /**
     * Check if we have the User tokens (Client is connected)
     */
    public function is_connected() : bool {
        $s = Utils::get_settings();
        return !empty($s['x_enabled']) 
            && !empty($s['x_consumer_key']) 
            && !empty($s['x_consumer_secret']) 
            && !empty($s['x_access_token']) // This is now the USER token
            && !empty($s['x_access_secret']);
    }

    /**
     * Step 1: Get the Authorization URL for the button
     */
    public function get_auth_url() : array {
        $s = Utils::get_settings();
        if (empty($s['x_consumer_key']) || empty($s['x_consumer_secret'])) {
            return ['ok' => false, 'error' => 'App Keys missing'];
        }

        // Callback URL (Must match Twitter Dev Portal)
        $callback = admin_url('admin-post.php?action=aiss_x_callback');

        // Generate Signature for Request Token
        $oauth = $this->build_oauth_params($s['x_consumer_key']);
        $oauth['oauth_callback'] = $callback;
        $signature = $this->sign_request('POST', self::URL_REQUEST_TOKEN, $oauth, $s['x_consumer_secret']);
        $oauth['oauth_signature'] = $signature;

        // Make Request
        $auth_header = $this->build_auth_header($oauth);
        $resp = wp_remote_post(self::URL_REQUEST_TOKEN, [
            'headers' => ['Authorization' => $auth_header],
            'timeout' => 30
        ]);

        if (is_wp_error($resp)) return ['ok' => false, 'error' => $resp->get_error_message()];
        
        $body = wp_remote_retrieve_body($resp);
        parse_str($body, $result); // oauth_token, oauth_token_secret, oauth_callback_confirmed

        if (!isset($result['oauth_token'])) {
            return ['ok' => false, 'error' => 'Failed to get request token: ' . $body];
        }

        // Save secret temporarily to verify later
        set_transient('aiss_x_temp_secret_' . $result['oauth_token'], $result['oauth_token_secret'], 600);

        // Return the Redirect URL
        return ['ok' => true, 'url' => self::URL_AUTHORIZE . '?oauth_token=' . $result['oauth_token']];
    }

    /**
     * Step 2: Handle the Callback (Code -> Access Token)
     */
    public function handle_callback($token, $verifier) : array {
        $s = Utils::get_settings();
        
        // Retrieve stored temp secret
        $temp_secret = get_transient('aiss_x_temp_secret_' . $token);
        if (!$temp_secret) return ['ok' => false, 'error' => 'Session expired. Try again.'];
        delete_transient('aiss_x_temp_secret_' . $token);

        // Prepare for Access Token Exchange
        $oauth = $this->build_oauth_params($s['x_consumer_key'], $token);
        $oauth['oauth_verifier'] = $verifier;
        
        // Sign with Consumer Secret AND Temp Token Secret
        $signature = $this->sign_request('POST', self::URL_ACCESS_TOKEN, $oauth, $s['x_consumer_secret'], $temp_secret);
        $oauth['oauth_signature'] = $signature;

        // Exchange
        $auth_header = $this->build_auth_header($oauth);
        $resp = wp_remote_post(self::URL_ACCESS_TOKEN, [
            'headers' => ['Authorization' => $auth_header],
            'timeout' => 30
        ]);

        if (is_wp_error($resp)) return ['ok' => false, 'error' => $resp->get_error_message()];
        
        parse_str(wp_remote_retrieve_body($resp), $result);

        if (!isset($result['oauth_token']) || !isset($result['oauth_token_secret'])) {
            return ['ok' => false, 'error' => 'Token exchange failed.'];
        }

        return [
            'ok' => true,
            'token' => $result['oauth_token'],
            'secret' => $result['oauth_token_secret'],
            'screen_name' => $result['screen_name'] ?? ''
        ];
    }

    /**
     * Post Tweet (Using stored User Tokens)
     */
    public function post_tweet(int $post_id, string $text) : array {
        if (!$this->is_connected()) return ['ok' => false, 'error' => 'Not connected'];

        $s = Utils::get_settings();
        $link = get_permalink($post_id);
        $payload = ['text' => $text . "\n\n" . $link];

        // Sign Request
        $oauth = $this->build_oauth_params($s['x_consumer_key'], $s['x_access_token']);
        $signature = $this->sign_request('POST', self::URL_POST_TWEET, $oauth, $s['x_consumer_secret'], $s['x_access_secret']);
        $oauth['oauth_signature'] = $signature;

        $resp = wp_remote_post(self::URL_POST_TWEET, [
            'headers' => [
                'Authorization' => $this->build_auth_header($oauth),
                'Content-Type'  => 'application/json'
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 30
        ]);

        if (is_wp_error($resp)) return ['ok' => false, 'error' => $resp->get_error_message()];

        $json = json_decode(wp_remote_retrieve_body($resp), true);
        if (!isset($json['data']['id'])) {
            $err = $json['detail'] ?? ($json['title'] ?? 'Unknown X Error');
            return ['ok' => false, 'error' => $err];
        }

        return ['ok' => true, 'id' => $json['data']['id']];
    }

    // --- Helpers ---

    private function build_oauth_params($key, $token = null) {
        $p = [
            'oauth_consumer_key'     => $key,
            'oauth_nonce'            => wp_generate_password(32, false),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => time(),
            'oauth_version'          => '1.0'
        ];
        if ($token) $p['oauth_token'] = $token;
        return $p;
    }

    private function sign_request($method, $url, $params, $consumer_secret, $token_secret = '') {
        ksort($params);
        $qs = [];
        foreach ($params as $k => $v) $qs[] = rawurlencode($k) . '=' . rawurlencode($v);
        $base = strtoupper($method) . '&' . rawurlencode($url) . '&' . rawurlencode(implode('&', $qs));
        $key = rawurlencode($consumer_secret) . '&' . rawurlencode($token_secret);
        return base64_encode(hash_hmac('sha1', $base, $key, true));
    }

    private function build_auth_header($params) {
        $h = [];
        foreach ($params as $k => $v) $h[] = $k . '="' . rawurlencode($v) . '"';
        return 'OAuth ' . implode(', ', $h);
    }
}