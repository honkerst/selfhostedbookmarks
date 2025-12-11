/**
 * Bookmarklet popup functionality
 */

let autocompleteTimeout;
let allTags = [];
let selectedAutocompleteIndex = -1;

document.addEventListener('DOMContentLoaded', async () => {
    const form = document.getElementById('bookmarklet-form');
    const tagsInput = document.getElementById('tags');
    const cancelBtn = document.getElementById('cancel-btn');
    const autocompleteContainer = document.getElementById('tag-autocomplete');
    
    // FIRST: Check if bookmark already exists and preload title, description, and tags
    // This must happen before anything else to overwrite URL params
    const hasExisting = await loadExistingBookmark();
    
    // Load all tags for autocomplete (can happen in parallel)
    loadTags();
    
    // Only try to read from clipboard if no existing bookmark was found
    if (!hasExisting) {
        loadClipboardContent();
    }
    
    // Form submission
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        await saveBookmark();
    });
    
    // Cancel button
    if (cancelBtn) {
        cancelBtn.addEventListener('click', () => {
            window.close();
        });
    }
    
    // Tag autocomplete
    if (tagsInput) {
        tagsInput.addEventListener('input', (e) => {
            clearTimeout(autocompleteTimeout);
            const value = e.target.value.trim();
            selectedAutocompleteIndex = -1;
            
            if (value.length > 0) {
                autocompleteTimeout = setTimeout(() => {
                    showAutocomplete(value);
                }, 300);
            } else {
                hideAutocomplete();
            }
        });
        
        tagsInput.addEventListener('focus', () => {
            const value = tagsInput.value.trim();
            selectedAutocompleteIndex = -1;
            if (value.length > 0) {
                showAutocomplete(value);
            }
        });
        
        tagsInput.addEventListener('blur', () => {
            // Delay hiding to allow clicking on suggestions
            setTimeout(() => hideAutocomplete(), 200);
        });
        
        // Keyboard navigation
        tagsInput.addEventListener('keydown', (e) => {
            const container = document.getElementById('tag-autocomplete');
            if (!container || container.style.display === 'none') {
                return;
            }
            
            const items = container.querySelectorAll('.autocomplete-item');
            if (items.length === 0) {
                return;
            }
            
            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    selectedAutocompleteIndex = (selectedAutocompleteIndex + 1) % items.length;
                    updateAutocompleteSelection(items);
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    selectedAutocompleteIndex = selectedAutocompleteIndex <= 0 
                        ? items.length - 1 
                        : selectedAutocompleteIndex - 1;
                    updateAutocompleteSelection(items);
                    break;
                case 'Enter':
                    e.preventDefault();
                    if (selectedAutocompleteIndex >= 0 && selectedAutocompleteIndex < items.length) {
                        const selectedItem = items[selectedAutocompleteIndex];
                        const currentTag = getCurrentTag(tagsInput.value, tagsInput.selectionStart || tagsInput.value.length);
                        selectAutocompleteTag(selectedItem.dataset.tag, currentTag);
                    }
                    break;
                case 'Escape':
                    e.preventDefault();
                    hideAutocomplete();
                    break;
            }
        });
    }
});

/**
 * Load clipboard content into description
 * Only called if no existing bookmark was found
 */
async function loadClipboardContent() {
    const descriptionField = document.getElementById('description');
    if (!descriptionField) {
        return;
    }
    
    // Only load clipboard if description is empty
    // (It might already have content from URL params)
    if (descriptionField.value.trim()) {
        console.log('Description already has content, skipping clipboard load');
        return;
    }
    
    try {
        // Try to read clipboard (requires HTTPS and user permission)
        if (navigator.clipboard && navigator.clipboard.readText) {
            const text = await navigator.clipboard.readText();
            if (text && !descriptionField.value.trim()) {
                descriptionField.value = text;
                console.log('Loaded content from clipboard');
            }
        }
    } catch (error) {
        // Clipboard access denied or not available - that's okay
        // Fall back to description from URL params (already set)
        console.log('Clipboard access not available:', error.message);
    }
}

/**
 * Load all tags
 */
async function loadTags() {
    try {
        const data = await API.getTags();
        allTags = (data.tags || []).map(t => t.name);
    } catch (error) {
        console.error('Failed to load tags:', error);
    }
}

/**
 * Load existing bookmark data by URL (for preloading title, description, and tags)
 * Returns true if an existing bookmark was found, false otherwise
 */
async function loadExistingBookmark() {
    const urlInput = document.getElementById('url');
    if (!urlInput) {
        console.log('URL input not found');
        return false;
    }
    
    const url = urlInput.value.trim();
    if (!url) {
        console.log('URL is empty');
        return false;
    }
    
    console.log('Checking for existing bookmark with URL:', url);
    
    try {
        const data = await API.getBookmarkByUrl(url);
        
        // If bookmark exists, preload title, description, and tags
        if (data.bookmark) {
            console.log('Existing bookmark found:', data.bookmark);
            
            // Preload title (always overwrite, even if empty/null)
            const titleInput = document.getElementById('title');
            if (titleInput) {
                titleInput.value = data.bookmark.title || '';
                console.log('Set title to:', data.bookmark.title || '(empty)');
            }
            
            // Preload description (always overwrite, even if empty/null)
            const descriptionInput = document.getElementById('description');
            if (descriptionInput) {
                descriptionInput.value = data.bookmark.description || '';
                console.log('Set description to:', data.bookmark.description ? '(has content)' : '(empty)');
            }
            
            // Preload tags (only if tags exist)
            const tagsInput = document.getElementById('tags');
            if (tagsInput) {
                if (data.bookmark.tags && data.bookmark.tags.length > 0) {
                    tagsInput.value = data.bookmark.tags.join(', ');
                    console.log('Set tags to:', data.bookmark.tags.join(', '));
                } else {
                    tagsInput.value = '';
                    console.log('No tags to preload');
                }
            }
            
            return true; // Existing bookmark found
        } else {
            console.log('No existing bookmark found for this URL');
            return false;
        }
    } catch (error) {
        // Log error for debugging
        console.error('Error checking for existing bookmark:', error);
        
        // If it's an authentication error, don't load clipboard (user needs to log in)
        if (error.message && error.message.includes('Unauthorized')) {
            console.error('Authentication required. Please log in.');
            return false; // Don't load clipboard, but also don't preload
        }
        
        // For other errors, assume bookmark doesn't exist and allow clipboard loading
        console.log('Error occurred, will try clipboard instead');
        return false;
    }
}

/**
 * Get current tag being typed
 */
function getCurrentTag(value, cursorPos) {
    const beforeCursor = value.substring(0, cursorPos);
    const lastComma = beforeCursor.lastIndexOf(',');
    return beforeCursor.substring(lastComma + 1).trim();
}

/**
 * Update autocomplete selection highlighting
 */
function updateAutocompleteSelection(items) {
    items.forEach((item, index) => {
        if (index === selectedAutocompleteIndex) {
            item.classList.add('autocomplete-selected');
            item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        } else {
            item.classList.remove('autocomplete-selected');
        }
    });
}

/**
 * Show tag autocomplete
 */
function showAutocomplete(query) {
    const container = document.getElementById('tag-autocomplete');
    if (!container) return;
    
    // Extract the last word/tag being typed
    const input = document.getElementById('tags');
    const value = input.value;
    const cursorPos = input.selectionStart || value.length;
    const currentTag = getCurrentTag(value, cursorPos);
    
    if (currentTag.length === 0) {
        hideAutocomplete();
        return;
    }
    
    // Filter tags matching current input
    const matches = allTags.filter(tag => 
        tag.toLowerCase().startsWith(currentTag.toLowerCase()) && 
        tag.toLowerCase() !== currentTag.toLowerCase()
    ).slice(0, 5);
    
    if (matches.length === 0) {
        hideAutocomplete();
        return;
    }
    
    container.innerHTML = matches.map(tag => `
        <div class="autocomplete-item" data-tag="${escapeHtml(tag)}">
            ${escapeHtml(tag)}
        </div>
    `).join('');
    
    container.style.display = 'block';
    selectedAutocompleteIndex = -1;
    
    // Attach click handlers
    container.querySelectorAll('.autocomplete-item').forEach(item => {
        item.addEventListener('click', () => {
            selectAutocompleteTag(item.dataset.tag, currentTag);
        });
    });
}

/**
 * Select autocomplete tag
 */
function selectAutocompleteTag(tag, currentTag) {
    const input = document.getElementById('tags');
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
    
    selectedAutocompleteIndex = -1;
    hideAutocomplete();
}

/**
 * Hide autocomplete
 */
function hideAutocomplete() {
    const container = document.getElementById('tag-autocomplete');
    if (container) {
        container.style.display = 'none';
    }
}

/**
 * Save bookmark
 */
async function saveBookmark() {
    const form = document.getElementById('bookmarklet-form');
    const errorDiv = document.getElementById('error-message');
    const successDiv = document.getElementById('success-message');
    
    // Hide previous messages
    errorDiv.style.display = 'none';
    successDiv.style.display = 'none';
    
    const url = document.getElementById('url').value.trim();
    const title = document.getElementById('title').value.trim();
    const description = document.getElementById('description').value.trim();
    const tagsString = document.getElementById('tags').value.trim();
    const isPrivate = document.getElementById('is_private').checked;
    
    if (!url) {
        showError('URL is required');
        return;
    }
    
    // Parse tags
    const tags = tagsString ? tagsString.split(',').map(t => t.trim()).filter(t => t) : [];
    
    try {
        // Use bookmarklet API which handles URL-based updates
        await API.createBookmarkViaBookmarklet({
            url: url,
            title: title,
            description: description,
            tags: tags,
            is_private: isPrivate ? 1 : 0
        });
        
        // Success - close popup after short delay
        successDiv.textContent = 'Bookmark saved successfully!';
        successDiv.style.display = 'block';
        
        setTimeout(() => {
            // Refresh parent window if it exists (same origin)
            try {
                if (window.opener && !window.opener.closed) {
                    window.opener.location.reload();
                }
            } catch (e) {
                // Cross-origin - can't access
            }
            window.close();
        }, 500);
        
    } catch (error) {
        showError(error.message || 'Failed to save bookmark');
    }
}

/**
 * Show error message
 */
function showError(message) {
    const errorDiv = document.getElementById('error-message');
    errorDiv.textContent = message;
    errorDiv.style.display = 'block';
}

/**
 * Utility
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

