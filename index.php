<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Dashboard is public - no requireAuth() needed
$isAuthenticated = isAuthenticated();

// Prevent Cloudflare and browser caching of this page
// Since authentication state can change, we can't cache this page
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="app-container">
        <header class="app-header">
            <div class="header-content">
                <div class="header-title">
                    <h1 class="logo">
                        <?php 
                        // Default: SelfHostedBookmarks
                        // Override by defining SITE_NAME in includes/config.php
                        echo h(defined('SITE_NAME') ? SITE_NAME : 'SelfHostedBookmarks'); 
                        ?>
                    </h1>
                    <p class="header-subtitle">
                        <?php 
                        // Default: A selfhosted del.icio.us clone
                        // Override by defining SITE_SUBTITLE in includes/config.php
                        echo h(defined('SITE_SUBTITLE') ? SITE_SUBTITLE : 'A selfhosted del.icio.us clone'); 
                        ?>
                    </p>
                </div>
                <nav class="header-nav">
                    <?php if ($isAuthenticated): ?>
                        <a href="/tags.php" class="btn btn-small">Tags</a>
                        <a href="/settings.php" class="btn btn-small">Settings</a>
                        <button id="logout-btn" class="btn btn-small">Logout</button>
                    <?php else: ?>
                        <a href="/login.php" class="btn btn-small btn-primary">Login</a>
                    <?php endif; ?>
                </nav>
            </div>
        </header>
        
        <main class="app-main">
            <div class="dashboard-content">
                <div class="bookmarks-section">
                    <div class="bookmarks-header">
                        <form id="search-form" class="search-form">
                            <input type="text" 
                                   id="search-input" 
                                   placeholder="Search bookmarks..." 
                                   value="<?php echo h($_GET['search'] ?? ''); ?>"
                                   class="search-input">
                            <button type="submit" class="btn btn-primary">Search</button>
                            <button type="button" id="clear-search" class="btn btn-small">Clear</button>
                        </form>
                    </div>
                    
                    <div id="bookmarks-container" class="bookmarks-container">
                        <!-- Bookmarks will be loaded here -->
                    </div>
                    
                    <div id="pagination" class="pagination">
                        <!-- Pagination will be loaded here -->
                    </div>
                    <div class="app-footer">
                        <p>powered by <a href="https://github.com/honkerst/selfhostedbookmarks" target="_blank" rel="noopener noreferrer">selfhostedbookmarks</a></p>
                    </div>
                </div>
                
                <aside class="sidebar">
                    <div id="tags-sidebar" class="tags-sidebar">
                        <!-- Tags will be loaded here -->
                    </div>
                    <?php if ($isAuthenticated): ?>
                    <div class="bookmarklet-sidebar">
                        <h3>Bookmarklet</h3>
                        <p class="bookmarklet-instructions">
                            To install: Drag the link below to your bookmarks bar
                        </p>
                        <a href="javascript:(function(){var q=location.href;var p=document.title;var d='';if(document.getSelection){d=document.getSelection().toString();}else if(window.getSelection){d=window.getSelection().toString();}var url='https://bookmarks.thoughton.co.uk/bookmarklet-popup.php?url='+encodeURIComponent(q)+'&title='+encodeURIComponent(p)+'&description='+encodeURIComponent(d);window.open(url,'bookmarklet','toolbar=no,scrollbars=yes,width=600,height=550,resizable=yes');})();" 
                           class="bookmarklet-link"
                           id="bookmarklet-link">
                            +📌
                        </a>
                        <p class="bookmarklet-help">
                            Use this bookmarklet to quickly save any webpage. Select text on the page to include it as the description.
                        </p>
                    </div>
                    <?php endif; ?>
                </aside>
            </div>
        </main>
    </div>
    
    <script>
        // Don't embed auth state in HTML to prevent Cloudflare caching issues
        // Will be fetched dynamically by verifyAuthState() in dashboard.js
        window.IS_AUTHENTICATED = false; // Default to false, will be verified on page load
        // CSRF token for API requests
        window.CSRF_TOKEN = '<?php echo generateCSRFToken(); ?>';
    </script>
    <script src="/assets/js/api.js"></script>
    <script src="/assets/js/dashboard.js"></script>
</body>
</html>

