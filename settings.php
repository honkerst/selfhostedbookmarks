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
                'pagination_per_page' => $_POST['pagination_per_page'] ?? '20'
            ];
        
        // Save directly to database
        $validKeys = ['tags_alphabetical', 'show_url', 'show_datetime', 'pagination_per_page'];
        
        foreach ($settings as $key => $value) {
            if (!in_array($key, $validKeys)) {
                continue;
            }
            
            // Handle boolean vs string values
            if ($key === 'pagination_per_page') {
                $dbValue = $value; // Store as string
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
    foreach ($rows as $row) {
        // Handle pagination_per_page as string, others as boolean
        if ($row['key'] === 'pagination_per_page') {
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
} catch (PDOException $e) {
    $currentSettings = [
        'tags_alphabetical' => false,
        'show_url' => true,
        'show_datetime' => false,
        'pagination_per_page' => '20'
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
                    </div>
                    
                    <div class="settings-group">
                        <h3>Import Bookmarks</h3>
                        
                        <div class="setting-item">
                            <p class="setting-description">
                                Import bookmarks from a Netscape-style HTML file (exported from Chrome, Firefox, Safari, etc.).
                            </p>
                            <div class="form-group">
                                <a href="/import.php" class="btn btn-primary">Go to Import Page</a>
                            </div>
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
    </script>
</body>
</html>

