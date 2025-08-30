# AI Customer Support Plugin for WordPress

AI Customer Support is a modern, AI-powered live chat plugin for WordPress. It features real-time chat, agent escalation, chat archiving, and secure integration with Firebase and Google Gemini AI.

## Features
- Live chat widget for website visitors
- AI-powered responses using Google Gemini API
- Human agent takeover/escalation
- Agent profile photo and name in chat
- Typing indicators for both agent and user
- Chat archive with search and pagination
- Admin dashboard for managing chats
- Secure message storage in WordPress database
- Firebase Realtime Database integration
- Customizable via WordPress settings

## Installation
1. **Clone or download this repository** into your `wp-content/plugins` directory.
2. **Install Composer dependencies:**
   ```
   composer install
   ```
3. **Create a Firebase project** and download the service account JSON. Place it as `firebase-service-account.json` in the plugin root.
4. **Configure plugin settings** in WordPress Admin > Settings > AI Customer Support:
   - Firebase API Key
   - Firebase Project ID
   - Google Gemini API Key
5. **Activate the plugin** from the WordPress Plugins page.

## Usage
- The chat widget appears on every page for visitors.
- Admins can manage chats and view archives from the WordPress dashboard.
- AI and agent messages are stored in both Firebase and the WordPress database.

## Firebase Security
- The plugin uses Firebase custom tokens for admin authentication.
- For best security, set your Firebase Realtime Database rules:
  ```json
  {
    "rules": {
      "admin_status": {
        "online": {
          ".read": true,
          ".write": "auth != null && auth.token.admin === true"
        }
      },
      // ...other rules...
    }
  }
  ```
- Only authenticated admins can change online status or access restricted data.

## Customization
- Edit CSS in `public/css/aics-public.css` and `admin/css/aics-admin.css` for UI changes.
- Modify chat widget HTML in `public/partials/chat-widget-inner.php`.
- Extend backend logic in `includes/class-aics-core.php` and `includes/class-aics-db.php`.

## Troubleshooting
- If you see errors about missing Firebase custom token, ensure `firebase-service-account.json` is present and correct.
- Check your Firebase rules if unauthorized users can change admin status.
- Use browser console for JS errors and WordPress debug log for PHP errors.

## License
This plugin is provided as-is. See LICENSE file for details.

---
For support or feature requests, open an issue or contact the author.
