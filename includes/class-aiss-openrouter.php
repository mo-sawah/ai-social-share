<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class OpenRouter {

    /**
     * Generate social media post using AI
     */
    public function generate_post(int $post_id, string $prompt_template) : array {
        $settings = Utils::get_settings();
        $api_key = trim((string)$settings['openrouter_api_key']);
        
        if (empty($api_key)) {
            return ['ok' => false, 'error' => 'OpenRouter API Key is missing.'];
        }

        $post = get_post($post_id);
        if (!$post) {
            return ['ok' => false, 'error' => 'Post not found.'];
        }

        // Get language name
        $language = $this->get_language_name($settings['post_language'] ?? 'en');
        
        // Replace {language} placeholder in prompt
        $prompt_template = str_replace('{language}', $language, $prompt_template);

        // Prepare post data
        $title = get_the_title($post_id);
        $content = Utils::clean_text($post->post_content, 4000);
        
        // Get excerpt if available
        $excerpt = '';
        if (!empty($post->post_excerpt)) {
            $excerpt = Utils::clean_text($post->post_excerpt, 300);
        }

        // Get categories/tags for context
        $categories = wp_get_post_categories($post_id, ['fields' => 'names']);
        $tags = wp_get_post_tags($post_id, ['fields' => 'names']);
        
        $context = [];
        if (!empty($categories)) {
            $context[] = 'Categories: ' . implode(', ', array_slice($categories, 0, 3));
        }
        if (!empty($tags)) {
            $context[] = 'Tags: ' . implode(', ', array_slice($tags, 0, 5));
        }
        $context_str = !empty($context) ? "\n\n" . implode("\n", $context) : '';

        // Build user message
        $user_message = "ARTICLE TITLE: {$title}\n\n";
        if ($excerpt) {
            $user_message .= "EXCERPT: {$excerpt}\n\n";
        }
        $user_message .= "ARTICLE CONTENT: {$content}";
        $user_message .= $context_str;

        // Prepare API payload
        $payload = [
            'model' => $settings['openrouter_model'],
            'messages' => [
                [
                    'role' => 'system', 
                    'content' => $prompt_template
                ],
                [
                    'role' => 'user', 
                    'content' => $user_message
                ],
            ],
            'temperature' => 0.7,
            'max_tokens' => 500, // Reasonable limit for social posts
        ];

        // Add web search if enabled
        if (!empty($settings['enable_web_search'])) {
            $payload['plugins'] = [
                ['id' => 'web']
            ];
        }

        // Make API request with retry logic
        $result = $this->make_api_request($api_key, $payload, $settings);
        
        if (!$result['ok']) {
            return $result;
        }

        // Post-process the generated text
        $text = $this->post_process_text($result['text']);
        
        return ['ok' => true, 'text' => $text];
    }

    /**
     * Make API request with retry logic
     */
    private function make_api_request(string $api_key, array $payload, array $settings, int $retry = 0) : array {
        $max_retries = 2;
        
        $args = [
            'timeout' => 60, // Increased timeout for AI processing
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => $settings['openrouter_site'],
                'X-Title'       => $settings['openrouter_app'],
            ],
            'body' => wp_json_encode($payload),
        ];

        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', $args);

        // Handle WordPress HTTP errors
        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            
            // Retry on timeout or connection errors
            if ($retry < $max_retries && (
                strpos($error_msg, 'timeout') !== false || 
                strpos($error_msg, 'connection') !== false
            )) {
                sleep(2); // Wait before retry
                return $this->make_api_request($api_key, $payload, $settings, $retry + 1);
            }
            
            return ['ok' => false, 'error' => 'Network error: ' . $error_msg];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Parse JSON response
        $json = json_decode($body, true);
        
        // Handle HTTP errors
        if ($status_code < 200 || $status_code >= 300) {
            $error_msg = $this->extract_error_message($json, $body, $status_code);
            
            // Retry on server errors (5xx)
            if ($retry < $max_retries && $status_code >= 500) {
                sleep(3); // Longer wait for server errors
                return $this->make_api_request($api_key, $payload, $settings, $retry + 1);
            }
            
            // Retry on rate limit (429) with exponential backoff
            if ($retry < $max_retries && $status_code === 429) {
                $wait = pow(2, $retry + 1); // 2, 4, 8 seconds
                sleep($wait);
                return $this->make_api_request($api_key, $payload, $settings, $retry + 1);
            }
            
            return ['ok' => false, 'error' => $error_msg];
        }

        // Extract AI response
        $text = $this->extract_response_text($json);
        
        if (empty($text)) {
            return ['ok' => false, 'error' => 'AI returned empty response. Response: ' . substr($body, 0, 200)];
        }

        return ['ok' => true, 'text' => $text];
    }

    /**
     * Extract error message from API response
     */
    private function extract_error_message($json, string $body, int $status_code) : string {
        if (is_array($json)) {
            // OpenRouter error format
            if (isset($json['error']['message'])) {
                return "OpenRouter error ({$status_code}): " . $json['error']['message'];
            }
            // Alternative error format
            if (isset($json['message'])) {
                return "API error ({$status_code}): " . $json['message'];
            }
        }
        
        // Provide helpful messages for common status codes
        switch ($status_code) {
            case 401:
                return 'Authentication failed. Check your OpenRouter API key.';
            case 403:
                return 'Access forbidden. Verify API key permissions.';
            case 429:
                return 'Rate limit exceeded. Try again later or reduce frequency.';
            case 500:
            case 502:
            case 503:
                return 'OpenRouter server error. This is temporary, will retry automatically.';
            default:
                return "API error ({$status_code}): " . substr($body, 0, 200);
        }
    }

    /**
     * Extract response text from JSON
     */
    private function extract_response_text($json) : string {
        if (!is_array($json)) {
            return '';
        }

        // Standard OpenAI format
        if (isset($json['choices'][0]['message']['content'])) {
            return trim($json['choices'][0]['message']['content']);
        }
        
        // Alternative format
        if (isset($json['choices'][0]['text'])) {
            return trim($json['choices'][0]['text']);
        }
        
        // Streaming format (shouldn't happen with this implementation)
        if (isset($json['choices'][0]['delta']['content'])) {
            return trim($json['choices'][0]['delta']['content']);
        }

        return '';
    }

    /**
     * Post-process generated text
     */
    private function post_process_text(string $text) : string {
        // Remove markdown code fences if present
        $text = preg_replace('/^```(?:text|markdown)?\s*\n/m', '', $text);
        $text = preg_replace('/\n```\s*$/m', '', $text);
        
        // Remove any leading/trailing quotes
        $text = trim($text, '"\'');
        
        // Normalize line breaks (some AIs use different formats)
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        
        // Remove excessive line breaks (more than 2 consecutive)
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        
        // Trim
        $text = trim($text);
        
        return $text;
    }

    /**
     * Get language name from code
     */
    private function get_language_name(string $code) : string {
        $languages = [
            'en' => 'English',
            'el' => 'Greek',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'ru' => 'Russian',
            'ar' => 'Arabic',
            'zh' => 'Chinese',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'tr' => 'Turkish',
            'nl' => 'Dutch',
            'pl' => 'Polish',
        ];
        
        return $languages[$code] ?? 'English';
    }

    /**
     * Test API connection
     */
    public function test_connection() : array {
        $settings = Utils::get_settings();
        $api_key = trim((string)$settings['openrouter_api_key']);
        
        if (empty($api_key)) {
            return ['ok' => false, 'error' => 'API Key is missing'];
        }

        $payload = [
            'model' => $settings['openrouter_model'],
            'messages' => [
                ['role' => 'user', 'content' => 'Say "Connection successful"']
            ],
            'max_tokens' => 10
        ];

        $result = $this->make_api_request($api_key, $payload, $settings);
        
        if ($result['ok']) {
            return ['ok' => true, 'message' => 'API connection successful'];
        }
        
        return $result;
    }
}