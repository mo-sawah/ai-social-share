<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class X {

    public function is_connected() : bool {
        $s = Utils::get_settings();
        return !empty($s['x_consumer_key']) 
            && !empty($s['x_consumer_secret']) 
            && !empty($s['x_access_token']) 
            && !empty($s['x_access_secret'])
            && !empty($s['x_enabled']);
    }

    public function post_tweet(int $post_id, string $text) : array {
        if (!$this->is_connected()) {
            return ['ok' => false, 'error' => 'X is not connected or disabled.'];
        }

        $s = Utils::get_settings();
        $url = 'https://api.twitter.com/2/tweets';
        $link = get_permalink($post_id);
        
        // Append link to text if not present, or X API v2 allows strictly text
        // Usually, just appending the link to the text is the standard way for v2
        $payload = [
            'text' => $text . "\n\n" . $link
        ];

        $headers = $this->build_oauth_headers('POST', $url, $s);
        $headers[] = 'Content-Type: application/json';

        $resp = wp_remote_post($url, [
            'headers' => $headers,
            'body'    => wp_json_encode($payload),
            'timeout' => 30
        ]);

        if (is_wp_error($resp)) {
            return ['ok' => false, 'error' => $resp->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            $msg = $body;
            if (isset($json['detail'])) $msg = $json['detail'];
            if (isset($json['title'])) $msg = $json['title'] . ': ' . $msg;
            return ['ok' => false, 'error' => "X Error ($code): $msg"];
        }

        $id = $json['data']['id'] ?? '';
        return ['ok' => true, 'id' => $id, 'raw' => $json];
    }

    /**
     * OAuth 1.0a Signature Generator
     */
    private function build_oauth_headers(string $method, string $url, array $s) : array {
        $ck = $s['x_consumer_key'];
        $cs = $s['x_consumer_secret'];
        $at = $s['x_access_token'];
        $as = $s['x_access_secret'];

        $oauth = [
            'oauth_consumer_key'     => $ck,
            'oauth_nonce'            => wp_generate_password(32, false),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => time(),
            'oauth_token'            => $at,
            'oauth_version'          => '1.0'
        ];

        $base_string_parts = [];
        $base_string_parts[] = strtoupper($method);
        $base_string_parts[] = rawurlencode($url);
        
        // Sort and encode parameters
        $params = $oauth; // POST body is JSON, so not part of signature in v2 usually, 
                          // strictly query params & oauth params.
        ksort($params);

        $param_parts = [];
        foreach ($params as $k => $v) {
            $param_parts[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        $base_string_parts[] = rawurlencode(implode('&', $param_parts));

        $base_string = implode('&', $base_string_parts);

        $signing_key = rawurlencode($cs) . '&' . rawurlencode($as);
        $signature   = base64_encode(hash_hmac('sha1', $base_string, $signing_key, true));

        $oauth['oauth_signature'] = $signature;

        $header_parts = [];
        foreach ($oauth as $k => $v) {
            $header_parts[] = $k . '="' . rawurlencode($v) . '"';
        }

        return ['Authorization: OAuth ' . implode(', ', $header_parts)];
    }
}