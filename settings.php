<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Settings page requires authentication
requireAuth();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $settings = [
                'tags_alphabetical' => isset($_POST['tags_alphabetical']) && $_POST['tags_alphabetical'] === '1',
                'show_url' => isset($_POST['show_url']) && $_POST['show_url'] === '1',
                'show_datetime' => isset($_POST['show_datetime']) && $_POST['show_datetime'] === '1',
                'pagination_per_page' => $_POST['pagination_per_page'] ?? '20',
                'tag_threshold' => isset($_POST['tag_threshold']) ? max(0, (int)$_POST['tag_threshold']) : '2',
                // WordPress sync settings
                'shb_base_url' => trim($_POST['shb_base_url'] ?? ''),
                'wp_base_url' => trim($_POST['wp_base_url'] ?? ''),
                'wp_user' => trim($_POST['wp_user'] ?? ''),
                'wp_app_password' => trim($_POST['wp_app_password'] ?? ''),
                'wp_watch_tag' => trim($_POST['wp_watch_tag'] ?? ''),
                'wp_post_tags' => trim($_POST['wp_post_tags'] ?? ''),
                'wp_post_categories' => trim($_POST['wp_post_categories'] ?? '')
            ];
            
            // Clear connection tested flag when WP settings change
            $stmt = $pdo->prepare("
                INSERT INTO settings (key, value, updated_at)
                VALUES ('wp_connection_tested', '0', datetime('now'))
                ON CONFLICT(key) DO UPDATE SET
                    value = '0',
                    updated_at = datetime('now')
            ");
            $stmt->execute();
        
        // Save directly to database
        $validKeys = [
            'tags_alphabetical',
            'show_url',
            'show_datetime',
            'pagination_per_page',
            'tag_threshold',
            'shb_base_url',
            'wp_base_url',
            'wp_user',
            'wp_app_password',
            'wp_watch_tag',
            'wp_post_tags',
            'wp_post_categories'
        ];
        
        foreach ($settings as $key => $value) {
            if (!in_array($key, $validKeys)) {
                continue;
            }
            
            // Handle boolean vs string values
            if (in_array($key, ['pagination_per_page', 'tag_threshold', 'shb_base_url', 'wp_base_url', 'wp_user', 'wp_app_password', 'wp_watch_tag', 'wp_post_tags', 'wp_post_categories'])) {
                $dbValue = (string)$value; // Store as string
            } else {
                $dbValue = $value ? '1' : '0';
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO settings (key, value, updated_at)
                VALUES (?, ?, datetime('now'))
                ON CONFLICT(key) DO UPDATE SET
                    value = excluded.value,
                    updated_at = datetime('now')
            ");
            $stmt->execute([$key, $dbValue]);
        }
        
            // Redirect to dashboard with success message
            header('Location: /index.php?settings_saved=1');
            exit;
        } catch (PDOException $e) {
            $error = 'Database error occurred';
        }
    }
}

// Load current settings
try {
    $stmt = $pdo->query("SELECT key, value FROM settings");
    $rows = $stmt->fetchAll();
    
    $currentSettings = [];
    $stringSettings = ['pagination_per_page', 'tag_threshold', 'shb_base_url', 'wp_base_url', 'wp_user', 'wp_app_password', 'wp_watch_tag', 'wp_post_tags', 'wp_post_categories', 'wp_connection_tested'];
    foreach ($rows as $row) {
        // Handle string settings vs boolean settings
        if (in_array($row['key'], $stringSettings)) {
            $currentSettings[$row['key']] = $row['value'];
        } else {
            $currentSettings[$row['key']] = $row['value'] === '1' || $row['value'] === 'true';
        }
    }
    
    // Apply defaults
    $currentSettings['tags_alphabetical'] = $currentSettings['tags_alphabetical'] ?? false;
    $currentSettings['show_url'] = $currentSettings['show_url'] ?? true;
    $currentSettings['show_datetime'] = $currentSettings['show_datetime'] ?? false;
    $currentSettings['pagination_per_page'] = $currentSettings['pagination_per_page'] ?? '20';
    $currentSettings['tag_threshold'] = $currentSettings['tag_threshold'] ?? '2';
    $currentSettings['wp_connection_tested'] = $currentSettings['wp_connection_tested'] ?? '0';
    $currentSettings['wp_base_url'] = $currentSettings['wp_base_url'] ?? '';
    $currentSettings['wp_user'] = $currentSettings['wp_user'] ?? '';
    $currentSettings['wp_app_password'] = $currentSettings['wp_app_password'] ?? '';
    $currentSettings['wp_watch_tag'] = $currentSettings['wp_watch_tag'] ?? '';
    $currentSettings['wp_post_tags'] = $currentSettings['wp_post_tags'] ?? 'interesting,thc,shb';
    $currentSettings['wp_post_categories'] = $currentSettings['wp_post_categories'] ?? 'Interesting stuff';
} catch (PDOException $e) {
    $currentSettings = [
        'tags_alphabetical' => false,
        'show_url' => true,
        'show_datetime' => false,
        'pagination_per_page' => '20',
        'tag_threshold' => '2',
        'wp_base_url' => '',
        'wp_user' => '',
        'wp_app_password' => '',
        'wp_watch_tag' => '',
        'wp_post_tags' => 'interesting,thc,shb',
        'wp_post_categories' => 'Interesting stuff'
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - <?php echo h(defined('SITE_NAME') ? SITE_NAME : 'SelfHostedBookmarks'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="app-container">
        <header class="app-header">
            <div class="header-content">
                <div class="header-title">
                    <h1 class="logo">
                        <a href="/index.php" style="text-decoration: none; color: inherit;">
                            <?php 
                            echo h(defined('SITE_NAME') ? SITE_NAME : 'SelfHostedBookmarks'); 
                            ?>
                        </a>
                    </h1>
                    <p class="header-subtitle">
                        <?php 
                        echo h(defined('SITE_SUBTITLE') ? SITE_SUBTITLE : 'A selfhosted del.icio.us clone'); 
                        ?>
                    </p>
                </div>
                <nav class="header-nav">
                    <a href="/index.php" class="btn btn-small">← Dashboard</a>
                    <a href="/tags.php" class="btn btn-small">Tags</a>
                    <button id="logout-btn" class="btn btn-small">Logout</button>
                </nav>
            </div>
        </header>
        
        <main class="app-main">
            <div class="settings-container">
                <h2>Settings</h2>
                
                <?php if ($message): ?>
                    <div class="success-message"><?php echo h($message); ?></div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="error-message"><?php echo h($error); ?></div>
                <?php endif; ?>
                
                <form method="POST" action="/settings.php" class="settings-form">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="settings-group">
                        <h3>Display Options</h3>
                        
                        <div class="setting-item">
                            <label class="toggle-label">
                                <input type="checkbox" 
                                       name="tags_alphabetical" 
                                       value="1" 
                                       <?php echo $currentSettings['tags_alphabetical'] ? 'checked' : ''; ?>>
                                <span class="toggle-text">
                                    Always show tags for bookmarks in alphabetical order?
                                </span>
                            </label>
                            <p class="setting-description">
                                When enabled, tags will be sorted alphabetically. When disabled, tags appear in the order they were added.
                            </p>
                        </div>
                        
                        <div class="setting-item">
                            <label class="toggle-label">
                                <input type="checkbox" 
                                       name="show_url" 
                                       value="1" 
                                       <?php echo $currentSettings['show_url'] ? 'checked' : ''; ?>>
                                <span class="toggle-text">
                                    Display the URL under each bookmark title?
                                </span>
                            </label>
                            <p class="setting-description">
                                When enabled, the full URL is shown below the bookmark title.
                            </p>
                        </div>
                        
                        <div class="setting-item">
                            <label class="toggle-label">
                                <input type="checkbox" 
                                       name="show_datetime" 
                                       value="1" 
                                       <?php echo $currentSettings['show_datetime'] ? 'checked' : ''; ?>>
                                <span class="toggle-text">
                                    Show exact date and time on bookmarks?
                                </span>
                            </label>
                            <p class="setting-description">
                                When enabled, shows the full date and time (e.g., "25 Dec, 2024 14:30"). When disabled, shows only the date (e.g., "25 Dec, 2024").
                            </p>
                        </div>
                        
                        <div class="setting-item">
                            <label for="pagination_per_page" class="setting-label">
                                Bookmarks per page:
                            </label>
                            <select id="pagination_per_page" name="pagination_per_page" class="setting-select">
                                <option value="1" <?php echo $currentSettings['pagination_per_page'] === '1' ? 'selected' : ''; ?>>1</option>
                                <option value="5" <?php echo $currentSettings['pagination_per_page'] === '5' ? 'selected' : ''; ?>>5</option>
                                <option value="10" <?php echo $currentSettings['pagination_per_page'] === '10' ? 'selected' : ''; ?>>10</option>
                                <option value="20" <?php echo $currentSettings['pagination_per_page'] === '20' ? 'selected' : ''; ?>>20</option>
                                <option value="50" <?php echo $currentSettings['pagination_per_page'] === '50' ? 'selected' : ''; ?>>50</option>
                                <option value="100" <?php echo $currentSettings['pagination_per_page'] === '100' ? 'selected' : ''; ?>>100</option>
                                <option value="250" <?php echo $currentSettings['pagination_per_page'] === '250' ? 'selected' : ''; ?>>250</option>
                                <option value="500" <?php echo $currentSettings['pagination_per_page'] === '500' ? 'selected' : ''; ?>>500</option>
                                <option value="1000" <?php echo $currentSettings['pagination_per_page'] === '1000' ? 'selected' : ''; ?>>1000</option>
                                <option value="unlimited" <?php echo $currentSettings['pagination_per_page'] === 'unlimited' ? 'selected' : ''; ?>>Unlimited</option>
                            </select>
                            <p class="setting-description">
                                Number of bookmarks to display per page. "Unlimited" will show all bookmarks on a single page.
                            </p>
                        </div>
                        
                        <div class="setting-item">
                            <label for="tag_threshold" class="setting-label">
                                Tag threshold (minimum count):
                            </label>
                            <input type="number" 
                                   id="tag_threshold" 
                                   name="tag_threshold" 
                                   min="0" 
                                   value="<?php echo h($currentSettings['tag_threshold'] ?? '2'); ?>" 
                                   class="setting-input"
                                   style="max-width: 150px;">
                            <p class="setting-description">
                                Only show tags in the sidebar that have been used at least this many times. Default is 2.
                            </p>
                        </div>
                    </div>
                    
                    <div class="settings-group">
                        <h3>Import Bookmarks</h3>
                        
                        <div class="setting-item">
                            <p class="setting-description">
                                Import bookmarks from a Netscape-style HTML file (exported from Chrome, Firefox, Safari, etc.), or a pinboard.in JSON file.
                            </p>
                            <div class="form-group">
                                <a href="/import.php" class="btn btn-primary">Go to Import Page</a>
                            </div>
                        </div>
                    </div>

                    <div class="settings-group">
                        <h3>WordPress Auto-Post</h3>
                        <p class="setting-description">
                            Configure automatic posting of bookmarks to your WordPress site. The sync script will post the newest bookmark matching the watch tag with the tags/categories you specify below.
                        </p>

                        <div class="setting-item">
                            <label for="shb_base_url" class="setting-label">SHB Base URL</label>
                            <input type="text" id="shb_base_url" name="shb_base_url" class="setting-input"
                                   value="<?php echo h($currentSettings['shb_base_url'] ?? 'https://bookmarks.thoughton.co.uk'); ?>"
                                   placeholder="https://bookmarks.example.com">
                            <p class="setting-description">
                                The base URL of your SelfHostedBookmarks installation (where the API endpoints live).
                            </p>
                        </div>

                        <div class="setting-item">
                            <label for="wp_base_url" class="setting-label">WordPress Base URL</label>
                            <input type="text" id="wp_base_url" name="wp_base_url" class="setting-input"
                                   value="<?php echo h($currentSettings['wp_base_url']); ?>"
                                   placeholder="https://example.com">
                            <p class="setting-description">
                                The root URL where your WordPress REST API lives (e.g., https://example.com).
                            </p>
                        </div>

                        <div class="setting-item">
                            <label for="wp_user" class="setting-label">WordPress Username</label>
                            <input type="text" id="wp_user" name="wp_user" class="setting-input"
                                   value="<?php echo h($currentSettings['wp_user']); ?>"
                                   placeholder="wp-admin-user">
                        </div>

                        <div class="setting-item">
                            <label for="wp_app_password" class="setting-label">WordPress Application Password</label>
                            <input type="password" id="wp_app_password" name="wp_app_password" class="setting-input"
                                   value="<?php echo h($currentSettings['wp_app_password']); ?>"
                                   placeholder="Paste app password">
                            <p class="setting-description">
                                Create this in WordPress under your user profile → Application Passwords.
                            </p>
                            <p class="setting-description" style="margin-top: 0.5rem; color: var(--text-light); font-size: 0.875rem;">
                                <strong>Security Note:</strong> This password is stored in plaintext in the database (not encrypted) because WordPress REST API authentication requires the actual password value. The SHB settings page is protected by authentication—only logged-in users can access it. To keep your credentials secure: ensure your SHB installation requires a strong password, use HTTPS, and don't share your login credentials. If your database is compromised, regenerate this application password in WordPress.
                            </p>
                            <div class="form-group" style="margin-top: 1rem;">
                                <button type="button" id="test-wp-connection" class="btn btn-small">Test Connection</button>
                                <span id="wp-connection-status" style="margin-left: 1rem; <?php echo ($currentSettings['wp_connection_tested'] === '1') ? 'color: var(--success-color);' : ''; ?>">
                                    <?php if ($currentSettings['wp_connection_tested'] === '1'): ?>
                                        ✓ Connection verified
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>

                        <div class="setting-item">
                            <label for="wp_watch_tag" class="setting-label">SHB Tag to Watch</label>
                            <input type="text" id="wp_watch_tag" name="wp_watch_tag" class="setting-input"
                                   value="<?php echo h($currentSettings['wp_watch_tag']); ?>"
                                   placeholder="e.g., thc">
                            <p class="setting-description">
                                The SHB tag that triggers posting to WordPress (newest bookmark with this tag will be posted).
                            </p>
                        </div>

                        <div class="setting-item">
                            <label for="wp_post_tags" class="setting-label">WordPress Tags (comma-separated)</label>
                            <input type="text" id="wp_post_tags" name="wp_post_tags" class="setting-input"
                                   value="<?php echo h($currentSettings['wp_post_tags']); ?>"
                                   placeholder="interesting,thc,shb">
                        </div>

                        <div class="setting-item">
                            <label for="wp_post_categories" class="setting-label">WordPress Categories (comma-separated)</label>
                            <input type="text" id="wp_post_categories" name="wp_post_categories" class="setting-input"
                                   value="<?php echo h($currentSettings['wp_post_categories']); ?>"
                                   placeholder="Interesting stuff">
                        </div>

                        <div class="setting-item">
                            <h4>Scheduling</h4>
                            <p class="setting-description">
                                You will need to schedule the sync script to run at specified intervals. This can be done in a few ways:
                            </p>
                            <p class="setting-description" style="font-weight: 500; margin-bottom: 0.5rem;">Crontab entry:</p>
<pre id="cron-example" class="setting-description" style="white-space: pre-wrap; word-break: break-word; background:#f5f5f5; padding:8px; border-radius:4px;">
*/2 * * * * /usr/bin/php /path/to/bookmarks.thoughton.co.uk/scripts/shb_thc_to_wp.php >>$HOME/shb_sync.log 2>&1
</pre>
                            <p class="setting-description" style="margin-top: 1rem; font-weight: 500; margin-bottom: 0.5rem;">Terminal command (for control panels like Plesk and Enhance that allow you to run a command at specified intervals):</p>
<pre id="terminal-example" class="setting-description" style="white-space: pre-wrap; word-break: break-word; background:#f5f5f5; padding:8px; border-radius:4px;">
WP_BASE_URL="https://thoughton.co.uk" WP_USER="tim" WP_APP_PASSWORD="..." /usr/bin/php /path/to/bookmarks.thoughton.co.uk/scripts/shb_thc_to_wp.php
</pre>
                            <p class="setting-description">
                                Replace the /path/to/ above with the actual path to your script, and fill in your Application Password. Environment variables are optional and will override database settings if provided.
                            </p>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save Settings</button>
                        <a href="/index.php" class="btn">Cancel</a>
                    </div>
                </form>
            </div>
        </main>
    </div>
    
    <script src="/assets/js/api.js"></script>
    <script>
        // Set CSRF token
        window.CSRF_TOKEN = '<?php echo generateCSRFToken(); ?>';
        
        // Logout handler
        document.getElementById('logout-btn')?.addEventListener('click', async () => {
            try {
                await API.logout();
                window.location.href = '/login.php';
            } catch (error) {
                console.error('Logout failed:', error);
                window.location.href = '/login.php';
            }
        });

        // Live-update cron example based on user inputs
        function updateCronExample() {
            const shbBaseUrl = document.getElementById('shb_base_url')?.value || 'https://bookmarks.thoughton.co.uk';
            const wpBaseUrl = document.getElementById('wp_base_url')?.value || '';
            const wpUser = document.getElementById('wp_user')?.value || '';
            const wpAppPassword = document.getElementById('wp_app_password')?.value || '';
            const watchTag = document.getElementById('wp_watch_tag')?.value || '';
            const wpTags = document.getElementById('wp_post_tags')?.value || '';
            const wpCategories = document.getElementById('wp_post_categories')?.value || '';

            // Extract domain from SHB base URL to suggest script path
            let scriptPath = '/path/to/bookmarks.thoughton.co.uk/scripts/shb_thc_to_wp.php';
            try {
                const url = new URL(shbBaseUrl);
                const hostname = url.hostname;
                // Suggest path based on hostname
                scriptPath = `/path/to/${hostname}/scripts/shb_thc_to_wp.php`;
            } catch (e) {
                // Keep default if URL parsing fails
            }

            // Build cron command - show that settings are read from database
            const cronSchedule = '*/2 * * * *';
            const command = `/usr/bin/php ${scriptPath} >>$HOME/shb_sync.log 2>&1`;

            // Optionally show env var overrides (for reference)
            const envVars = [];
            if (wpBaseUrl) envVars.push(`WP_BASE_URL="${wpBaseUrl}"`);
            if (wpUser) envVars.push(`WP_USER="${wpUser}"`);
            if (wpAppPassword) envVars.push(`WP_APP_PASSWORD="${wpAppPassword.replace(/./g, '*')}"`); // Mask password
            if (watchTag) envVars.push(`SHB_TAG="${watchTag}"`);
            if (wpTags) envVars.push(`WP_TAGS="${wpTags}"`);
            if (wpCategories) envVars.push(`WP_CATEGORIES="${wpCategories}"`);

            // Build cron command: schedule + env vars (if any) + command
            let cronCmd;
            if (envVars.length > 0) {
                cronCmd = `${cronSchedule} ${envVars.join(' ')} ${command}`;
                cronCmd += '\n\n# Note: Settings are read from the database. Env vars above are optional overrides.';
            } else {
                cronCmd = `${cronSchedule} ${command}`;
                cronCmd += '\n\n# Settings are read from the database (no env vars needed).';
            }

            const cronExample = document.getElementById('cron-example');
            if (cronExample) {
                cronExample.textContent = cronCmd;
            }

            // Build terminal command (no schedule, no log redirection)
            const terminalCommand = `/usr/bin/php ${scriptPath}`;
            let terminalCmd;
            if (envVars.length > 0) {
                terminalCmd = `${envVars.join(' ')} ${terminalCommand}`;
            } else {
                terminalCmd = terminalCommand;
            }

            const terminalExample = document.getElementById('terminal-example');
            if (terminalExample) {
                terminalExample.textContent = terminalCmd;
            }
        }

        // Add event listeners to all relevant input fields
        const inputsToWatch = ['shb_base_url', 'wp_base_url', 'wp_user', 'wp_app_password', 'wp_watch_tag', 'wp_post_tags', 'wp_post_categories'];
        inputsToWatch.forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                input.addEventListener('input', updateCronExample);
                input.addEventListener('change', updateCronExample);
            }
        });

        // Update on page load
        updateCronExample();

        // Test WordPress connection
        document.getElementById('test-wp-connection')?.addEventListener('click', async () => {
            const btn = document.getElementById('test-wp-connection');
            const status = document.getElementById('wp-connection-status');
            if (!btn || !status) return;

            btn.disabled = true;
            btn.textContent = 'Testing...';
            const originalStatus = status.textContent;
            status.textContent = '';
            status.style.color = '';

            try {
                const response = await fetch('/api/wp-test-connection.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        csrf_token: window.CSRF_TOKEN
                    })
                });

                const data = await response.json();

                if (data.success) {
                    status.textContent = '✓ ' + data.message;
                    status.style.color = 'var(--success-color)';
                    // Message will persist - it's stored in database and shown on page load
                } else {
                    status.textContent = '✗ ' + (data.error || 'Connection failed');
                    status.style.color = 'var(--error-color)';
                }
            } catch (error) {
                status.textContent = '✗ Connection test failed';
                status.style.color = 'var(--error-color)';
            } finally {
                btn.disabled = false;
                btn.textContent = 'Test Connection';
            }
        });
        
        // Clear success message when WP settings change
        const wpInputs = ['wp_base_url', 'wp_user', 'wp_app_password'];
        wpInputs.forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                input.addEventListener('input', () => {
                    const status = document.getElementById('wp-connection-status');
                    if (status && status.textContent.includes('✓')) {
                        status.textContent = '';
                        status.style.color = '';
                    }
                });
            }
        });
    </script>
</body>
</html>

