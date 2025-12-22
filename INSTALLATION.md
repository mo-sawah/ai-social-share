# AI Social Share - Installation Guide

## 🚀 Quick Install

### Option 1: Upload via WordPress Admin (Recommended)
1. Download `ai-social-share.zip` 
2. Go to WordPress Admin → Plugins → Add New → Upload Plugin
3. Choose the zip file and click "Install Now"
4. Click "Activate Plugin"
5. Go to Settings → AI Social Share to configure

### Option 2: Manual FTP Upload
1. Extract `ai-social-share.zip`
2. Upload the `ai-social-share` folder to `/wp-content/plugins/`
3. Go to WordPress Admin → Plugins
4. Find "AI Social Share" and click "Activate"
5. Go to Settings → AI Social Share to configure

## ⚙️ Configuration Steps

### Step 1: OpenRouter API Setup (5 minutes)

1. Go to https://openrouter.ai/keys
2. Create a free account if you don't have one
3. Generate a new API key
4. In WordPress, go to AI Social Share → **General** tab
5. Paste your API key in the "API Key" field
6. Select your preferred AI model (GPT-4o Mini is recommended for best value)
7. Click "Save Configuration"

### Step 2: Facebook App Setup (10 minutes)

1. Go to https://developers.facebook.com/apps
2. Click "Create App"
3. Choose "Business" as app type
4. Fill in app details:
   - App name: "Your Site Social Share" (or any name)
   - App contact email: your email
5. Once created, note your **App ID** and **App Secret** (Settings → Basic)
6. Add a use case: Click "Add use case" → Choose "Manage Pages"
7. Configure Facebook Login:
   - Go to your use case settings
   - Find "Valid OAuth Redirect URIs"
   - Add the URI shown in AI Social Share → Facebook tab
8. In "Basic" settings:
   - Add your domain to "App Domains" (e.g., `yourdomain.com`)
   - Add Platform → Website → Enter your site URL
9. Make app Live (switch from Development mode in top navigation)

### Step 3: Connect to Facebook (2 minutes)

1. In WordPress, go to AI Social Share → **Facebook** tab
2. Enter your Facebook App ID
3. Enter your Facebook App Secret
4. Select Graph API Version (v24.0 is current)
5. Click "Save Facebook App Settings"
6. Copy the "OAuth Redirect URI" and add it to your Facebook App (if you haven't already)
7. Click "🔗 Connect to Facebook"
8. Authorize the app
9. Select which Facebook Page to use
10. Click "Use This Page"
11. You should see "✓ Facebook Page connected successfully!"

### Step 4: Configure Scheduling (1 minute)

1. Go to AI Social Share → **Scheduler** tab
2. Set "Check Every (minutes)": Recommended 30 minutes
3. Set "Posts Per Check": Recommended 2-5 posts
4. Choose Filter Mode:
   - "Share all published posts" - shares every post
   - "Specific categories" - only shares posts from certain categories
   - "Specific tags" - only shares posts with certain tags
5. If using filters, enter category/tag slugs (comma-separated)
6. Click "Save Scheduler Settings"

### Step 5: Verify Status (1 minute)

1. Go to AI Social Share → **Status** tab
2. Check that all indicators are green:
   - ✓ Facebook Status: Connected
   - ✓ OpenRouter API: Configured
3. Verify "Next Scheduled Run" shows a future time
4. If everything is green: **You're all set!** 🎉

## 🧪 Testing

### Test Manual Sharing
1. Create or edit a post
2. Look for "AI Social Share" metabox in the sidebar (or below editor)
3. Click "Generate" to preview AI-generated content
4. Edit the text if desired
5. Click "Share Now"
6. Check your Facebook Page to see the post

### Test Automatic Sharing
1. Publish a new post
2. Wait for the next scheduled run (check Status tab for time)
3. After the scheduled time, check your Facebook Page
4. The post should appear automatically

## 🔧 Troubleshooting

### "URL blocked" Error
- **Fixed in v1.0.1** - Update to latest version
- Ensure redirect URI in Facebook App matches exactly what's shown in plugin

### "Domain not found" Error
- Add your domain to "App Domains" in Facebook App Basic Settings
- Make sure App is in "Live" mode, not "Development"

### AI Not Generating Content
- Verify OpenRouter API key is valid
- Check you have credits in your OpenRouter account
- Try a different AI model

### Posts Not Sharing Automatically
- Check Status tab for next scheduled run time
- Verify Facebook connection is active
- Ensure WordPress cron is working: `wp cron event list`
- Check post matches your filter criteria

### Facebook Token Expired
- Simply click "Disconnect Facebook" then "Connect to Facebook" again
- Select your page again to refresh the token

## 📋 Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher  
- OpenRouter API account (free tier available)
- Facebook Page and App
- WordPress cron enabled

## 🆘 Support

If you encounter issues:

1. Check the **Status** tab for configuration problems
2. Review Facebook App settings match the plugin requirements
3. Verify OpenRouter API key is valid and has credits
4. Check WordPress debug log for errors
5. Try disconnecting and reconnecting Facebook

## 🔐 Security Notes

- All Facebook tokens are encrypted before storage
- API keys are stored securely in WordPress database
- Use strong App Secrets for Facebook
- Keep WordPress and plugins updated
- Use HTTPS on your website (required by Facebook)

## 🎨 Features Overview

### Automatic Features
- Scheduled post checking
- AI content generation
- Facebook posting
- Error handling and retry

### Manual Controls
- Generate preview before sharing
- Edit AI-generated content
- Share specific posts on-demand
- Disconnect/reconnect anytime

### Customization
- Custom AI prompts
- Schedule frequency
- Post filters (category/tag)
- Posts per batch

---

**Need Help?** Check the Status tab for real-time configuration status and tips!
