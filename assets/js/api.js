/**
 * API helper functions
 */

const API_BASE = '/api';

async function apiRequest(endpoint, options = {}) {
    const url = API_BASE + endpoint;
    const defaultOptions = {
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin'
    };
    
    const config = { ...defaultOptions, ...options };
    
    if (config.body && typeof config.body === 'object') {
        // Add CSRF token to request body for state-changing methods
        if (['POST', 'PUT', 'DELETE'].includes(config.method || 'GET')) {
            if (window.CSRF_TOKEN) {
                config.body.csrf_token = window.CSRF_TOKEN;
            }
        }
        config.body = JSON.stringify(config.body);
    }
    
    try {
        const response = await fetch(url, config);
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.error || 'API request failed');
        }
        
        return data;
    } catch (error) {
        console.error('API request failed:', error);
        throw error;
    }
}

const API = {
    // Bookmarks
    getBookmarks: (params = {}) => {
        const queryString = new URLSearchParams(params).toString();
        return apiRequest('/bookmarks.php' + (queryString ? '?' + queryString : ''));
    },
    
    createBookmark: (bookmark) => {
        return apiRequest('/bookmarks.php', {
            method: 'POST',
            body: bookmark
        });
    },
    
    updateBookmark: (bookmark) => {
        return apiRequest('/bookmarks.php', {
            method: 'PUT',
            body: bookmark
        });
    },
    
    deleteBookmark: (id) => {
        // Add CSRF token to query string for DELETE
        const csrfToken = window.CSRF_TOKEN || '';
        return apiRequest(`/bookmarks.php?id=${id}&csrf_token=${encodeURIComponent(csrfToken)}`, {
            method: 'DELETE'
        });
    },
    
    // Tags
    getTags: (all = false, query = '') => {
        const params = new URLSearchParams();
        if (query) {
            params.append('q', query);
        }
        if (all) {
            params.append('all', '1');
        }
        const queryString = params.toString();
        return apiRequest('/tags.php' + (queryString ? '?' + queryString : ''));
    },
    
    deleteTag: (tagName) => {
        return apiRequest('/tags.php', {
            method: 'DELETE',
            body: {
                name: tagName
            }
        });
    },
    
    // Bookmarklet - get existing bookmark by URL
    getBookmarkByUrl: (url) => {
        return apiRequest('/bookmarklet.php?url=' + encodeURIComponent(url));
    },
    
    // Bookmarklet - create/update bookmark
    createBookmarkViaBookmarklet: (bookmark) => {
        return apiRequest('/bookmarklet.php', {
            method: 'POST',
            body: bookmark
        });
    },
    
    // Settings
    getSettings: () => {
        return apiRequest('/settings.php');
    },
    
    updateSettings: (settings) => {
        return apiRequest('/settings.php', {
            method: 'PUT',
            body: { settings }
        });
    },
    
    // Tags
    deleteTag: (tagName) => {
        return apiRequest('/tags.php', {
            method: 'DELETE',
            body: {
                name: tagName
            }
        });
    },
    
    // Import
    getImports: () => {
        return apiRequest('/import.php');
    },
    
    importBookmarks: (content, additionalTags = [], filename = null, format = 'auto') => {
        return apiRequest('/import.php', {
            method: 'POST',
            body: {
                content: content,
                additional_tags: Array.isArray(additionalTags) ? additionalTags : (additionalTags ? [additionalTags] : []),
                filename: filename,
                format: format
            }
        });
    },
    
    undoImport: (importId) => {
        return apiRequest('/import.php', {
            method: 'DELETE',
            body: {
                import_id: importId
            }
        });
    },
    
    // Auth
    logout: async () => {
        const formData = new URLSearchParams();
        formData.append('action', 'logout');
        if (window.CSRF_TOKEN) {
            formData.append('csrf_token', window.CSRF_TOKEN);
        }
        
        return fetch(API_BASE + '/auth.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            credentials: 'same-origin',
            body: formData
        }).then(async (response) => {
            const data = await response.json();
            if (!response.ok) {
                throw new Error(data.error || 'Logout failed');
            }
            return data;
        });
    },

    // WordPress
    testWpConnection: () => {
        return apiRequest('/wp-test-connection.php', {
            method: 'POST',
            body: {}
        });
    },

    checkWpBookmarkExists: (bookmarkId) => {
        return apiRequest(`/wp-publish.php?bookmark_id=${bookmarkId}`);
    },

    publishToWordPress: (bookmarkId) => {
        return apiRequest('/wp-publish.php', {
            method: 'POST',
            body: { bookmark_id: bookmarkId }
        });
    }
};

