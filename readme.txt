=== AI Social Share ===
Contributors: sawahsolutions
Tags: facebook, social media, ai, openrouter, automation
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically generate and share WordPress posts to Facebook using AI-powered content generation.

== Description ==

AI Social Share automatically generates engaging social media content for your WordPress posts and shares them to your Facebook Page using AI technology.

**Key Features:**

* 🤖 AI-powered post generation using OpenRouter (supports GPT-4, Claude, Gemini)
* 📘 Automatic Facebook Page posting
* ⏰ Customizable scheduling (check every X minutes)
* 🎯 Content filters (by category or tag)
* ✍️ Manual sharing from post editor
* 🎨 Modern, beautiful admin interface
* 🔒 Secure token encryption

**How It Works:**

1. Install and activate the plugin
2. Configure your OpenRouter API key (for AI generation)
3. Connect your Facebook Page
4. Set your posting schedule
5. Posts are automatically shared to Facebook with AI-generated content!

**Perfect For:**

* News websites
* Blogs
* Content creators
* Publishers
* Anyone who wants to automate social media posting

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/ai-social-share/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings → AI Social Share
4. Configure OpenRouter API settings
5. Connect your Facebook Page
6. Configure your posting schedule

== Frequently Asked Questions ==

= Do I need a Facebook App? =

Yes, you need to create a Facebook App and get your App ID and Secret from https://developers.facebook.com/apps

= What AI models are supported? =

The plugin uses OpenRouter which provides access to GPT-4, Claude, Gemini, and other leading AI models.

= Is my Facebook token secure? =

Yes, all tokens are encrypted before being stored in the WordPress database.

= Can I customize the AI-generated content? =

Yes! You can customize the prompt template in the General settings to control the style and format of generated content.

== Screenshots ==

1. Modern admin interface with gradient design
2. Facebook connection settings
3. Scheduler and content filters
4. Status dashboard

== Changelog ==

= 1.0.1 =
* Fixed OAuth URL encoding bug that caused "URL blocked" errors
* Fixed disappearing page selector after connection
* Modernized admin interface with beautiful UI
* Added comprehensive status dashboard
* Improved error handling and user feedback
* Better visual indicators for connection status

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.1 =
Critical fix for Facebook OAuth connection issues. Highly recommended update with modernized UI.
