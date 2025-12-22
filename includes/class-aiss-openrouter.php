<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class OpenRouter {

    public function generate_post(int $post_id, string $prompt_template) : array {
        $settings = Utils::get_settings();
        $api_key = trim((string)$settings['openrouter_api_key']);
        
        if ($api_key === '') {
            return ['ok' => false, 'error' => 'OpenRouter API Key is missing. Check settings.'];
        }

        $post = get_post($post_id);
        if (!$post) {
            return ['ok' => false, 'error' => 'Post not found.'];
        }

        $title = get_the_title($post_id);
        $content = Utils::clean_text($post->post_content, 4000);

        // Build Messages
        $messages = [
            [
                'role' => 'system', 
                'content' => $prompt_template
            ],
            [
                'role' => 'user', 
                'content' => "TITLE: {$title}\n\nCONTENT: {$content}"
            ],
        ];

        // API Request
        $args = [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => $settings['openrouter_site'],
                'X-Title'       => $settings['openrouter_app'],
            ],
            'body'    => wp_json_encode([
                'model' => $settings['openrouter_model'],
                'messages' => $messages,
                'temperature' => 0.7
            ]),
        ];

        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', $args);

        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message()];
        }

        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        // Extract Text
        $text = '';
        if (isset($json['choices'][0]['message']['content'])) {
            $text = $json['choices'][0]['message']['content'];
        }

        $text = trim($text);
        if (empty($text)) {
            return ['ok' => false, 'error' => 'AI returned empty response.'];
        }

        return ['ok' => true, 'text' => $text];
    }
}