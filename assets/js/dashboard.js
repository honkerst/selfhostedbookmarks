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
    } catch (error) {
        console.error('Failed to load settings:', error);
        // Use defaults
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
 * Edit bookmark (placeholder - can be enhanced with modal)
 */
function editBookmark(id) {
    const bookmark = bookmarks.find(b => b.id === id);
    if (!bookmark) return;
    
    // For now, just show an alert - can be enhanced with a modal
    alert('Edit functionality coming soon!\n\nBookmark: ' + bookmark.title);
    // TODO: Implement edit modal
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

