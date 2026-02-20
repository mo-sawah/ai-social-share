<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

/**
 * Image Generator
 *
 * Uses Google Gemini 2.5 Flash Image ("Nano Banana") via OpenRouter to generate
 * a photorealistic social media image AND an Instagram caption in a single API call.
 *
 * OpenRouter model: google/gemini-2.5-flash-image-preview
 * Response format:  choices[0].message.images[0].image_url.url  (base64 data URL)
 *                   choices[0].message.content                   (caption text)
 *
 * The generated image is saved to the WordPress Media Library so it gets a
 * public https:// URL that Instagram can fetch.
 */
final class ImageGenerator {

    /**
     * Generate Instagram image + caption for a post.
     *
     * @return array {
     *   ok: bool,
     *   caption: string,
     *   image_url: string,       Public URL in WP Media Library
     *   attachment_id: int,
     *   error?: string
     * }
     */
    public function generate_for_instagram(int $post_id) : array {
        $settings     = Utils::get_settings();
        $api_key      = trim((string)$settings['openrouter_api_key']);
        $image_model  = trim((string)($settings['ig_image_model'] ?? 'google/gemini-2.5-flash-image-preview:free'));
        $site_name    = get_bloginfo('name');
        $brand_colors = trim((string)($settings['ig_brand_colors'] ?? ''));
        $language     = $this->get_language_name($settings['post_language'] ?? 'en');

        if (empty($api_key)) {
            return ['ok' => false, 'error' => 'OpenRouter API Key is missing.'];
        }

        $post = get_post($post_id);
        if (!$post) {
            return ['ok' => false, 'error' => "Post #{$post_id} not found."];
        }

        // ── Build article context ───────────────────────────────────────────
        $title   = get_the_title($post_id);
        $content = strip_shortcodes($post->post_content);
        $content = wp_strip_all_tags($content, true);
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);
        $content = mb_substr($content, 0, 3000);

        $categories    = wp_get_post_categories($post_id, ['fields' => 'names']);
        $category_str  = implode(', ', array_slice($categories, 0, 3));

        // ── System prompt (from settings or default) ────────────────────────
        $system_prompt = $settings['prompt_instagram'] ?? $this->default_instagram_prompt();
        $system_prompt = str_replace('{language}',     $language,     $system_prompt);
        $system_prompt = str_replace('{site_name}',    $site_name,    $system_prompt);
        $system_prompt = str_replace('{brand_colors}', $brand_colors ?: 'professional dark blue and white', $system_prompt);

        // ── User message ─────────────────────────────────────────────────────
        $user_message  = "ARTICLE TITLE: {$title}\n\n";
        if ($category_str) {
            $user_message .= "CATEGORIES: {$category_str}\n\n";
        }
        $user_message .= "ARTICLE CONTENT:\n{$content}";

        // ── API Payload ──────────────────────────────────────────────────────
        // Gemini 2.5 Flash Image is multimodal: it returns BOTH text (caption)
        // AND an image in a single response. modalities must be ["image","text"].
        $payload = [
            'model'    => $image_model,
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user',   'content' => $user_message],
            ],
            'modalities'   => ['image', 'text'],
            'image_config' => [
                'aspect_ratio' => '4:5',   // Best for Instagram feed (portrait)
            ],
            'max_tokens'   => 800,
            'temperature'  => 0.8,         // Slightly higher for creative image generation
        ];

        $args = [
            'timeout' => 120,              // Image generation takes longer than text
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => $settings['openrouter_site'] ?? home_url(),
                'X-Title'       => $settings['openrouter_app'] ?? 'AI Social Share',
            ],
            'body' => wp_json_encode($payload),
        ];

        SimpleScheduler::log("Instagram: Calling {$image_model} for post #{$post_id}");

        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', $args);

        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => 'Network error: ' . $response->get_error_message()];
        }

        $status = (int)wp_remote_retrieve_response_code($response);
        $body   = wp_remote_retrieve_body($response);
        $json   = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            $msg = $json['error']['message'] ?? "HTTP {$status}: " . substr($body, 0, 200);
            return ['ok' => false, 'error' => "Image model error: {$msg}"];
        }

        // ── Extract caption ──────────────────────────────────────────────────
        $caption = trim($json['choices'][0]['message']['content'] ?? '');

        // ── Extract image ────────────────────────────────────────────────────
        $image_base64 = '';
        $images       = $json['choices'][0]['message']['images'] ?? [];
        foreach ($images as $img) {
            $url = $img['image_url']['url'] ?? '';
            if ($url !== '') {
                $image_base64 = $url;
                break;
            }
        }

        if ($image_base64 === '') {
            // Log partial response for debugging
            $preview = is_array($json) ? substr(wp_json_encode($json), 0, 500) : substr($body, 0, 500);
            SimpleScheduler::log("Instagram: No image in response. Preview: {$preview}", 'error');
            return ['ok' => false, 'error' => 'Image model returned no image. Check model availability on OpenRouter. Response: ' . $preview];
        }

        // If Gemini only returned image (no caption text), fall back to text model
        if ($caption === '') {
            SimpleScheduler::log("Instagram: No caption returned, generating with text model");
            $caption = $this->generate_caption_fallback($post_id, $settings, $language);
        }

        SimpleScheduler::log("Instagram: Image received (" . strlen($image_base64) . " chars base64), saving to media library");

        // ── Save image to WP Media Library ───────────────────────────────────
        $media = $this->save_image_to_media($image_base64, $post_id, $title);
        if (!$media['ok']) {
            return $media;
        }

        SimpleScheduler::log("Instagram: Image saved - Attachment #{$media['attachment_id']}, URL: {$media['url']}");

        return [
            'ok'            => true,
            'caption'       => $caption,
            'image_url'     => $media['url'],
            'attachment_id' => $media['attachment_id'],
        ];
    }

    // ── Private: Save image to Media Library ──────────────────────────────────

    /**
     * Decode base64 data URL and save as a WordPress Media attachment.
     * Returns public https:// URL needed for Instagram API.
     */
    private function save_image_to_media(string $data_url, int $post_id, string $post_title) : array {
        // Parse data URL:  data:image/png;base64,XXXXX
        if (strpos($data_url, 'data:') === 0) {
            $comma = strpos($data_url, ',');
            if ($comma === false) {
                return ['ok' => false, 'error' => 'Malformed base64 data URL.'];
            }
            $meta_part = substr($data_url, 5, $comma - 5); // "image/png;base64"
            $b64       = substr($data_url, $comma + 1);

            // Determine file extension
            $ext = 'png';
            if (strpos($meta_part, 'jpeg') !== false || strpos($meta_part, 'jpg') !== false) {
                $ext = 'jpg';
            } elseif (strpos($meta_part, 'webp') !== false) {
                $ext = 'webp';
            }
        } else {
            // Plain base64 without data: prefix
            $b64 = $data_url;
            $ext = 'png';
        }

        $binary = base64_decode($b64, true);
        if ($binary === false || strlen($binary) < 256) {
            return ['ok' => false, 'error' => 'Failed to decode image data (too small or invalid base64).'];
        }

        // Generate unique filename
        $filename = 'aiss-ig-' . $post_id . '-' . time() . '.' . $ext;

        // Upload to WP uploads directory
        $upload = wp_upload_bits($filename, null, $binary);
        if (!empty($upload['error'])) {
            return ['ok' => false, 'error' => 'WP upload failed: ' . $upload['error']];
        }

        // Create Media Library attachment record
        $filetype = wp_check_filetype($filename, null);
        $att_data = [
            'post_mime_type' => $filetype['type'] ?: 'image/png',
            'post_title'     => sanitize_title($post_title) . '-instagram-' . $post_id,
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attachment_id = wp_insert_attachment($att_data, $upload['file']);
        if (is_wp_error($attachment_id)) {
            return ['ok' => false, 'error' => 'wp_insert_attachment failed: ' . $attachment_id->get_error_message()];
        }

        // Generate image metadata and thumbnails
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $metadata);

        // Tag attachment so we can clean it up later if needed
        update_post_meta($attachment_id, '_aiss_generated',   1);
        update_post_meta($attachment_id, '_aiss_source_post', $post_id);

        return [
            'ok'            => true,
            'attachment_id' => (int)$attachment_id,
            'url'           => (string)$upload['url'],
            'file'          => (string)$upload['file'],
        ];
    }

    // ── Private: Caption Fallback ─────────────────────────────────────────────

    /**
     * If Gemini returns no caption text, generate one separately using the text model.
     */
    private function generate_caption_fallback(int $post_id, array $settings, string $language) : string {
        $prompt = "Write an engaging Instagram caption in {$language}.\n"
                . "- 2-3 short paragraphs\n"
                . "- End with 5 relevant hashtags\n"
                . "- Professional news journalism tone\n"
                . "- Do NOT include any URLs\n"
                . "Return ONLY the caption text, nothing else.";

        $title   = get_the_title($post_id);
        $payload = [
            'model'      => $settings['openrouter_model'],
            'messages'   => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user',   'content' => "Article title: {$title}"],
            ],
            'max_tokens' => 400,
        ];

        $resp = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $settings['openrouter_api_key'],
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($resp)) return '';

        $json = json_decode(wp_remote_retrieve_body($resp), true);
        return trim($json['choices'][0]['message']['content'] ?? '');
    }

    // ── Default Prompt ────────────────────────────────────────────────────────

    public static function default_instagram_prompt() : string {
        return <<<'PROMPT'
You are a professional social media designer and journalist for {site_name}.

Your job: given a news article, produce TWO things in a single response:

1. CAPTION (text): An engaging Instagram caption in {language}.
   - Start with a strong headline statement (NOT a question)
   - 2-3 short, punchy paragraphs
   - Professional news journalism tone
   - End with 5 relevant hashtags
   - Do NOT include any URLs
   - Keep it under 300 words

2. IMAGE (generated): A high-quality, photorealistic editorial image that:
   - Visually represents the KEY theme of the article
   - Looks like a professional news magazine cover photo
   - Uses cinematic lighting, sharp focus, rich colors
   - Brand colors: {brand_colors}
   - Subtle watermark text "{site_name}" in the bottom-right corner
   - NO text overlays, headlines, or captions ON the image itself
   - Aspect ratio: 4:5 (portrait, perfect for Instagram feed)
   - Style: photojournalism quality, NOT cartoon or illustration

Return the caption text first, then generate the image.
PROMPT;
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function get_language_name(string $code) : string {
        $languages = [
            'en' => 'English',   'el' => 'Greek',    'es' => 'Spanish',
            'fr' => 'French',    'de' => 'German',   'it' => 'Italian',
            'pt' => 'Portuguese','ru' => 'Russian',  'ar' => 'Arabic',
            'zh' => 'Chinese',   'ja' => 'Japanese', 'ko' => 'Korean',
            'tr' => 'Turkish',   'nl' => 'Dutch',    'pl' => 'Polish',
        ];
        return $languages[$code] ?? 'English';
    }
}
