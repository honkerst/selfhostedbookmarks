<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

requireAuth();

// Get pre-filled data from URL parameters
$url = $_GET['url'] ?? '';
$title = $_GET['title'] ?? '';
$description = $_GET['description'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Bookmark - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        body {
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .popup-container {
            max-width: 600px;
            margin: 0 auto;
        }
    </style>
</head>
<body>
    <div class="popup-container">
        <h2>Add Bookmark</h2>
        
        <form id="bookmarklet-form" class="bookmark-form">
            <div id="error-message" class="error-message" style="display: none;"></div>
            <div id="success-message" class="success-message" style="display: none;"></div>
            
            <div class="form-group">
                <label for="url">URL *</label>
                <input type="url" id="url" name="url" required value="<?php echo h($url); ?>">
            </div>
            
            <div class="form-group">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" value="<?php echo h($title); ?>">
            </div>
            
            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="4"><?php echo h($description); ?></textarea>
            </div>
            
            <div class="form-group">
                <label for="tags">Tags (comma-separated)</label>
                <input type="text" 
                       id="tags" 
                       name="tags" 
                       placeholder="tag1, tag2, tag3"
                       autocomplete="off">
                <div id="tag-autocomplete" class="tag-autocomplete"></div>
            </div>
            
            <div class="form-group">
                <label>
                    <input type="checkbox" id="is_private" name="is_private" value="1">
                    Private
                </label>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Bookmark</button>
                <button type="button" id="cancel-btn" class="btn">Cancel</button>
            </div>
        </form>
    </div>
    
    <script>
        // CSRF token for API requests
        window.CSRF_TOKEN = '<?php echo generateCSRFToken(); ?>';
    </script>
    <script src="/assets/js/api.js"></script>
    <script src="/assets/js/bookmarklet.js"></script>
</body>
</html>

