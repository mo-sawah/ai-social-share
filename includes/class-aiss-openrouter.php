<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class OpenRouter {

    public function generate_facebook_post(int $post_id, string $override_prompt = '') : array {
        $settings = Utils::get_settings();
        $api_key = trim((string)$settings['openrouter_api_key']);
        if ($api_key === '') {
            return ['ok' => false, 'error' => 'OpenRouter API key is not set.'];
        }

        $post = get_post($post_id);
        if (!$post) {
            return ['ok' => false, 'error' => 'Post not found.'];
        }

        $title = get_the_title($post_id);
        $url   = get_permalink($post_id);

        $excerpt = has_excerpt($post_id) ? get_the_excerpt($post_id) : '';
        $content = Utils::clean_text($post->post_content, 5000);

        $prompt = $override_prompt !== '' ? $override_prompt : (string)$settings['prompt_facebook'];

        $messages = [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => "ARTICLE TITLE:\n{$title}\n\nARTICLE EXCERPT:\n{$excerpt}\n\nARTICLE TEXT (CLEAN):\n{$content}\n\nURL:\n{$url}"],
        ];

        $model = (string)$settings['openrouter_model'];
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.6,
        ];

        $headers = [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ];

        // Optional but recommended by OpenRouter for attribution
        $site = trim((string)$settings['openrouter_site']);
        $app  = trim((string)$settings['openrouter_app']);
        if ($site !== '') { $headers['HTTP-Referer'] = $site; }
        if ($app  !== '') { $headers['X-Title']      = $app; }

        $resp = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'timeout' => 45,
            'headers' => $headers,
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($resp)) {
            return ['ok' => false, 'error' => $resp->get_error_message()];
        }

        $code = (int)wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            $msg = is_array($json) && isset($json['error']['message']) ? (string)$json['error']['message'] : $body;
            return ['ok' => false, 'error' => "OpenRouter error ({$code}): " . $msg];
        }

        $text = '';
        if (is_array($json) && isset($json['choices'][0]['message']['content'])) {
            $text = (string)$json['choices'][0]['message']['content'];
        }
        $text = trim($text);

        if ($text === '') {
            return ['ok' => false, 'error' => 'OpenRouter returned empty content.'];
        }

        return ['ok' => true, 'text' => $text, 'raw' => $json];
    }
}
