<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class X {

    /**
     * Checks if X is enabled and all keys are present.
     */
    public function is_connected() : bool {
        $s = Utils::get_settings();
        
        if (empty($s['x_enabled'])) {
            return false;
        }
        
        if (empty($s['x_consumer_key']) || empty($s['x_consumer_secret'])) {
            return false;
        }

        if (empty($s['x_access_token']) || empty($s['x_access_secret'])) {
            return false;
        }

        return true;
    }

    /**
     * Posts a tweet with the article link.
     */
    public function post_tweet(int $post_id, string $text) : array {
        if (!$this->is_connected()) {
            return ['ok' => false, 'error' => 'X is disabled or keys are missing.'];
        }

        $settings = Utils::get_settings();
        $endpoint = 'https://api.twitter.com/2/tweets';
        $link = get_permalink($post_id);
        
        // For API v2, the link is usually just part of the text
        $final_text = $text . "\n\n" . $link;

        $payload = [
            'text' => $final_text
        ];

        // Generate OAuth 1.0a headers
        $auth_header = $this->build_oauth_headers('POST', $endpoint, $settings);

        $args = [
            'headers' => [
                'Authorization' => $auth_header,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 30,
        ];

        $response = wp_remote_post($endpoint, $args);

        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        // Success is typically 201 Created
        if ($code < 200 || $code >= 300) {
            $error_msg = 'Unknown error';
            
            // Try to find the error message in the JSON response
            if (isset($json['detail'])) {
                $error_msg = $json['detail'];
            } elseif (isset($json['title'])) {
                $error_msg = $json['title'];
            } elseif (is_string($body)) {
                $error_msg = $body;
            }

            return ['ok' => false, 'error' => "X API Error ({$code}): " . $error_msg];
        }

        $tweet_id = isset($json['data']['id']) ? (string)$json['data']['id'] : '';

        return ['ok' => true, 'id' => $tweet_id, 'raw' => $json];
    }

    /**
     * Generates the OAuth 1.0a Authorization header.
     */
    private function build_oauth_headers(string $method, string $url, array $s) : string {
        $consumer_key = $s['x_consumer_key'];
        $consumer_secret = $s['x_consumer_secret'];
        $token = $s['x_access_token'];
        $token_secret = $s['x_access_secret'];

        $oauth = [
            'oauth_consumer_key'     => $consumer_key,
            'oauth_nonce'            => wp_generate_password(32, false, false),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => time(),
            'oauth_token'            => $token,
            'oauth_version'          => '1.0'
        ];

        // 1. Collect parameters
        $base_params = $oauth;
        ksort($base_params);

        // 2. Create Parameter String
        $param_parts = [];
        foreach ($base_params as $key => $value) {
            $param_parts[] = rawurlencode($key) . '=' . rawurlencode($value);
        }
        $param_string = implode('&', $param_parts);

        // 3. Create Signature Base String
        $base_string = strtoupper($method) . '&' 
                     . rawurlencode($url) . '&' 
                     . rawurlencode($param_string);

        // 4. Create Signing Key
        $signing_key = rawurlencode($consumer_secret) . '&' . rawurlencode($token_secret);

        // 5. Generate Signature
        $signature = base64_encode(hash_hmac('sha1', $base_string, $signing_key, true));
        $oauth['oauth_signature'] = $signature;

        // 6. Build Header String
        $header_parts = [];
        foreach ($oauth as $key => $value) {
            $header_parts[] = $key . '="' . rawurlencode($value) . '"';
        }

        return 'OAuth ' . implode(', ', $header_parts);
    }
}