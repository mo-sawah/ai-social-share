<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class OpenRouter {

    public function generate_post(int $post_id, string $prompt) : array {
        $s = Utils::get_settings();
        if (empty($s['openrouter_api_key'])) return ['ok' => false, 'error' => 'No API Key'];

        $post = get_post($post_id);
        $content = Utils::clean_text($post->post_content, 4000);

        $resp = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'timeout' => 45,
            'headers' => ['Authorization' => 'Bearer ' . $s['openrouter_api_key'], 'Content-Type' => 'application/json', 'HTTP-Referer' => $s['openrouter_site']],
            'body'    => wp_json_encode([
                'model' => $s['openrouter_model'],
                'messages' => [['role'=>'system','content'=>$prompt], ['role'=>'user','content'=>"TITLE: ".get_the_title($post_id)."\n\nCONTENT: ".$content]]
            ]),
        ]);

        if (is_wp_error($resp)) return ['ok' => false, 'error' => $resp->get_error_message()];
        $json = json_decode(wp_remote_retrieve_body($resp), true);
        $text = $json['choices'][0]['message']['content'] ?? '';
        
        return $text ? ['ok' => true, 'text' => trim($text)] : ['ok' => false, 'error' => 'Empty AI response'];
    }
}