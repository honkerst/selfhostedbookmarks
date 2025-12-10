/**
 * Dashboard functionality
 */

// Ensure IS_AUTHENTICATED is defined (defaults to false)
if (typeof window.IS_AUTHENTICATED === 'undefined') {
    window.IS_AUTHENTICATED = false;
}

let currentPage = 1;
let currentTag = '';
let currentSearch = '';
let bookmarks = [];
let tags = [];
let settings = {
    tags_alphabetical: false,
    show_url: true,
    show_datetime: false,
    pagination_per_page: '20'
};
let wpConfigured = false;
let wpStatusCache = {}; // Cache for WP status checks (bookmark_id -> {exists, timestamp})

/**
 * Verify authentication state from server
 * This is the SOURCE OF TRUTH for authentication state
 */
async function verifyAuthState() {
    // Always start false - only set to true if server confirms
    window.IS_AUTHENTICATED = false;
    
    try {
        // Fetch auth status from server with cache-busting and credentials
        const response = await fetch('/api/auth.php?t=' + Date.now(), {
            method: 'GET',
            credentials: 'same-origin', // Include session cookies
            cache: 'no-store' // Don't cache this request
        });
        
        if (!response.ok) {
            console.warn('Auth check failed with status:', response.status);
            window.IS_AUTHENTICATED = false;
            return;
        }
        
        const data = await response.json();
        // Only set to true if explicitly authenticated
        window.IS_AUTHENTICATED = data.authenticated === true;
        
    } catch (error) {
        console.error('Failed to verify auth status:', error);
        // On error, default to false for security
        window.IS_AUTHENTICATED = false;
    }
    
    // Update UI based on verified auth state
    updateUIForAuthState();
}

/**
 * Update UI elements based on authentication state
 */
function updateUIForAuthState() {
    // Show/hide logout/login button
    const logoutBtn = document.getElementById('logout-btn');
    const loginLink = document.querySelector('.header-nav a[href="/login.php"]');
    
    if (window.IS_AUTHENTICATED === true) {
        if (!logoutBtn && loginLink) {
            // Replace login link with logout button
            loginLink.outerHTML = '<button id="logout-btn" class="btn btn-small">Logout</button>';
            // Re-attach logout handler
            attachLogoutHandler();
        }
    } else {
        if (logoutBtn && !loginLink) {
            // Replace logout button with login link
            logoutBtn.outerHTML = '<a href="/login.php" class="btn btn-small btn-primary">Login</a>';
        }
    }
    
    // Show/hide bookmarklet sidebar
    const bookmarkletSidebar = document.querySelector('.bookmarklet-sidebar');
    if (window.IS_AUTHENTICATED === true) {
        // Show bookmarklet sidebar if hidden (server-side rendering)
        if (!bookmarkletSidebar) {
            // Would need to add it, but for now let's just ensure it's shown
        }
    } else {
        // Hide bookmarklet sidebar
        if (bookmarkletSidebar) {
            bookmarkletSidebar.style.display = 'none';
        }
    }
}

/**
 * Attach logout handler (needed when button is dynamically created)
 */
function attachLogoutHandler() {
    const logoutBtn = document.getElementById('logout-btn');
    if (logoutBtn && !logoutBtn.hasAttribute('data-handler-attached')) {
        logoutBtn.setAttribute('data-handler-attached', 'true');
        logoutBtn.addEventListener('click', async () => {
            try {
                await API.logout();
                window.IS_AUTHENTICATED = false;
                window.location.reload(); // Reload to show public view
            } catch (error) {
                console.error('Logout failed:', error);
                // Still reload even if logout API fails
                window.location.reload();
            }
        });
    }
}

// Initialize
document.addEventListener('DOMContentLoaded', async () => {
    // IMPORTANT: Always start with false - will be verified from server
    window.IS_AUTHENTICATED = false;
    
    // CRITICAL: Verify authentication state from server FIRST, before anything else
    await verifyAuthState();
    
    // Load settings
    await loadSettings();
    
    // Now load data - auth state is verified, settings loaded
    loadTags();
    loadBookmarks();
    
    // Show success message if settings were just saved
    if (new URLSearchParams(window.location.search).get('settings_saved') === '1') {
        showSuccess('Settings saved successfully!');
        // Remove query parameter from URL
        window.history.replaceState({}, '', window.location.pathname);
    }
    
    // Search form
    const searchForm = document.getElementById('search-form');
    if (searchForm) {
        searchForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const input = document.getElementById('search-input');
            currentSearch = input.value.trim();
            currentPage = 1;
            loadBookmarks();
        });
    }
    
    // Clear search
    const clearSearch = document.getElementById('clear-search');
    if (clearSearch) {
        clearSearch.addEventListener('click', () => {
            document.getElementById('search-input').value = '';
            currentSearch = '';
            currentPage = 1;
            loadBookmarks();
        });
    }
    
    // Attach logout handler
    attachLogoutHandler();
});

/**
 * Load bookmarks
 */
async function loadBookmarks() {
    try {
        const params = {
            page: currentPage
        };
        
        if (currentTag) {
            params.tag = currentTag;
        }
        
        if (currentSearch) {
            params.search = currentSearch;
        }
        
        const data = await API.getBookmarks(params);
        bookmarks = data.bookmarks || [];
        
        // Render bookmarks - they will check window.IS_AUTHENTICATED which was verified on page load
        renderBookmarks();
        renderPagination(data.pagination);
    } catch (error) {
        console.error('Failed to load bookmarks:', error);
        showError('Failed to load bookmarks');
    }
}

/**
 * Load settings
 */
async function loadSettings() {
    try {
        const data = await API.getSettings();
        settings = data.settings || settings; // Use defaults if API fails
        
        // Check if WordPress is configured AND connection has been tested
        const wpSettingsExist = !!(settings.wp_base_url && settings.wp_user && settings.wp_app_password);
        const wpConnectionTested = settings.wp_connection_tested === '1' || settings.wp_connection_tested === 1;
        wpConfigured = wpSettingsExist && wpConnectionTested;
    } catch (error) {
        console.error('Failed to load settings:', error);
        // Use defaults
        wpConfigured = false;
    }
}

/**
 * Render bookmark tags (with alphabetical sorting if enabled)
 */
function renderBookmarkTags(tagArray) {
    let tagsToRender = [...tagArray]; // Copy array
    
    // Sort alphabetically if setting is enabled
    if (settings.tags_alphabetical) {
        tagsToRender.sort((a, b) => a.localeCompare(b));
    }
    
    return tagsToRender.map(tag => `<span class="tag">#${escapeHtml(tag)}</span>`).join('');
}

/**
 * Load tags for sidebar
 */
async function loadTags() {
    try {
        const data = await API.getTags();
        tags = data.tags || [];
        renderTags();
    } catch (error) {
        console.error('Failed to load tags:', error);
    }
}

/**
 * Render bookmarks
 */
function renderBookmarks() {
    const container = document.getElementById('bookmarks-container');
    if (!container) return;
    
    if (bookmarks.length === 0) {
        container.innerHTML = '<div class="empty-state">No bookmarks found. Add one using the bookmarklet!</div>';
        return;
    }
    
    container.innerHTML = bookmarks.map(bookmark => `
        <div class="bookmark-card" data-id="${bookmark.id}">
            <div class="bookmark-header">
                <h3 class="bookmark-title">
                    <a href="${escapeHtml(bookmark.url)}" target="_blank" rel="noopener">
                        ${escapeHtml(bookmark.title || bookmark.url)}
                    </a>
                </h3>
                ${(window.IS_AUTHENTICATED === true) ? `
                <div class="bookmark-actions">
                    ${wpConfigured ? `
                    <button class="btn-icon publish-to-wp" data-id="${bookmark.id}" title="Publish to WordPress" data-wp-status="unknown">
                        📤
                    </button>
                    ` : ''}
                    <button class="btn-icon edit-bookmark" data-id="${bookmark.id}" title="Edit">
                        ✏️
                    </button>
                    <button class="btn-icon delete-bookmark" data-id="${bookmark.id}" title="Delete">
                        🗑️
                    </button>
                </div>
                ` : ''}
            </div>
            ${settings.show_url ? `<div class="bookmark-url">${escapeHtml(bookmark.url)}</div>` : ''}
            ${bookmark.description ? `<div class="bookmark-description">${escapeHtml(bookmark.description)}</div>` : ''}
            <div class="bookmark-footer">
                <div class="bookmark-tags">
                    ${bookmark.tags && bookmark.tags.length > 0 ? 
                        renderBookmarkTags(bookmark.tags) 
                        : '<span class="no-tags">No tags</span>'
                    }
                </div>
                <div class="bookmark-meta">
                    <span class="bookmark-date">${formatDate(bookmark.created_at, settings.show_datetime)}</span>
                    ${(window.IS_AUTHENTICATED === true && bookmark.is_private) ? '<span class="private-badge">Private</span>' : ''}
                </div>
            </div>
        </div>
    `).join('');
    
    // Attach event listeners
    container.querySelectorAll('.delete-bookmark').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const id = parseInt(e.target.closest('.delete-bookmark').dataset.id);
            deleteBookmark(id);
        });
    });
    
    container.querySelectorAll('.edit-bookmark').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const id = parseInt(e.target.closest('.edit-bookmark').dataset.id);
            editBookmark(id);
        });
    });
    
    // Attach WordPress publish button handlers
    if (wpConfigured) {
        container.querySelectorAll('.publish-to-wp').forEach(btn => {
            const bookmarkId = parseInt(btn.dataset.id);
            
            // Check status on hover
            btn.addEventListener('mouseenter', () => {
                checkWpStatus(bookmarkId, btn);
            });
            
            // Publish on click
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                await publishToWordPress(bookmarkId, btn);
            });
        });
    }
}

/**
 * Render tags sidebar
 */
function renderTags() {
    const container = document.getElementById('tags-sidebar');
    if (!container) return;
    
    if (tags.length === 0) {
        container.innerHTML = '<div class="empty-tags">No tags yet</div>';
        return;
    }
    
    container.innerHTML = `
        <h3>Tags</h3>
        <div class="tags-list">
            ${tags.map(tag => `
                <div class="tag-item ${currentTag === tag.name ? 'active' : ''}" 
                     data-tag="${escapeHtml(tag.name)}">
                    <span class="tag-name">#${escapeHtml(tag.name)}</span>
                    <span class="tag-count">${tag.count}</span>
                </div>
            `).join('')}
        </div>
        ${currentTag ? '<button id="clear-tag-filter" class="btn btn-small">Clear filter</button>' : ''}
    `;
    
    // Attach event listeners
    container.querySelectorAll('.tag-item').forEach(item => {
        item.addEventListener('click', () => {
            const tag = item.dataset.tag;
            filterByTag(tag);
        });
    });
    
    const clearBtn = document.getElementById('clear-tag-filter');
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            filterByTag('');
        });
    }
}

/**
 * Filter by tag
 */
function filterByTag(tag) {
    currentTag = tag;
    currentPage = 1;
    loadBookmarks();
    loadTags(); // Reload to update active state
}

/**
 * Delete bookmark
 */
async function deleteBookmark(id) {
    if (!confirm('Are you sure you want to delete this bookmark?')) {
        return;
    }
    
    try {
        await API.deleteBookmark(id);
        loadBookmarks();
        loadTags(); // Reload tags in case counts changed
    } catch (error) {
        console.error('Failed to delete bookmark:', error);
        showError('Failed to delete bookmark');
    }
}

/**
 * Edit bookmark inline
 */
let editingBookmarkId = null;
let allTagsForAutocomplete = [];

async function editBookmark(id) {
    const bookmark = bookmarks.find(b => b.id === id);
    if (!bookmark) return;
    
    // If already editing this bookmark, do nothing
    if (editingBookmarkId === id) return;
    
    // If editing another bookmark, cancel that first
    if (editingBookmarkId !== null) {
        cancelEdit(editingBookmarkId);
    }
    
    editingBookmarkId = id;
    
    // Load tags for autocomplete if not already loaded
    if (allTagsForAutocomplete.length === 0) {
        try {
            const data = await API.getTags();
            allTagsForAutocomplete = (data.tags || []).map(t => t.name);
        } catch (error) {
            console.error('Failed to load tags:', error);
        }
    }
    
    const card = document.querySelector(`.bookmark-card[data-id="${id}"]`);
    if (!card) return;
    
    // Store original HTML for cancel
    card.dataset.originalHtml = card.innerHTML;
    
    // Create edit form
    const tagsString = bookmark.tags && bookmark.tags.length > 0 ? bookmark.tags.join(', ') : '';
    
    card.innerHTML = `
        <form class="bookmark-edit-form" data-id="${id}">
            <div class="form-group">
                <label for="edit-url-${id}">URL *</label>
                <input type="url" id="edit-url-${id}" name="url" required value="${escapeHtml(bookmark.url)}">
            </div>
            
            <div class="form-group">
                <label for="edit-title-${id}">Title</label>
                <input type="text" id="edit-title-${id}" name="title" value="${escapeHtml(bookmark.title || '')}">
            </div>
            
            <div class="form-group">
                <label for="edit-description-${id}">Description</label>
                <textarea id="edit-description-${id}" name="description" rows="3">${escapeHtml(bookmark.description || '')}</textarea>
            </div>
            
            <div class="form-group">
                <label for="edit-tags-${id}">Tags (comma-separated)</label>
                <input type="text" 
                       id="edit-tags-${id}" 
                       name="tags" 
                       value="${escapeHtml(tagsString)}"
                       placeholder="tag1, tag2, tag3"
                       autocomplete="off">
                <div class="tag-autocomplete" id="edit-autocomplete-${id}"></div>
            </div>
            
            <div class="form-group">
                <label>
                    <input type="checkbox" id="edit-private-${id}" name="is_private" value="1" ${bookmark.is_private ? 'checked' : ''}>
                    Private
                </label>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn cancel-edit-btn">Cancel</button>
            </div>
        </form>
    `;
    
    // Setup tag autocomplete
    setupTagAutocomplete(id);
    
    // Attach form handlers
    const form = card.querySelector('.bookmark-edit-form');
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        await saveBookmarkEdit(id);
    });
    
    card.querySelector('.cancel-edit-btn').addEventListener('click', () => {
        cancelEdit(id);
    });
    
    // Focus on URL field
    document.getElementById(`edit-url-${id}`).focus();
}

/**
 * Setup tag autocomplete for edit form
 */
function setupTagAutocomplete(bookmarkId) {
    const input = document.getElementById(`edit-tags-${bookmarkId}`);
    const container = document.getElementById(`edit-autocomplete-${bookmarkId}`);
    if (!input || !container) return;
    
    let autocompleteTimeout;
    
    input.addEventListener('input', () => {
        clearTimeout(autocompleteTimeout);
        autocompleteTimeout = setTimeout(() => {
            showEditAutocomplete(input, container, bookmarkId);
        }, 300);
    });
    
    input.addEventListener('blur', () => {
        // Delay hiding to allow click on autocomplete item
        setTimeout(() => {
            container.style.display = 'none';
        }, 200);
    });
    
    // Hide autocomplete when clicking outside
    document.addEventListener('click', (e) => {
        if (!container.contains(e.target) && e.target !== input) {
            container.style.display = 'none';
        }
    });
}

/**
 * Show autocomplete for edit form
 */
function showEditAutocomplete(input, container, bookmarkId) {
    const value = input.value;
    const cursorPos = input.selectionStart || value.length;
    const beforeCursor = value.substring(0, cursorPos);
    const lastComma = beforeCursor.lastIndexOf(',');
    const currentTag = beforeCursor.substring(lastComma + 1).trim();
    
    if (currentTag.length === 0) {
        container.style.display = 'none';
        return;
    }
    
    // Filter tags matching current input
    const matches = allTagsForAutocomplete.filter(tag => 
        tag.toLowerCase().startsWith(currentTag.toLowerCase()) && 
        tag.toLowerCase() !== currentTag.toLowerCase()
    ).slice(0, 5);
    
    if (matches.length === 0) {
        container.style.display = 'none';
        return;
    }
    
    container.innerHTML = matches.map(tag => `
        <div class="autocomplete-item" data-tag="${escapeHtml(tag)}">
            ${escapeHtml(tag)}
        </div>
    `).join('');
    
    container.style.display = 'block';
    
    // Attach click handlers
    container.querySelectorAll('.autocomplete-item').forEach(item => {
        item.addEventListener('click', () => {
            selectEditAutocompleteTag(input, item.dataset.tag, currentTag);
            container.style.display = 'none';
        });
    });
}

/**
 * Select autocomplete tag in edit form
 */
function selectEditAutocompleteTag(input, tag, currentTag) {
    const value = input.value;
    const cursorPos = input.selectionStart || value.length;
    const beforeCursor = value.substring(0, cursorPos);
    const afterCursor = value.substring(cursorPos);
    const lastComma = beforeCursor.lastIndexOf(',');
    
    let newValue;
    if (lastComma === -1) {
        // First tag
        newValue = tag + (afterCursor.trim() ? ', ' + afterCursor : ', ');
    } else {
        const beforeTag = value.substring(0, lastComma + 1);
        newValue = beforeTag + ' ' + tag + (afterCursor.trim() ? ', ' + afterCursor : ', ');
    }
    
    input.value = newValue;
    input.focus();
    input.setSelectionRange(newValue.length, newValue.length);
}

/**
 * Save bookmark edit
 */
async function saveBookmarkEdit(id) {
    const form = document.querySelector(`.bookmark-edit-form[data-id="${id}"]`);
    if (!form) return;
    
    const url = document.getElementById(`edit-url-${id}`).value.trim();
    const title = document.getElementById(`edit-title-${id}`).value.trim();
    const description = document.getElementById(`edit-description-${id}`).value.trim();
    const tagsInput = document.getElementById(`edit-tags-${id}`).value.trim();
    const isPrivate = document.getElementById(`edit-private-${id}`).checked;
    
    if (!url) {
        alert('URL is required');
        return;
    }
    
    // Parse tags
    const tags = tagsInput ? tagsInput.split(',').map(t => t.trim()).filter(t => t) : [];
    
    try {
        const result = await API.updateBookmark({
            id: id,
            url: url,
            title: title,
            description: description,
            tags: tags,
            is_private: isPrivate ? 1 : 0
        });
        
        // Reload bookmarks to get updated data
        await loadBookmarks();
        editingBookmarkId = null;
    } catch (error) {
        alert('Failed to update bookmark: ' + (error.message || 'Unknown error'));
    }
}

/**
 * Cancel edit
 */
function cancelEdit(id) {
    const card = document.querySelector(`.bookmark-card[data-id="${id}"]`);
    if (!card) return;
    
    if (card.dataset.originalHtml) {
        card.innerHTML = card.dataset.originalHtml;
        delete card.dataset.originalHtml;
        
        // Re-attach event listeners after restoring HTML
        const editBtn = card.querySelector('.edit-bookmark');
        if (editBtn) {
            editBtn.addEventListener('click', (e) => {
                const bookmarkId = parseInt(e.target.closest('.edit-bookmark').dataset.id);
                editBookmark(bookmarkId);
            });
        }
        
        const deleteBtn = card.querySelector('.delete-bookmark');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', (e) => {
                const bookmarkId = parseInt(e.target.closest('.delete-bookmark').dataset.id);
                deleteBookmark(bookmarkId);
            });
        }
    }
    
    if (editingBookmarkId === id) {
        editingBookmarkId = null;
    }
}

/**
 * Render pagination
 */
function renderPagination(pagination) {
    const container = document.getElementById('pagination');
    if (!container || !pagination || pagination.pages <= 1) {
        if (container) container.innerHTML = '';
        return;
    }
    
    const pages = [];
    const totalPages = pagination.pages;
    const current = pagination.page;
    
    // Previous button
    pages.push(`<button class="btn-page ${current === 1 ? 'disabled' : ''}" 
                      data-page="${current - 1}" 
                      ${current === 1 ? 'disabled' : ''}>Previous</button>`);
    
    // Page numbers
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= current - 1 && i <= current + 1)) {
            pages.push(`<button class="btn-page ${i === current ? 'active' : ''}" 
                              data-page="${i}">${i}</button>`);
        } else if (i === current - 2 || i === current + 2) {
            pages.push('<span class="pagination-ellipsis">...</span>');
        }
    }
    
    // Next button
    pages.push(`<button class="btn-page ${current === totalPages ? 'disabled' : ''}" 
                      data-page="${current + 1}" 
                      ${current === totalPages ? 'disabled' : ''}>Next</button>`);
    
    container.innerHTML = pages.join('');
    
    // Attach event listeners
    container.querySelectorAll('.btn-page:not(.disabled)').forEach(btn => {
        btn.addEventListener('click', () => {
            currentPage = parseInt(btn.dataset.page);
            loadBookmarks();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });
}

/**
 * Utility functions
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateString, showDateTime = false) {
    const date = new Date(dateString);
    
    if (showDateTime) {
        return date.toLocaleString('en-GB', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    } else {
        return date.toLocaleDateString('en-GB', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric' 
        });
    }
}

function showError(message) {
    // Simple error display - can be enhanced
    alert('Error: ' + message);
}

function showSuccess(message) {
    // Simple success display - can be enhanced with a proper notification system
    // For now, use a temporary alert or console log
    // TODO: Replace with a proper toast/notification system
    const notification = document.createElement('div');
    notification.className = 'success-notification';
    notification.textContent = message;
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #4CAF50;
        color: white;
        padding: 1rem 1.5rem;
        border-radius: 4px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        z-index: 10000;
        animation: slideIn 0.3s ease-out;
    `;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease-out';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

/**
 * Check if bookmark exists in WordPress (on-demand, cached)
 */
async function checkWpStatus(bookmarkId, button) {
    // Check cache first (valid for 5 minutes)
    const cached = wpStatusCache[bookmarkId];
    if (cached && (Date.now() - cached.timestamp) < 300000) {
        updateWpButtonState(button, cached.exists);
        return;
    }
    
    // Show loading state
    button.style.opacity = '0.5';
    button.title = 'Checking WordPress...';
    
    try {
        const data = await API.checkWpBookmarkExists(bookmarkId);
        
        // Update cache
        wpStatusCache[bookmarkId] = {
            exists: data.exists === true,
            timestamp: Date.now()
        };
        
        updateWpButtonState(button, data.exists === true);
    } catch (error) {
        console.error('Failed to check WP status:', error);
        button.style.opacity = '1';
        button.title = 'Publish to WordPress (check failed)';
    }
}

/**
 * Update WordPress publish button state
 */
function updateWpButtonState(button, exists) {
    button.style.opacity = exists ? '0.4' : '1';
    button.disabled = exists;
    button.dataset.wpStatus = exists ? 'exists' : 'new';
    button.title = exists ? 'Already published to WordPress' : 'Publish to WordPress';
    if (exists) {
        button.style.cursor = 'not-allowed';
    } else {
        button.style.cursor = 'pointer';
    }
}

/**
 * Publish bookmark to WordPress
 */
async function publishToWordPress(bookmarkId, button) {
    // Check if already exists
    const cached = wpStatusCache[bookmarkId];
    if (cached && cached.exists) {
        showError('This bookmark is already published to WordPress');
        return;
    }
    
    // Confirm action
    if (!confirm('Publish this bookmark to WordPress?')) {
        return;
    }
    
    // Show loading state
    const originalTitle = button.title;
    button.disabled = true;
    button.style.opacity = '0.5';
    button.title = 'Publishing...';
    
    try {
        const data = await API.publishToWordPress(bookmarkId);
        
        if (data.success) {
            // Update cache
            wpStatusCache[bookmarkId] = {
                exists: true,
                timestamp: Date.now()
            };
            
            updateWpButtonState(button, true);
            showSuccess('Bookmark published to WordPress successfully!');
        } else if (data.already_exists) {
            // Update cache
            wpStatusCache[bookmarkId] = {
                exists: true,
                timestamp: Date.now()
            };
            
            updateWpButtonState(button, true);
            showError(data.message || 'This bookmark is already published to WordPress');
        } else {
            showError(data.error || 'Failed to publish to WordPress');
            button.disabled = false;
            button.style.opacity = '1';
            button.title = originalTitle;
        }
    } catch (error) {
        console.error('Failed to publish to WordPress:', error);
        showError(error.message || 'Failed to publish to WordPress');
        button.disabled = false;
        button.style.opacity = '1';
        button.title = originalTitle;
    }
}

