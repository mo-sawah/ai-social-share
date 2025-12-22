=== AI Social Share ===
Contributors: custom
Tags: social, facebook, ai, openrouter, autopost
Requires at least: 6.0
Tested up to: 6.6
Stable tag: 1.0.0
License: GPLv2 or later

Auto-generates Facebook posts using OpenRouter AI and shares new WordPress posts to a connected Facebook Page.

== Quick Setup ==
1) Install + activate plugin.
2) Settings -> AI Social Share:
   - Add OpenRouter API key + model
   - Add Facebook App ID/Secret
   - Click Connect Facebook and select the Page.
3) Configure scheduler minutes and filters.
4) Use the post editor metabox to Generate/Share instantly.

== Notes ==
- This MVP uses WP-Cron. For production, use a server cron to call wp-cron.php.
- Facebook posting uses the Page Feed endpoint.
