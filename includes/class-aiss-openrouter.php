<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class OpenRouter {

    public function generate_post(int $post_id, string $prompt_template) : array {
        $settings = Utils::get_settings();
        $api_key = trim((string)$settings['openrouter_api_key']);
        
        if (!$api_key) return ['ok' => false, 'error' => 'No API Key'];

        $post = get_post($post_id);
        if (!$post) return ['ok' => false, 'error' => 'Post not found'];

        $content = Utils::clean_text($post->post_content, 4000);
        
        // Prepare Payload
        $payload = [
            'model' => $settings['openrouter_model'],
            'messages' => [
                ['role' => 'system', 'content' => $prompt_template],
                ['role' => 'user', 'content' => "TITLE: ".get_the_title($post_id)."\n\nCONTENT: ".$content]
            ]
        ];

        // ADD WEB SEARCH if enabled
        if (!empty($settings['enable_web_search'])) {
            $payload['plugins'] = [['id' => 'web']];
        }

        $resp = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => $settings['openrouter_site'],
                'X-Title'       => $settings['openrouter_app'],
            ],
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($resp)) return ['ok' => false, 'error' => $resp->get_error_message()];
        
        $json = json_decode(wp_remote_retrieve_body($resp), true);
        $text = $json['choices'][0]['message']['content'] ?? '';
        
        return $text ? ['ok' => true, 'text' => trim($text)] : ['ok' => false, 'error' => 'Empty AI response'];
    }
}