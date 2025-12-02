<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Import page requires authentication
requireAuth();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Bookmarks - <?php echo h(defined('SITE_NAME') ? SITE_NAME : 'SelfHostedBookmarks'); ?></title>
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
                <h2>Import Bookmarks</h2>
                
                <div class="settings-form">
                    <div class="settings-group">
                        <h3>Import from File</h3>
                        
                        <div class="setting-item">
                            <p class="setting-description">
                                Import bookmarks from a Netscape-style HTML file (exported from Chrome, Firefox, Safari, etc.) or Pinboard JSON format.
                            </p>
                            
                            <div id="import-section">
                                <div class="form-group">
                                    <label for="bookmarks-file" class="setting-label">
                                        Bookmarks File:
                                    </label>
                                    <input type="file" 
                                           id="bookmarks-file" 
                                           accept=".html,.htm,.json" 
                                           class="setting-input">
                                    <p class="setting-description">
                                        Select a bookmarks file to import. Supports Netscape-style HTML files (.html) or Pinboard JSON format (.json).
                                    </p>
                                </div>
                                
                                <div class="form-group">
                                    <label for="import-tags" class="setting-label">
                                        Additional Tags (optional):
                                    </label>
                                    <input type="text" 
                                           id="import-tags" 
                                           placeholder="tag1, tag2, tag3" 
                                           class="setting-input">
                                    <p class="setting-description">
                                        Add these tags to all imported bookmarks. Separate multiple tags with commas.
                                    </p>
                                </div>
                                
                                <div class="form-group">
                                    <button type="button" id="import-btn" class="btn btn-primary">Import Bookmarks</button>
                                </div>
                                
                                <div id="import-status" class="import-status" style="display: none;"></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="import-history-section" style="margin-top: 3rem;">
                    <h2>Import History</h2>
                    <div id="import-history" class="import-history">
                        <p>Loading import history...</p>
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
        
        // Load import history
        async function loadImportHistory() {
            const historyDiv = document.getElementById('import-history');
            try {
                const result = await API.getImports();
                const imports = result.imports || [];
                
                if (imports.length === 0) {
                    historyDiv.innerHTML = '<p class="setting-description">No imports yet.</p>';
                    return;
                }
                
                let html = '<div class="imports-list">';
                imports.forEach(importRecord => {
                    const date = new Date(importRecord.created_at);
                    const dateStr = date.toLocaleString('en-GB', {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    const filename = importRecord.filename || 'Unknown file';
                    const additionalTags = importRecord.additional_tags ? ` (with tags: ${importRecord.additional_tags})` : '';
                    
                    html += `
                        <div class="import-record">
                            <div class="import-record-header">
                                <div class="import-record-info">
                                    <strong>${escapeHtml(filename)}</strong>
                                    <span class="import-record-date">${escapeHtml(dateStr)}</span>
                                </div>
                                <button class="btn btn-small undo-import-btn" data-import-id="${importRecord.id}">
                                    Undo
                                </button>
                            </div>
                            <div class="import-record-details">
                                <span>Created: ${importRecord.created_count} bookmarks</span>
                                ${importRecord.updated_count > 0 ? `<span>Updated: ${importRecord.updated_count} bookmarks</span>` : ''}
                                <span>Total: ${importRecord.bookmark_count} bookmarks</span>
                                ${additionalTags ? `<span class="import-tags">${escapeHtml(additionalTags)}</span>` : ''}
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
                historyDiv.innerHTML = html;
                
                // Attach undo handlers
                document.querySelectorAll('.undo-import-btn').forEach(btn => {
                    btn.addEventListener('click', async (e) => {
                        const importId = parseInt(e.target.getAttribute('data-import-id'));
                        await undoImport(importId);
                    });
                });
            } catch (error) {
                historyDiv.innerHTML = `<p class="error-message">Error loading import history: ${error.message}</p>`;
            }
        }
        
        // Undo import
        async function undoImport(importId) {
            if (!confirm('Are you sure you want to undo this import? This will delete all bookmarks that were imported in this operation.')) {
                return;
            }
            
            try {
                const result = await API.undoImport(importId);
                if (result.success) {
                    // Reload import history
                    await loadImportHistory();
                    
                    // Show success message
                    const statusDiv = document.getElementById('import-status');
                    statusDiv.className = 'import-status success-message';
                    statusDiv.textContent = result.message;
                    statusDiv.style.display = 'block';
                    
                    // Hide message after 5 seconds
                    setTimeout(() => {
                        statusDiv.style.display = 'none';
                    }, 5000);
                }
            } catch (error) {
                const statusDiv = document.getElementById('import-status');
                statusDiv.className = 'import-status error-message';
                statusDiv.textContent = 'Undo failed: ' + (error.message || 'Unknown error');
                statusDiv.style.display = 'block';
            }
        }
        
        // Import bookmarks handler
        document.getElementById('import-btn')?.addEventListener('click', async () => {
            const fileInput = document.getElementById('bookmarks-file');
            const tagsInput = document.getElementById('import-tags');
            const statusDiv = document.getElementById('import-status');
            const importBtn = document.getElementById('import-btn');
            
            if (!fileInput.files || !fileInput.files[0]) {
                statusDiv.className = 'import-status error-message';
                statusDiv.textContent = 'Please select a bookmarks file.';
                statusDiv.style.display = 'block';
                return;
            }
            
            const file = fileInput.files[0];
            const additionalTags = tagsInput.value.trim();
            const filename = file.name;
            const isJSON = filename.toLowerCase().endsWith('.json');
            
            // Read file
            const reader = new FileReader();
            reader.onload = async (e) => {
                try {
                    importBtn.disabled = true;
                    importBtn.textContent = 'Importing...';
                    statusDiv.style.display = 'block';
                    statusDiv.className = 'import-status';
                    statusDiv.textContent = 'Importing bookmarks...';
                    
                    const content = e.target.result;
                    const tagsArray = additionalTags ? additionalTags.split(',').map(t => t.trim()).filter(t => t) : [];
                    
                    const result = await API.importBookmarks(content, tagsArray, filename, isJSON ? 'pinboard' : 'netscape');
                    
                    if (result.success) {
                        statusDiv.className = 'import-status success-message';
                        statusDiv.innerHTML = `
                            <strong>Import successful!</strong><br>
                            ${result.message}<br>
                            ${result.errors && result.errors.length > 0 ? '<br>Errors:<br>' + result.errors.map(e => escapeHtml(e)).join('<br>') : ''}
                        `;
                        
                        // Clear file input
                        fileInput.value = '';
                        tagsInput.value = '';
                        
                        // Reload import history
                        await loadImportHistory();
                    } else {
                        statusDiv.className = 'import-status error-message';
                        statusDiv.textContent = result.error || 'Import failed';
                    }
                } catch (error) {
                    statusDiv.className = 'import-status error-message';
                    statusDiv.textContent = 'Import failed: ' + (error.message || 'Unknown error');
                } finally {
                    importBtn.disabled = false;
                    importBtn.textContent = 'Import Bookmarks';
                }
            };
            
            reader.onerror = () => {
                statusDiv.className = 'import-status error-message';
                statusDiv.textContent = 'Failed to read file.';
                statusDiv.style.display = 'block';
                importBtn.disabled = false;
                importBtn.textContent = 'Import Bookmarks';
            };
            
            reader.readAsText(file);
        });
        
        // Escape HTML helper
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Load import history on page load
        loadImportHistory();
    </script>
</body>
</html>

