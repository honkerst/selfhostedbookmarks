<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Publish requires authentication
if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // Verify CSRF token
    $data = json_decode(file_get_contents('php://input'), true);
    $csrfToken = $data['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid security token']);
        exit;
    }
    
    $bookmarkId = isset($data['bookmark_id']) ? (int)$data['bookmark_id'] : 0;
    if ($bookmarkId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid bookmark ID']);
        exit;
    }
    
    // Get bookmark from database
    try {
        $stmt = $pdo->prepare("
            SELECT b.*, GROUP_CONCAT(DISTINCT t.name) as tags
            FROM bookmarks b
            LEFT JOIN bookmark_tags bt ON b.id = bt.bookmark_id
            LEFT JOIN tags t ON bt.tag_id = t.id
            WHERE b.id = ?
            GROUP BY b.id
        ");
        $stmt->execute([$bookmarkId]);
        $bookmark = $stmt->fetch();
        
        if (!$bookmark) {
            http_response_code(404);
            echo json_encode(['error' => 'Bookmark not found']);
            exit;
        }
        
        // Format tags
        $bookmark['tags'] = $bookmark['tags'] ? explode(',', $bookmark['tags']) : [];
        $bookmark['is_private'] = (bool)$bookmark['is_private'];
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error occurred']);
        exit;
    }
    
    // Get WordPress settings
    $wpSettings = [];
    try {
        $keys = ['wp_base_url', 'wp_user', 'wp_app_password', 'wp_post_tags', 'wp_post_categories'];
        $placeholders = rtrim(str_repeat('?,', count($keys)), ',');
        $stmt = $pdo->prepare("SELECT key, value FROM settings WHERE key IN ($placeholders)");
        $stmt->execute($keys);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $wpSettings[$row['key']] = $row['value'];
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error occurred']);
        exit;
    }
    
    if (empty($wpSettings['wp_base_url']) || empty($wpSettings['wp_user']) || empty($wpSettings['wp_app_password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'WordPress settings are not configured']);
        exit;
    }
    
    // Check if URL already exists in WordPress
    $wpBase = rtrim($wpSettings['wp_base_url'], '/');
    $wpUser = $wpSettings['wp_user'];
    $wpPassword = $wpSettings['wp_app_password'];
    $authHeader = 'Authorization: Basic ' . base64_encode($wpUser . ':' . $wpPassword);
    
    $searchUrl = $wpBase . '/wp-json/wp/v2/posts?search=' . rawurlencode($bookmark['url']) . '&per_page=100';
    $ch = curl_init($searchUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [$authHeader],
        CURLOPT_USERAGENT => 'SHB-WordPress-Sync/1.0',
    ]);
    $searchBody = curl_exec($ch);
    $searchStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($searchBody && $searchStatus === 200) {
        $searchResults = json_decode($searchBody, true);
        if (is_array($searchResults)) {
            foreach ($searchResults as $post) {
                if (isset($post['content']['rendered']) && strpos($post['content']['rendered'], $bookmark['url']) !== false) {
                    echo json_encode([
                        'success' => false,
                        'already_exists' => true,
                        'message' => 'This bookmark URL already exists in WordPress'
                    ]);
                    exit;
                }
            }
        }
    }
    
    // Post to WordPress (reuse logic from sync script)
    $wpUrl = $wpBase . '/wp-json/wp/v2/posts';
    $title = $bookmark['title'] ?: $bookmark['url'];
    $desc = trim((string)($bookmark['description'] ?? ''));
    if (strtolower($desc) === 'uncategorized') {
        $desc = '';
    }
    
    $contentParts = [];
    if ($desc !== '') {
        $contentParts[] = '<p>' . htmlspecialchars($desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }
    $contentParts[] = '<p><a href="' . htmlspecialchars($bookmark['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Link</a></p>';
    $content = implode("\n", $contentParts);
    
    $payload = [
        'title' => $title,
        'content' => $content,
        'status' => 'publish',
    ];
    
    // Set post date from bookmark's created_at
    if (isset($bookmark['created_at']) && !empty($bookmark['created_at'])) {
        $bookmarkDate = new DateTime($bookmark['created_at']);
        $payload['date'] = $bookmarkDate->format('c');
    }
    
    // Ensure tags exist and attach them
    $wpTags = !empty($wpSettings['wp_post_tags']) ? array_filter(array_map('trim', explode(',', $wpSettings['wp_post_tags']))) : [];
    if (!empty($wpTags)) {
        $tagIds = [];
        foreach ($wpTags as $tagName) {
            $safeSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', $tagName), '-'));
            $tagUrl = $wpBase . '/wp-json/wp/v2/tags?slug=' . rawurlencode($safeSlug);
            $tagCh = curl_init($tagUrl);
            curl_setopt_array($tagCh, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => [$authHeader],
            ]);
            $tagBody = curl_exec($tagCh);
            curl_close($tagCh);
            $tagResp = json_decode($tagBody, true);
            if (!empty($tagResp) && isset($tagResp[0]['id'])) {
                $tagIds[] = $tagResp[0]['id'];
            } else {
                // Create tag
                $createTagCh = curl_init($wpBase . '/wp-json/wp/v2/tags');
                curl_setopt_array($createTagCh, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => [$authHeader, 'Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => json_encode(['name' => $tagName, 'slug' => $safeSlug]),
                    CURLOPT_TIMEOUT => 10,
                ]);
                $createTagBody = curl_exec($createTagCh);
                $createTagStatus = curl_getinfo($createTagCh, CURLINFO_HTTP_CODE);
                curl_close($createTagCh);
                if ($createTagStatus === 201) {
                    $createTagResp = json_decode($createTagBody, true);
                    if (isset($createTagResp['id'])) {
                        $tagIds[] = $createTagResp['id'];
                    }
                }
            }
        }
        if (!empty($tagIds)) {
            $payload['tags'] = $tagIds;
        }
    }
    
    // Ensure categories exist and attach them
    $wpCategories = !empty($wpSettings['wp_post_categories']) ? array_filter(array_map('trim', explode(',', $wpSettings['wp_post_categories']))) : [];
    if (!empty($wpCategories)) {
        $catIds = [];
        foreach ($wpCategories as $catName) {
            $safeSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', $catName), '-'));
            $catUrl = $wpBase . '/wp-json/wp/v2/categories?slug=' . rawurlencode($safeSlug);
            $catCh = curl_init($catUrl);
            curl_setopt_array($catCh, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => [$authHeader],
            ]);
            $catBody = curl_exec($catCh);
            curl_close($catCh);
            $catResp = json_decode($catBody, true);
            if (!empty($catResp) && isset($catResp[0]['id'])) {
                $catIds[] = $catResp[0]['id'];
            } else {
                // Create category
                $createCatCh = curl_init($wpBase . '/wp-json/wp/v2/categories');
                curl_setopt_array($createCatCh, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => [$authHeader, 'Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => json_encode(['name' => $catName, 'slug' => $safeSlug]),
                    CURLOPT_TIMEOUT => 10,
                ]);
                $createCatBody = curl_exec($createCatCh);
                $createCatStatus = curl_getinfo($createCatCh, CURLINFO_HTTP_CODE);
                curl_close($createCatCh);
                if ($createCatStatus === 201) {
                    $createCatResp = json_decode($createCatBody, true);
                    if (isset($createCatResp['id'])) {
                        $catIds[] = $createCatResp['id'];
                    }
                }
            }
        }
        if (!empty($catIds)) {
            $payload['categories'] = $catIds;
        }
    }
    
    // Post to WordPress
    $postCh = curl_init($wpUrl);
    curl_setopt_array($postCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [$authHeader, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_UNRESTRICTED_AUTH => true,
    ]);
    $postBody = curl_exec($postCh);
    $postStatus = curl_getinfo($postCh, CURLINFO_HTTP_CODE);
    $postErr = curl_error($postCh);
    curl_close($postCh);
    
    if ($postBody === false || $postStatus >= 400) {
        http_response_code(500);
        echo json_encode(['error' => "Failed to publish to WordPress (HTTP $postStatus): " . ($postErr ?: 'Unknown error')]);
        exit;
    }
    
    $postResp = json_decode($postBody, true);
    if (isset($postResp['id'])) {
        echo json_encode([
            'success' => true,
            'message' => 'Bookmark published to WordPress successfully',
            'post_id' => $postResp['id']
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Unexpected response from WordPress']);
    }
    
} elseif ($method === 'GET') {
    // Check if URL exists in WordPress
    $bookmarkId = isset($_GET['bookmark_id']) ? (int)$_GET['bookmark_id'] : 0;
    if ($bookmarkId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid bookmark ID']);
        exit;
    }
    
    // Get bookmark URL
    try {
        $stmt = $pdo->prepare("SELECT url FROM bookmarks WHERE id = ?");
        $stmt->execute([$bookmarkId]);
        $bookmark = $stmt->fetch();
        
        if (!$bookmark) {
            http_response_code(404);
            echo json_encode(['error' => 'Bookmark not found']);
            exit;
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error occurred']);
        exit;
    }
    
    // Get WordPress settings
    $wpSettings = [];
    try {
        $keys = ['wp_base_url', 'wp_user', 'wp_app_password'];
        $placeholders = rtrim(str_repeat('?,', count($keys)), ',');
        $stmt = $pdo->prepare("SELECT key, value FROM settings WHERE key IN ($placeholders)");
        $stmt->execute($keys);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $wpSettings[$row['key']] = $row['value'];
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error occurred']);
        exit;
    }
    
    if (empty($wpSettings['wp_base_url']) || empty($wpSettings['wp_user']) || empty($wpSettings['wp_app_password'])) {
        echo json_encode(['exists' => false, 'configured' => false]);
        exit;
    }
    
    // Check if URL exists in WordPress
    $wpBase = rtrim($wpSettings['wp_base_url'], '/');
    $wpUser = $wpSettings['wp_user'];
    $wpPassword = $wpSettings['wp_app_password'];
    $authHeader = 'Authorization: Basic ' . base64_encode($wpUser . ':' . $wpPassword);
    
    $searchUrl = $wpBase . '/wp-json/wp/v2/posts?search=' . rawurlencode($bookmark['url']) . '&per_page=100';
    $ch = curl_init($searchUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [$authHeader],
        CURLOPT_USERAGENT => 'SHB-WordPress-Sync/1.0',
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $exists = false;
    if ($body && $status === 200) {
        $results = json_decode($body, true);
        if (is_array($results)) {
            foreach ($results as $post) {
                if (isset($post['content']['rendered']) && strpos($post['content']['rendered'], $bookmark['url']) !== false) {
                    $exists = true;
                    break;
                }
            }
        }
    }
    
    echo json_encode(['exists' => $exists, 'configured' => true]);
    
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

