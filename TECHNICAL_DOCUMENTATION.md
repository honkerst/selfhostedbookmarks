# Technical Documentation: Pinboard Clone

This document provides a comprehensive explanation of the pinboard.in clone application codebase, including architecture, components, data flow, and implementation details.

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Database Schema](#database-schema)
4. [Authentication System](#authentication-system)
5. [Configuration](#configuration)
6. [API Endpoints](#api-endpoints)
7. [Frontend Components](#frontend-components)
8. [Key Features](#key-features)
9. [Data Flow Examples](#data-flow-examples)
10. [Security Considerations](#security-considerations)

## Overview

This is a self-hosted bookmarking service built with **vanilla PHP** (backend) and **vanilla JavaScript** (frontend). It allows users to save, organize, and search bookmarks with tags, similar to pinboard.in or del.icio.us.

**Key Technologies:**
- Backend: PHP 7.4+ with SQLite database
- Frontend: Vanilla JavaScript (no frameworks)
- Database: SQLite (single file database, no server required)
- Authentication: Single-user, password-based session authentication

## Architecture

### File Structure

```
pinboard-clone/
├── api/                      # API endpoints
│   ├── auth.php             # Authentication API
│   ├── bookmarks.php        # Bookmarks CRUD API
│   ├── bookmarklet.php      # Bookmarklet-specific API
│   ├── tags.php             # Tags API (autocomplete, listing)
│   └── settings.php         # User settings API
├── assets/
│   ├── css/
│   │   └── style.css        # Main stylesheet
│   └── js/
│       ├── api.js           # API helper functions
│       ├── dashboard.js     # Dashboard functionality
│       └── bookmarklet.js   # Bookmarklet popup functionality
├── includes/
│   ├── config.php           # Database connection, config
│   ├── auth.php             # Authentication functions
│   └── functions.php        # Utility functions
├── sql/
│   └── schema.sql           # Database schema
├── data/                    # SQLite database file location (created at runtime)
├── index.php                # Main dashboard page
├── login.php                # Login page
├── settings.php             # Settings page
├── bookmarklet-popup.php    # Bookmarklet popup window
└── bookmarklet.js           # Bookmarklet code (for browser bookmark bar)
```

### Request Flow

1. **Public Dashboard**: Anyone can view public bookmarks (`index.php`)
2. **Authenticated Actions**: Login required for creating/editing/deleting
3. **API Requests**: All API calls go through `/api/*.php` endpoints
4. **Session-Based Auth**: PHP sessions with secure cookie configuration

## Database Schema

### Tables

#### `bookmarks`
Stores all bookmark data.

```sql
CREATE TABLE bookmarks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    url TEXT NOT NULL,              -- Unique identifier for updates
    title TEXT,                     -- Can be NULL
    description TEXT,               -- Can be NULL
    is_private INTEGER DEFAULT 0,   -- 0 = public, 1 = private
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME
);
```

**Key Points:**
- `url` is used as the unique identifier (bookmarks with same URL are updated, not duplicated)
- `is_private` determines visibility for non-authenticated users
- Indexed on `is_private` and `created_at` for performance

#### `tags`
Stores unique tag names.

```sql
CREATE TABLE tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE NOT NULL      -- Tag name (lowercase, unique)
);
```

#### `bookmark_tags`
Many-to-many relationship between bookmarks and tags.

```sql
CREATE TABLE bookmark_tags (
    bookmark_id INTEGER NOT NULL,
    tag_id INTEGER NOT NULL,
    PRIMARY KEY (bookmark_id, tag_id),
    FOREIGN KEY (bookmark_id) REFERENCES bookmarks(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);
```

**Key Points:**
- Uses CASCADE deletes (deleting bookmark removes its tags associations)
- Indexed on both foreign keys for query performance

#### `settings`
Stores user preferences as key-value pairs.

```sql
CREATE TABLE settings (
    key TEXT PRIMARY KEY,           -- Setting name (e.g., 'tags_alphabetical')
    value TEXT NOT NULL,            -- '0' or '1' (boolean stored as text)
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

**Available Settings:**
- `tags_alphabetical` - Sort tags alphabetically (default: false)
- `show_url` - Display URL under bookmark title (default: true)
- `show_datetime` - Show exact date/time vs date only (default: false)

#### `login_attempts`
Stores login attempt history for rate limiting.

```sql
CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_address TEXT NOT NULL,
    attempt_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    success INTEGER DEFAULT 0
);
```

**Key Points:**
- Tracks failed login attempts per IP address
- Used for rate limiting (5 attempts per 15 minutes)
- Old records automatically cleaned up (older than 24 hours)
- Indexed on `ip_address` and `attempt_time` for performance

## Authentication System

### Single-User Setup

The application uses a **single-user authentication model** - only one user account exists.

**Implementation:**
- Password hash stored in `includes/config.php` or as environment variable `PINBOARD_PASSWORD_HASH`
- **No default password hash** - must be configured before first use
- Hash generated using PHP's `password_hash()` with bcrypt
- Session-based authentication with secure cookies
- **Rate limiting**: 5 failed attempts per 15 minutes per IP address
- **Session regeneration**: Session ID regenerated after successful login to prevent session fixation

### Session Configuration

Located in `includes/config.php`:

```php
// Secure session cookie configuration
ini_set('session.cookie_httponly', 1);        // Prevent XSS access
ini_set('session.cookie_secure', 1);          // HTTPS only (when available)
ini_set('session.cookie_samesite', 'Lax');    // CSRF protection
ini_set('session.cookie_lifetime', 31536000); // 1 year max lifetime
ini_set('session.gc_maxlifetime', 31536000);  // 1 year max lifetime
```

**Key Features:**
- Works behind Cloudflare proxy (checks `HTTP_X_FORWARDED_PROTO` header)
- Session cookie is HttpOnly (not accessible via JavaScript)
- Secure flag set automatically when HTTPS detected
- **Session timeout**: Maximum 1 year (31536000 seconds)
- Session age checked on each request - expired sessions automatically destroyed

### Authentication Functions

Located in `includes/auth.php`:

- `isAuthenticated()` - Checks if user has valid session
- `requireAuth()` - Redirects to login if not authenticated (for web pages) or returns 401 JSON (for API)
- `login($password)` - Validates password, checks rate limit, creates session, and regenerates session ID
- `logout()` - Destroys session

Located in `includes/functions.php`:

- `checkRateLimit($maxAttempts, $timeWindow)` - Checks if IP address has exceeded login attempt limit
- `recordLoginAttempt($success)` - Records login attempt in database for rate limiting
- `getClientIp()` - Gets client IP address (works behind Cloudflare proxy)

### Login Flow

1. User submits password on `login.php` or via API
2. **Rate limit checked**: If IP has exceeded 5 failed attempts in 15 minutes, login is rejected
3. Password verified against stored hash using `password_verify()`
4. **Login attempt recorded** in database (success or failure)
5. If valid:
   - **Session ID regenerated** to prevent session fixation
   - Session created with `$_SESSION['authenticated'] = true` and `$_SESSION['login_time'] = time()`
6. Redirected to dashboard or original destination
7. **Session age checked** on each request - sessions older than 1 year are automatically destroyed

## Configuration

### Site Configuration

Located in `includes/config.php`:

**Constants:**
- `SITE_NAME` - Site title (default: 'SelfHostedBookmarks')
- `SITE_SUBTITLE` - Subtitle under title (default: 'A selfhosted del.icio.us clone')
- `PASSWORD_HASH` - bcrypt hash of user password

**Design Pattern:**
- Default values are hardcoded in HTML (`index.php`)
- Can be overridden by defining constants in `config.php`
- Uses `defined()` checks to allow graceful fallback

### Database Connection

```php
$pdo = new PDO('sqlite:' . DB_PATH);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');  // Enable foreign key constraints
```

**Key Points:**
- Auto-creates `data/` directory if missing
- Auto-runs `sql/schema.sql` to create tables on first run
- Uses prepared statements throughout for SQL injection protection

## API Endpoints

### `/api/auth.php`

**Purpose:** Handle authentication status and logout

**Methods:**
- `GET ?action=status` - Returns `{authenticated: true/false}`
- `POST action=login` - Authenticate with password (rate limited: 5 attempts per 15 min)
- `POST action=logout&csrf_token=...` - End session (requires CSRF token)

**Authentication:** Public (for status check), requires auth (for logout)
**Rate Limiting:** Login endpoint limited to 5 failed attempts per IP per 15 minutes

**Usage:**
- Dashboard checks auth status on page load (bypasses caching issues)
- Login page uses this endpoint

### `/api/bookmarks.php`

**Purpose:** CRUD operations for bookmarks

**Methods:**

#### `GET`
- **Query Params:**
  - `page` - Page number (default: 1)
  - `tag` - Filter by tag name
  - `search` - Search in title, description, URL
  - `private` - Filter by privacy (0/1, only for authenticated users)
- **Returns:** `{bookmarks: [...], pagination: {...}}`
- **Authentication:** Public (filters private bookmarks for non-authenticated users)

**Key Features:**
- Pagination (20 per page)
- Full-text search across multiple fields
- Tag filtering with JOIN
- Private bookmark filtering based on auth status
- Tags returned as comma-separated string, converted to array in response

#### `POST`
- **Body:** `{url, title, description, tags: [...], is_private: 0/1}`
- **Returns:** Created bookmark with tags
- **Authentication:** Required

**Important Behavior:**
- **Checks if bookmark with same URL exists**
- **If exists:** Updates existing bookmark instead of creating duplicate
- **Empty fields overwrite:** If title/description is empty string, it sets to NULL (clears field)
- **URL validation:** URL must be valid http/https URL, max 2048 characters
- **Input validation:** Title max 500 chars, description max 5000 chars, tags max 100 chars each
- **CSRF protection:** Requires valid CSRF token in request body

#### `PUT`
- **Body:** `{id, url, title, description, tags: [...], is_private: 0/1, csrf_token: '...'}`
- **Returns:** Updated bookmark with tags
- **Authentication:** Required
- **CSRF Protection:** Required (token in request body)
- **Input Validation:** Same as POST (URL, length limits)

#### `DELETE ?id=X&csrf_token=...`
- **Returns:** Success message
- **Authentication:** Required
- **CSRF Protection:** Required (token in query string)
- **Cascade:** Deleting bookmark automatically removes tag associations

### `/api/bookmarklet.php`

**Purpose:** Specialized API for bookmarklet popup

**Methods:**

#### `GET ?url=...`
- **Purpose:** Check if bookmark exists and return it (for preloading form)
- **Returns:** `{bookmark: {...} or null}`
- **Authentication:** Required
- **Usage:** Popup checks for existing bookmark to preload title, description, tags

#### `POST`
- **Body:** `{url, title, description, tags: [...], is_private: 0/1, csrf_token: '...'}`
- **Returns:** Success message
- **Authentication:** Required
- **CSRF Protection:** Required (token in request body)
- **CORS:** Validates origin against allowlist (same domain, localhost, 127.0.0.1)

**Key Behavior:**
- Same URL-based update logic as `/api/bookmarks.php`
- Empty fields overwrite existing values (set to NULL)
- Tags always updated (even if empty array, removes all tags)
- **URL validation:** URL must be valid http/https URL, max 2048 characters
- **Input validation:** Title max 500 chars, description max 5000 chars, tags max 100 chars each

### `/api/tags.php`

**Purpose:** Tag autocomplete and listing

**Methods:**

#### `GET ?q=query`
- **Purpose:** Autocomplete tags matching query
- **Returns:** `{tags: [{name, count}, ...]}`
- **Authentication:** Public (filters tags from private bookmarks for non-auth users)

**Features:**
- Case-insensitive matching
- Returns top 10 matches
- Sorted by count (most used first), then alphabetically
- Filters out tags with count 0

#### `GET` (no query)
- **Purpose:** Get all tags with counts
- **Returns:** All tags sorted by count
- **Authentication:** Public (filters appropriately)

**Key Implementation:**
- Uses `HAVING COUNT(bt.bookmark_id) > 0` to exclude orphaned tags
- Filters private bookmarks for non-authenticated users

### `/api/settings.php`

**Purpose:** Get and update user settings

**Methods:**

#### `GET`
- **Returns:** `{settings: {tags_alphabetical: bool, show_url: bool, show_datetime: bool}}`
- **Authentication:** Public (settings apply to all users)

**Default Values:**
- `tags_alphabetical`: false
- `show_url`: true
- `show_datetime`: false

#### `PUT`
- **Body:** `{settings: {tags_alphabetical: bool, show_url: bool, show_datetime: bool}, csrf_token: '...'}`
- **Returns:** Updated settings
- **Authentication:** Required
- **CSRF Protection:** Required (token in request body)

**Implementation:**
- Stores as '0' or '1' in database
- Converts to boolean in API response
- Uses `INSERT ... ON CONFLICT DO UPDATE` for upsert

## Frontend Components

### Dashboard (`index.php`)

**Purpose:** Main page displaying all bookmarks

**Features:**
- Public view: Shows only public bookmarks
- Authenticated view: Shows all bookmarks (public + private)
- Search functionality
- Tag filtering (sidebar)
- Pagination
- Conditional UI based on authentication:
  - Login/Logout button
  - Edit/Delete icons (only when authenticated)
  - Bookmarklet sidebar (only when authenticated)

**Key JavaScript File:** `assets/js/dashboard.js`

**Initialization Flow:**
1. Verify authentication state from server (bypasses caching)
2. Load settings
3. Load tags for sidebar
4. Load bookmarks
5. Attach event listeners

**Authentication State Management:**
- Uses `verifyAuthState()` function that calls `/api/auth.php?action=status`
- Updates `window.IS_AUTHENTICATED` dynamically
- Renders UI conditionally based on this state
- Avoids embedding auth state in HTML (prevents Cloudflare caching issues)

### Bookmarklet Popup (`bookmarklet-popup.php`)

**Purpose:** Form to add/edit bookmarks from any webpage

**Features:**
- Pre-filled URL and title from webpage
- Clipboard reading for description
- Tag autocomplete
- Private/public toggle

**Key JavaScript File:** `assets/js/bookmarklet.js`

**Initialization Flow:**
1. Load tags for autocomplete
2. Check if bookmark already exists for this URL:
   - If exists: Preload title, description, and tags (overwrites clipboard)
   - If not: Try to read clipboard for description
3. Set up form submission handler

**Tag Autocomplete:**
- Triggers after 300ms delay while typing
- Extracts current word being typed (handles comma-separated tags)
- Filters tags starting with current word
- Shows top 5 matches
- Click to autocomplete

**Existing Bookmark Handling:**
- Calls `GET /api/bookmarklet.php?url=...` on page load
- If bookmark exists, preloads:
  - Title (always, even if empty)
  - Description (always, even if empty)
  - Tags (if they exist)
- Skips clipboard loading when existing bookmark found

### Settings Page (`settings.php`)

**Purpose:** Configure display preferences

**Features:**
- Three toggle options:
  - Tags alphabetical order
  - Show URL under bookmark
  - Show exact date/time
- Saves via POST to same page (updates database)
- Redirects to dashboard after save

**Settings Application:**
- Dashboard loads settings on page load
- Bookmarks rendered based on settings:
  - Tags sorted alphabetically if enabled
  - URL shown/hidden based on setting
  - Date format changes based on setting

### API Helper (`assets/js/api.js`)

**Purpose:** Centralized API request handling

**Functions:**
- `apiRequest(endpoint, options)` - Generic fetch wrapper
- `API.getBookmarks(params)` - Get bookmarks with filters
- `API.createBookmark(bookmark)` - Create bookmark
- `API.updateBookmark(bookmark)` - Update bookmark
- `API.deleteBookmark(id)` - Delete bookmark
- `API.getTags(query)` - Get tags (with optional query)
- `API.getBookmarkByUrl(url)` - Get existing bookmark by URL
- `API.createBookmarkViaBookmarklet(bookmark)` - Bookmarklet-specific create/update
- `API.getSettings()` - Get settings
- `API.updateSettings(settings)` - Update settings
- `API.logout()` - Logout

**Key Features:**
- Automatic JSON parsing
- Error handling
- Session cookie credentials (`credentials: 'same-origin'`)
- Content-Type headers set automatically
- **CSRF token handling**: Automatically includes CSRF token in POST/PUT requests
- CSRF token read from `window.CSRF_TOKEN` (set on page load)

## Key Features

### URL-Based Bookmark Updates

**Behavior:** When saving a bookmark with a URL that already exists, the system **updates** the existing bookmark instead of creating a duplicate.

**Implementation:**
1. Before insert, query: `SELECT id FROM bookmarks WHERE url = ?`
2. If found:
   - Update existing bookmark
   - Empty fields overwrite (set to NULL)
   - Tags always updated (replace all)
3. If not found:
   - Insert new bookmark

**Empty Field Handling:**
- Empty string `''` is treated as "clear this field"
- Converted to `NULL` in database
- Allows users to remove title/description by leaving fields blank

**Locations:**
- `/api/bookmarks.php` POST method
- `/api/bookmarklet.php` POST method

### Tag Preloading in Bookmarklet

**Behavior:** When bookmarklet popup opens, if bookmark already exists, preload its data.

**Implementation:**
1. On popup load, call `GET /api/bookmarklet.php?url=...`
2. If bookmark exists:
   - Overwrite title field with existing title (or empty)
   - Overwrite description field with existing description (or empty)
   - Preload tags if they exist
   - Skip clipboard reading
3. If bookmark doesn't exist:
   - Keep URL-parameter values (title, description)
   - Try to read clipboard for description

**Purpose:** Makes it easy to re-save/update bookmarks - user sees what's already saved and can modify.

### Settings System

**Three Settings:**

1. **Tags Alphabetical Order** (`tags_alphabetical`)
   - When enabled: Tags sorted alphabetically before display
   - When disabled: Tags shown in order added
   - Applied in `renderBookmarkTags()` function

2. **Show URL** (`show_url`)
   - When enabled: URL displayed under bookmark title
   - When disabled: URL hidden
   - Applied conditionally in bookmark rendering HTML

3. **Show Date/Time** (`show_datetime`)
   - When enabled: Shows "Dec 25, 2024 14:30"
   - When disabled: Shows "Dec 25, 2024"
   - Applied in `formatDate()` function

**Settings Loading:**
- Dashboard loads settings on page load via `API.getSettings()`
- Settings stored in `settings` variable
- Applied during bookmark rendering

### Authentication State Verification

**Problem:** Cloudflare caching can cache HTML with embedded authentication state, causing wrong UI to display.

**Solution:** Dynamic authentication verification on page load.

**Implementation:**
1. Dashboard HTML doesn't embed auth state in JavaScript
2. On page load, `verifyAuthState()` calls `/api/auth.php?action=status` with cache-busting parameter
3. Updates `window.IS_AUTHENTICATED` based on response
4. Renders UI conditionally (login/logout buttons, edit/delete icons)
5. Re-renders bookmarks if auth state changed

**Cache Prevention:**
- `index.php` sets `Cache-Control: no-cache, no-store, must-revalidate, private`
- API calls include timestamp query parameter for cache-busting

### Private/Public Bookmarks

**Behavior:**
- Public bookmarks visible to everyone (including non-authenticated users)
- Private bookmarks only visible to authenticated user
- Non-authenticated users cannot see private bookmarks in:
  - Dashboard bookmark list
  - Tag counts/sidebar
  - Tag autocomplete

**Implementation:**
- `is_private` column in `bookmarks` table (0 = public, 1 = private)
- All API queries filter: `WHERE is_private = 0` for non-authenticated users
- Dashboard shows/hides private badge based on auth state

### Tag System

**Tag Storage:**
- Tags stored in separate `tags` table (normalized)
- Many-to-many relationship via `bookmark_tags`
- Tag names stored in lowercase for consistency

**Tag Autocomplete:**
- Loads all tags on popup initialization
- Filters client-side as user types
- Shows matching tags (starting with typed text)
- Handles comma-separated input (detects current word being typed)

**Tag Display:**
- Dashboard shows tags as `#tagname` links
- Clicking tag filters bookmarks
- Tags sorted by count in sidebar
- Orphaned tags (count = 0) automatically filtered out

## Data Flow Examples

### Adding a New Bookmark via Bookmarklet

1. User clicks bookmarklet on webpage
2. Bookmarklet JavaScript extracts:
   - URL: `location.href`
   - Title: `document.title`
   - Selected text: `document.getSelection().toString()`
3. Opens popup: `bookmarklet-popup.php?url=...&title=...&description=...`
4. Popup loads:
   - Checks if bookmark exists: `GET /api/bookmarklet.php?url=...`
   - If exists: Preloads title, description, tags
   - If not: Keeps URL params, tries clipboard
5. User adds tags, clicks "Save Bookmark"
6. Form submits: `POST /api/bookmarklet.php`
7. API checks: Does bookmark with this URL exist?
   - If yes: Updates existing bookmark
   - If no: Creates new bookmark
8. API updates tags (removes old, adds new)
9. Popup closes, parent window refreshes (if same origin)

### Viewing Dashboard

1. User visits `index.php`
2. Page loads with cache-control headers
3. JavaScript initializes:
   - Calls `verifyAuthState()` → `GET /api/auth.php?action=status`
   - Sets `window.IS_AUTHENTICATED`
   - Updates UI (login/logout button)
4. Calls `loadSettings()` → `GET /api/settings.php`
   - Stores settings in variable
5. Calls `loadTags()` → `GET /api/tags.php`
   - Renders tag sidebar
6. Calls `loadBookmarks()` → `GET /api/bookmarks.php`
   - Includes pagination, search, tag filter params if applicable
   - For non-authenticated: filters `WHERE is_private = 0`
7. Renders bookmarks based on settings:
   - Tags sorted if `tags_alphabetical` enabled
   - URL shown/hidden based on `show_url`
   - Date format based on `show_datetime`

### Updating Existing Bookmark

1. User uses bookmarklet on URL they've already bookmarked
2. Popup opens and checks: `GET /api/bookmarklet.php?url=...`
3. Existing bookmark found, form pre-filled with:
   - Old title
   - Old description
   - Old tags
4. User modifies fields (or leaves empty to clear)
5. Submits: `POST /api/bookmarklet.php`
6. API detects existing bookmark by URL
7. Updates database:
   - Empty fields → set to NULL
   - Non-empty fields → update to new values
   - Tags → replace all with new tags
8. Returns success message

### Tag Autocomplete

1. User types in tags field: "web, dev"
2. After 300ms delay, autocomplete triggers
3. JavaScript extracts current word: "dev" (after last comma)
4. Filters `allTags` array for tags starting with "dev"
5. Shows dropdown with matches: ["development", "devops"]
6. User clicks "development"
7. Replaces "dev" with "development" in input
8. Result: "web, development"

## Security Considerations

### SQL Injection Prevention

- All database queries use **prepared statements** with parameter binding
- Example: `$stmt->execute([$url, $title])` instead of string concatenation
- SQLite automatically escapes parameters

### XSS Prevention

- All user input escaped with `htmlspecialchars()` via `h()` helper function
- Used in all PHP output: `<?php echo h($variable); ?>`
- JavaScript uses `escapeHtml()` function (creates text node)

### CSRF Protection

- **CSRF tokens** implemented for all state-changing operations (POST, PUT, DELETE)
- Tokens generated on page load and included in all API requests
- All authenticated endpoints verify CSRF tokens before processing requests
- Session cookie uses `SameSite=Lax` as additional protection layer
- Tokens stored in session and verified using `hash_equals()` for timing-safe comparison

### Authentication Security

- Password hashed with bcrypt (`password_hash()`)
- **No default password hash** - must be set via environment variable or config file
- **Rate limiting**: 5 failed login attempts per 15 minutes per IP address
- **Session fixation protection**: Session ID regenerated after successful login
- **Session timeout**: Maximum session lifetime of 1 year (31536000 seconds)
- Session cookie is HttpOnly (not accessible via JavaScript)
- Secure flag set on HTTPS connections
- Session destroyed on logout
- Login attempts tracked in `login_attempts` table with automatic cleanup

### Private Bookmark Protection

- Database queries filter private bookmarks for non-authenticated users
- Applied consistently across:
  - Bookmark listing
  - Tag counts
  - Tag autocomplete
  - Search results

### CORS Configuration

- Bookmarklet API validates origin against allowlist (no wildcard with credentials)
- Only allows specific origins: same domain, localhost, 127.0.0.1
- Cross-origin requests from unknown origins are rejected (403)
- Same-origin requests (popup window) don't require CORS headers
- Other APIs use same-origin policy
- CORS headers set appropriately in `api/bookmarklet.php`

## Important Implementation Details

### Cache Control Headers

Dashboard page (`index.php`) sets aggressive no-cache headers:
```php
header("Cache-Control: no-cache, no-store, must-revalidate, private");
header("Pragma: no-cache");
header("Expires: 0");
```

**Purpose:** Prevent Cloudflare/browser from caching HTML with wrong authentication state.

### Tag Normalization

- All tags stored in lowercase
- Trimmed of whitespace
- Duplicates removed with `array_unique()`
- Empty tags filtered out

### Date Formatting

- Stored as UTC datetime in database
- Formatted client-side with `toLocaleDateString()` / `toLocaleString()`
- Respects user's browser locale settings

### Pagination

- 20 bookmarks per page
- Offset calculated: `($page - 1) * 20`
- Total pages: `ceil(total / 20)`
- Previous/Next buttons disabled at boundaries

### Error Handling

- API errors return JSON: `{error: "message"}`
- HTTP status codes: 400 (bad request), 401 (unauthorized), 403 (forbidden), 500 (server error)
- **Database errors sanitized**: Generic error messages returned to prevent information leakage
- Frontend shows errors via alerts or error divs
- Database errors caught and returned as generic 500 errors (no stack traces or SQL details exposed)

### Input Validation

- **URL validation**: All bookmark URLs validated using `filter_var()` with `FILTER_VALIDATE_URL`
- URLs must be http or https schemes only
- Maximum URL length: 2048 characters
- **Input length limits**:
  - Titles: 500 characters
  - Descriptions: 5000 characters
  - Tags: 100 characters each
- Validation applied to all bookmark creation and update endpoints

## Configuration and Deployment

### Password Setup

**Option 1: Web-based setup (Recommended)**
1. Visit `setup-password-web.php` in your browser
2. Enter your password and generate hash
3. Copy the generated hash
4. Set in `includes/config.php`: `$passwordHash = 'your_hash_here';`
5. **Delete `setup-password-web.php` after use for security**

**Option 2: Environment variable (Production)**
1. Generate hash: `password_hash('your_password', PASSWORD_DEFAULT)`
2. Set environment variable: `export PINBOARD_PASSWORD_HASH='your_hash_here'`
3. Or configure in your web server environment

**Option 3: Command line**
1. Run: `php setup-password.php your_password`
2. Copy the generated hash
3. Set in `includes/config.php` or as environment variable

**Important:** The application will not start without a password hash configured. No default hash is provided for security.

### Site Customization

1. Edit `includes/config.php`:
   - Set `SITE_NAME` constant
   - Set `SITE_SUBTITLE` constant
2. Or leave undefined to use HTML defaults

### Database Setup

- Database auto-created at `data/bookmarks.db` on first run
- Schema auto-applied from `sql/schema.sql`
- Ensure `data/` directory is writable

### Cloudflare Configuration

- Application works behind Cloudflare proxy
- Session cookies work correctly (checks `HTTP_X_FORWARDED_PROTO`)
- Cache headers prevent HTML caching issues
- See `CLOUDFLARE.md` for detailed configuration

---

This documentation should provide a complete understanding of how the pinboard clone application works, its components, data flow, and implementation details.

