<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class OpenRouter {

    public function generate_post(int $post_id, string $prompt_template) : array {
        $settings = Utils::get_settings();
        $api_key = trim((string)$settings['openrouter_api_key']);
        
        if ($api_key === '') return ['ok' => false, 'error' => 'Missing API key'];

        $post = get_post($post_id);
        if (!$post) return ['ok' => false, 'error' => 'Post not found'];

        $title = get_the_title($post_id);
        $content = Utils::clean_text($post->post_content, 4000);

        $messages = [
            ['role' => 'system', 'content' => $prompt_template],
            ['role' => 'user', 'content' => "TITLE: $title\n\nCONTENT: $content"],
        ];

        $resp = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => $settings['openrouter_site'],
                'X-Title'       => $settings['openrouter_app'],
            ],
            'body'    => wp_json_encode([
                'model' => $settings['openrouter_model'],
                'messages' => $messages
            ]),
        ]);

        if (is_wp_error($resp)) return ['ok' => false, 'error' => $resp->get_error_message()];
        
        $json = json_decode(wp_remote_retrieve_body($resp), true);
        $text = $json['choices'][0]['message']['content'] ?? '';
        
        if (!$text) return ['ok' => false, 'error' => 'Empty AI response'];

        return ['ok' => true, 'text' => trim($text)];
    }
    
    // Backward compatibility if needed
    public function generate_facebook_post($id) {
        return $this->generate_post($id, Utils::get_settings()['prompt_facebook']);
    }
}