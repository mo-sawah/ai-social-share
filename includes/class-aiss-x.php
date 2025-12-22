<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class X {

    public function is_connected() : bool {
        $s = Utils::get_settings();
        return !empty($s['x_consumer_key']) && !empty($s['x_consumer_secret']) 
            && !empty($s['x_access_token']) && !empty($s['x_access_secret'])
            && !empty($s['x_enabled']);
    }

    public function post_tweet(int $post_id, string $text) : array {
        if (!$this->is_connected()) return ['ok' => false, 'error' => 'X disabled/not connected'];

        $s = Utils::get_settings();
        $url = 'https://api.twitter.com/2/tweets';
        $link = get_permalink($post_id);
        
        $payload = [ 'text' => $text . "\n\n" . $link ]; // V2 requires text body

        $headers = $this->build_oauth_headers('POST', $url, $s);
        $headers[] = 'Content-Type: application/json';

        $resp = wp_remote_post($url, [
            'headers' => $headers,
            'body'    => wp_json_encode($payload),
            'timeout' => 30
        ]);

        if (is_wp_error($resp)) return ['ok' => false, 'error' => $resp->get_error_message()];

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            $msg = $json['detail'] ?? ($json['title'] ?? $body);
            return ['ok' => false, 'error' => "X Error ($code): $msg"];
        }

        return ['ok' => true, 'id' => $json['data']['id'] ?? '', 'raw' => $json];
    }

    private function build_oauth_headers(string $method, string $url, array $s) : array {
        $oauth = [
            'oauth_consumer_key' => $s['x_consumer_key'],
            'oauth_nonce' => wp_generate_password(32, false),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => time(),
            'oauth_token' => $s['x_access_token'],
            'oauth_version' => '1.0'
        ];

        $parts = [strtoupper($method), rawurlencode($url)];
        $params = $oauth; 
        ksort($params);
        
        $qs = [];
        foreach ($params as $k => $v) $qs[] = rawurlencode($k) . '=' . rawurlencode($v);
        $parts[] = rawurlencode(implode('&', $qs));

        $key = rawurlencode($s['x_consumer_secret']) . '&' . rawurlencode($s['x_access_secret']);
        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', implode('&', $parts), $key, true));

        $h = [];
        foreach ($oauth as $k => $v) $h[] = $k . '="' . rawurlencode($v) . '"';
        return ['Authorization: OAuth ' . implode(', ', $h)];
    }
}