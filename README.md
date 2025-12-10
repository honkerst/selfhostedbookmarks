# SelfHostedBookmarks

A simple bookmarking service in memory of del.icio.us, built with vanilla PHP and JavaScript.

## Features

- **Dashboard**: View all your bookmarks with search and filtering
- **Tag System**: Organize bookmarks with tags, with tag cloud sidebar
- **Bookmarklet**: Quick bookmark addition from any webpage
- **Private/Public**: Mark bookmarks as private or public
- **Simple Auth**: Password-based authentication for single-user setup
- **SQLite Database**: No database server required
- **Import**: Import bookmarks from Netscape HTML or Pinboard JSON files
- **WordPress Integration**: Auto-post tagged bookmarks to WordPress (optional)
- **Manual Publishing**: One-click publish buttons for individual bookmarks

## Requirements

- PHP 7.4 or higher
- SQLite extension (usually enabled by default)
- Web server (Apache/Nginx) or PHP built-in server

## Installation

1. Clone or download this repository

2. Set up your password hash:
   
   **Option A: Web-based setup (Easiest - Recommended)**
   
   Start your web server, then visit:
   ```
   http://localhost:8000/setup-password-web.php
   ```
   Enter your password and copy the generated hash. **Important: Delete this file after use for security!**
   
   **Option B: Command line (if PHP is installed)**
   
   Generate a password hash using PHP:
   ```bash
   php -r "echo password_hash('your_password_here', PASSWORD_DEFAULT);"
   ```
   
   On macOS, PHP might be in a different location. Try:
   ```bash
   /usr/bin/php -r "echo password_hash('your_password_here', PASSWORD_DEFAULT);"
   # or if installed via Homebrew:
   /opt/homebrew/bin/php -r "echo password_hash('your_password_here', PASSWORD_DEFAULT);"
   ```
   
   **After generating the hash:**
   
   Update `includes/config.php` and set the `PASSWORD_HASH` constant, or set it as an environment variable:
   ```bash
   export SHB_PASSWORD_HASH='your_hash_here'
   ```

3. Create the data directory:
   ```bash
   mkdir -p data
   chmod 755 data
   ```

4. The database will be created automatically on first run.

## Setup Bookmarklet

1. Open `bookmarklet.js` and replace `YOUR_DOMAIN_HERE` with your actual domain
2. Copy the entire `javascript:...` code
3. Create a new bookmark in your browser
4. Set the bookmark URL to the copied code
5. Name it something like "Add to SHB"

## Usage

### Accessing the Application

1. Start your web server:
   ```bash
   php -S localhost:8000
   ```
   Or use Apache/Nginx with proper configuration.

2. Navigate to `http://localhost:8000` (or your configured domain)

3. Log in with your password

### Dashboard

- **View Bookmarks**: All bookmarks are displayed on the main page
- **Search**: Use the search box to find bookmarks by title, URL, or description
- **Filter by Tag**: Click any tag in the sidebar to filter bookmarks
- **Pagination**: Navigate through pages of bookmarks (configurable in Settings)
- **Publish to WordPress**: If WordPress is configured, each bookmark has a 📤 button to manually publish to WordPress

### Adding Bookmarks

#### Via Bookmarklet (Recommended)

1. Visit any webpage
2. Optionally select text on the page
3. Click your bookmarklet bookmark
4. A popup will open with pre-filled URL and title
5. Add description, tags, and mark as private if needed
6. Click "Save Bookmark"

#### Via Import

1. Go to Settings → Import Bookmarks
2. Upload a Netscape-style HTML file (exported from Chrome, Firefox, Safari, etc.) or a Pinboard JSON file
3. Optionally add additional tags to all imported bookmarks
4. Review and confirm the import

#### Via API (Manual)

You can also add bookmarks programmatically using the API endpoints.

## API Endpoints

Most API endpoints require authentication via session (except public read endpoints).

### Bookmarks
- `GET /api/bookmarks.php` - List bookmarks (supports `?tag=`, `?search=`, `?page=`, `?private=`) - **Public**
- `POST /api/bookmarks.php` - Create bookmark - **Requires Auth**
- `PUT /api/bookmarks.php` - Update bookmark - **Requires Auth**
- `DELETE /api/bookmarks.php?id=X` - Delete bookmark - **Requires Auth**

### Tags
- `GET /api/tags.php` - Get all tags (supports `?q=` for autocomplete) - **Public**
- `DELETE /api/tags.php` - Delete tag - **Requires Auth**

### Bookmarklet
- `GET /api/bookmarklet.php?url=...` - Get existing bookmark by URL - **Requires Auth**
- `POST /api/bookmarklet.php` - Create bookmark via bookmarklet (CORS enabled) - **Requires Auth**

### Settings
- `GET /api/settings.php` - Get all settings - **Public**
- `PUT /api/settings.php` - Update settings - **Requires Auth**

### Import
- `GET /api/import.php` - Get import history - **Requires Auth**
- `POST /api/import.php` - Import bookmarks from file - **Requires Auth**
- `DELETE /api/import.php` - Undo an import - **Requires Auth**

### WordPress Integration (Optional)
- `POST /api/wp-test-connection.php` - Test WordPress connection - **Requires Auth**
- `GET /api/wp-publish.php?bookmark_id=X` - Check if bookmark exists in WordPress - **Requires Auth**
- `POST /api/wp-publish.php` - Publish bookmark to WordPress - **Requires Auth**

### Authentication
- `GET /api/auth.php?action=status` - Check authentication status - **Public**
- `POST /api/auth.php` - Login or logout - **Public for login, Auth for logout**

## File Structure

```
selfhostedbookmarks/
├── api/                    # API endpoints
│   ├── auth.php
│   ├── bookmarks.php
│   ├── bookmarklet.php
│   ├── tags.php
│   ├── settings.php
│   ├── import.php
│   ├── wp-test-connection.php
│   └── wp-publish.php
├── assets/
│   ├── css/
│   │   └── style.css
│   └── js/
│       ├── api.js
│       ├── bookmarklet.js
│       └── dashboard.js
├── includes/
│   ├── auth.php
│   ├── config.php
│   ├── config.php.example
│   └── functions.php
├── scripts/
│   └── shb_thc_to_wp.php   # WordPress sync script
├── sql/
│   ├── schema.sql
│   └── migrations/
│       └── 001_add_imports_table.sql
├── data/                   # SQLite database (gitignored)
├── bookmarklet-popup.php   # Bookmarklet popup page
├── index.php               # Dashboard
├── login.php               # Login page
├── settings.php            # Settings page
├── tags.php                # Tags page
├── import.php              # Import page
├── bookmarklet.js          # Bookmarklet code
├── README.md
├── TECHNICAL_DOCUMENTATION.md
└── CLOUDFLARE.md
```

## Security Notes

- Change the default password hash in production
- Use HTTPS in production (required for clipboard access in bookmarklet)
- Consider adding rate limiting for API endpoints
- The bookmarklet requires you to be logged in (uses session cookies)

## Settings

Access the Settings page (requires login) to configure:

### Display Options
- **Tags Alphabetical**: Sort tags alphabetically vs. order added
- **Show URL**: Display URL under bookmark title
- **Show Date/Time**: Show full timestamp vs. date only
- **Bookmarks per Page**: Pagination size (1-1000 or unlimited)
- **Tag Threshold**: Minimum tag count to show in sidebar

### Import Bookmarks
- Import from Netscape HTML files (Chrome, Firefox, Safari exports)
- Import from Pinboard JSON files
- Add additional tags to all imported bookmarks
- Undo imports if needed

### WordPress Auto-Post
- Configure WordPress connection
- Set watch tag for auto-posting
- Configure WordPress tags and categories
- Test connection before use

## Customization

- Edit `includes/config.php` for site name and configuration
- Modify `assets/css/style.css` for styling
- Update database schema in `sql/schema.sql` if needed

## Troubleshooting

**Database errors**: Ensure the `data/` directory is writable by the web server.

**Bookmarklet not working**: 
- Make sure you've updated the domain in `bookmarklet.js`
- Ensure you're logged in (bookmarklet uses session cookies)
- For clipboard access, the site must be served over HTTPS

**Session issues**: Check PHP session configuration and ensure sessions directory is writable.

## WordPress Integration (Optional)

SelfHostedBookmarks can automatically post bookmarks to your WordPress site. This feature supports both automated (scheduled) and manual publishing.

### Setup

1. Go to **Settings → WordPress Auto-Post**
2. Fill in your WordPress connection details:
   - **SHB Base URL**: Your SelfHostedBookmarks installation URL
   - **WordPress Base URL**: Your WordPress site root URL (where `/wp-json/` lives)
   - **WordPress Username**: Your WordPress admin username
   - **WordPress Application Password**: Create this in WordPress under your user profile → Application Passwords
   - **SHB Tag to Watch**: The tag that triggers auto-posting (e.g., `thc`)
   - **WordPress Tags**: Comma-separated tags to add to posts (e.g., `interesting,thc,shb`)
   - **WordPress Categories**: Comma-separated categories (e.g., `Interesting stuff`)
3. Click **Test Connection** to verify your credentials
4. Save settings

### Manual Publishing

Once WordPress is configured and tested:
- Each bookmark on the dashboard will show a 📤 (publish) button
- Hover over the button to check if the bookmark already exists in WordPress
- Click to publish the bookmark immediately
- The button will be greyed out if the bookmark is already published

### Automated Publishing

The sync script (`scripts/shb_thc_to_wp.php`) automatically posts new bookmarks with your watch tag to WordPress.

**Features:**
- Processes multiple bookmarks per run (catches up on backlog)
- Skips bookmarks that already exist in WordPress (checks by URL)
- Preserves original bookmark creation date in WordPress posts
- Uses settings from the database (no environment variables needed)

**Scheduling Options:**

1. **Cron** (if available):
   ```
   */2 * * * * /usr/bin/php /path/to/scripts/shb_thc_to_wp.php >>$HOME/shb_sync.log 2>&1
   ```

2. **Control Panel Scheduled Tasks** (Plesk, cPanel, etc.):
   - Use the terminal command shown in Settings
   - Schedule it to run every 2-5 minutes

The script reads all settings from the database, so you don't need to set environment variables unless you want to override specific settings.

## License

Free to use and modify for personal use.

## Demo

See it in action: https://bookmarks.thoughton.co.uk

## Credits

Tim Houghton - https://thoughton.co.uk

