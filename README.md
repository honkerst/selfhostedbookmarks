# SelfHostedBookmarks

A simple bookmarking service in memory of del.icio.us, built with vanilla PHP and JavaScript.

## Features

- **Dashboard**: View all your bookmarks with search and filtering
- **Tag System**: Organize bookmarks with tags, with tag cloud sidebar
- **Bookmarklet**: Quick bookmark addition from any webpage
- **Private/Public**: Mark bookmarks as private or public
- **Simple Auth**: Password-based authentication for single-user setup
- **SQLite Database**: No database server required

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
- **Pagination**: Navigate through pages of bookmarks

### Adding Bookmarks

#### Via Bookmarklet (Recommended)

1. Visit any webpage
2. Optionally select text on the page
3. Click your bookmarklet bookmark
4. A popup will open with pre-filled URL and title
5. Add description, tags, and mark as private if needed
6. Click "Save Bookmark"

#### Via API (Manual)

You can also add bookmarks programmatically using the API endpoints.

## API Endpoints

All API endpoints require authentication via session.

- `GET /api/bookmarks.php` - List bookmarks (supports `?tag=`, `?search=`, `?page=`)
- `POST /api/bookmarks.php` - Create bookmark
- `PUT /api/bookmarks.php` - Update bookmark
- `DELETE /api/bookmarks.php?id=X` - Delete bookmark
- `GET /api/tags.php` - Get all tags (supports `?q=` for autocomplete)
- `POST /api/bookmarklet.php` - Create bookmark via bookmarklet (CORS enabled)

## File Structure

```
del.icio.us-clone/
├── api/                    # API endpoints
│   ├── auth.php
│   ├── bookmarks.php
│   ├── bookmarklet.php
│   └── tags.php
├── assets/
│   ├── css/
│   │   └── style.css
│   └── js/
│       ├── api.js
│       ├── bookmarklet.js
│       └── dashboard.js
├── data/                   # SQLite database (gitignored)
├── includes/
│   ├── auth.php
│   ├── config.php
│   └── functions.php
├── sql/
│   └── schema.sql
├── bookmarklet-popup.php   # Bookmarklet popup page
├── index.php               # Dashboard
├── login.php               # Login page
└── README.md
```

## Security Notes

- Change the default password hash in production
- Use HTTPS in production (required for clipboard access in bookmarklet)
- Consider adding rate limiting for API endpoints
- The bookmarklet requires you to be logged in (uses session cookies)

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

## WordPress auto-post from tagged bookmarks (optional)

You can mirror a tag (e.g., `thc`) from SelfHostedBookmarks to a WordPress site using the helper script in `scripts/shb_thc_to_wp.php`. It polls SHB for the newest bookmark with that tag and publishes a post via the WordPress REST API.

1. Create a WordPress Application Password for the account that should publish posts.
2. Set environment variables:
   - `WP_APP_PASSWORD` (required)
   - `SHB_BASE_URL` (default: `https://bookmarks.thoughton.co.uk`)
   - `SHB_TAG` (default: `thc`)
   - `WP_BASE_URL` (default: `https://thoughton.co.uk/_wp`)
   - `WP_USER` (default: `selfhostedbookmarks`)
   - `SHB_WP_STATE_FILE` (default: `data/wp_sync_last_id.txt`)
3. Run the script manually to verify:
   ```bash
   WP_APP_PASSWORD=your_app_password php scripts/shb_thc_to_wp.php
   ```
4. Add a cron entry to run every few minutes:
   ```
   */2 * * * * WP_APP_PASSWORD=your_app_password php /path/to/scripts/shb_thc_to_wp.php >>$HOME/shb_thc.log 2>&1
   ```

The script skips posting if there is no new bookmark since the last run. It stores the last posted bookmark ID in `data/wp_sync_last_id.txt` (configurable via `SHB_WP_STATE_FILE`).

## License

Free to use and modify for personal use.

## Credits

Created by Tim Houghton - https://thoughton.co.uk

