<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Tags page requires authentication
requireAuth();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tags - <?php echo h(defined('SITE_NAME') ? SITE_NAME : 'SelfHostedBookmarks'); ?></title>
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
                    <a href="/settings.php" class="btn btn-small">Settings</a>
                    <button id="logout-btn" class="btn btn-small">Logout</button>
                </nav>
            </div>
        </header>
        
        <main class="app-main">
            <div class="settings-container">
                <h2>Tags</h2>
                
                <div class="settings-form">
                    <div class="settings-group">
                        <p class="setting-description">
                            Manage all tags. Deleting a tag will remove it from all bookmarks.
                        </p>
                        
                        <div id="tags-list-container">
                            <p>Loading tags...</p>
                        </div>
                    </div>
                </div>
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
        
        // Load and display tags
        async function loadTags() {
            const container = document.getElementById('tags-list-container');
            try {
                const data = await API.getTags(true); // Get all tags, bypass threshold
                const tags = data.tags || [];
                
                if (tags.length === 0) {
                    container.innerHTML = '<p class="setting-description">No tags found.</p>';
                    return;
                }
                
                // Sort alphabetically by name
                tags.sort((a, b) => a.name.localeCompare(b.name));
                
                let html = '<div class="tags-management-list">';
                tags.forEach(tag => {
                    html += `
                        <div class="tag-management-item">
                            <div class="tag-management-info">
                                <span class="tag-management-name">#${escapeHtml(tag.name)}</span>
                                <span class="tag-management-count">${tag.count} bookmark${tag.count !== 1 ? 's' : ''}</span>
                            </div>
                            <button class="btn btn-small btn-danger delete-tag-btn" data-tag-name="${escapeHtml(tag.name)}">
                                Delete
                            </button>
                        </div>
                    `;
                });
                html += '</div>';
                container.innerHTML = html;
                
                // Attach delete handlers
                document.querySelectorAll('.delete-tag-btn').forEach(btn => {
                    btn.addEventListener('click', async (e) => {
                        const tagName = e.target.getAttribute('data-tag-name');
                        await deleteTag(tagName);
                    });
                });
            } catch (error) {
                container.innerHTML = `<p class="error-message">Error loading tags: ${error.message}</p>`;
            }
        }
        
        // Delete tag
        async function deleteTag(tagName) {
            if (!confirm(`Are you sure you want to delete the tag "#${tagName}"? This will remove it from all bookmarks.`)) {
                return;
            }
            
            try {
                await API.deleteTag(tagName);
                // Reload tags list
                await loadTags();
            } catch (error) {
                alert('Failed to delete tag: ' + (error.message || 'Unknown error'));
            }
        }
        
        // Escape HTML helper
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Load tags on page load
        loadTags();
    </script>
</body>
</html>

