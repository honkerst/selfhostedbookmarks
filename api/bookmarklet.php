<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// CORS configuration for bookmarklet
// Note: Bookmarklet opens a popup window on the same domain, so API calls are same-origin
// CORS is only needed if external sites try to make direct API calls (which they shouldn't)
// For same-origin requests, browsers don't send Origin header, so we only handle cross-origin
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    // Cross-origin request - check if origin is allowed
    $allowedOrigins = [
        'https://bookmarks.thoughton.co.uk',
        'http://localhost',
        'http://127.0.0.1'
    ];
    
    if (in_array($origin, $allowedOrigins)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    } else {
        // Reject unknown origins
        http_response_code(403);
        echo json_encode(['error' => 'Origin not allowed']);
        exit;
    }
}
// For same-origin requests (no Origin header), don't set CORS headers - browser handles it

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// For bookmarklet POST, we need to check session cookie - user must be logged in
// For GET, we allow checking for existing bookmarks (used for tag preloading)
$method = $_SERVER['REQUEST_METHOD'];

// Require auth for all bookmarklet endpoints
// The bookmarklet popup requires authentication, so API calls should too
requireAuth();

if ($method === 'GET') {
    // Get existing bookmark by URL for tag preloading
    $url = $_GET['url'] ?? '';
    
    if (empty($url)) {
        http_response_code(400);
        echo json_encode(['error' => 'URL parameter is required']);
        exit;
    }
    
    try {
        // Find bookmark by URL (user is authenticated, so can see all their bookmarks)
        // Try exact match first
        $stmt = $pdo->prepare("
            SELECT b.*, GROUP_CONCAT(DISTINCT t.name) as tags
            FROM bookmarks b
            LEFT JOIN bookmark_tags bt ON b.id = bt.bookmark_id
            LEFT JOIN tags t ON bt.tag_id = t.id
            WHERE b.url = ?
            GROUP BY b.id
        ");
        $stmt->execute([$url]);
        $bookmark = $stmt->fetch();
        
        // If no exact match, try normalized URLs (handle trailing slash, http/https differences)
        if (!$bookmark) {
            // Try adding/removing trailing slash
            $urlVariations = [
                rtrim($url, '/'),
                $url . '/',
                str_replace('https://', 'http://', $url),
                str_replace('http://', 'https://', $url),
            ];
            
            foreach ($urlVariations as $urlVar) {
                if ($urlVar === $url) continue; // Already tried
                
                $stmt = $pdo->prepare("
                    SELECT b.*, GROUP_CONCAT(DISTINCT t.name) as tags
                    FROM bookmarks b
                    LEFT JOIN bookmark_tags bt ON b.id = bt.bookmark_id
                    LEFT JOIN tags t ON bt.tag_id = t.id
                    WHERE b.url = ?
                    GROUP BY b.id
                ");
                $stmt->execute([$urlVar]);
                $bookmark = $stmt->fetch();
                if ($bookmark) break;
            }
        }
        
        if ($bookmark) {
            $bookmark['tags'] = $bookmark['tags'] ? explode(',', $bookmark['tags']) : [];
            echo json_encode(['bookmark' => $bookmark]);
        } else {
            echo json_encode(['bookmark' => null]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error occurred']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'An error occurred']);
    }
} elseif ($method === 'POST') {
    // Verify CSRF token
    $data = json_decode(file_get_contents('php://input'), true);
    $csrfToken = $data['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid security token']);
        exit;
    }
    
    try {
        
        $url = $data['url'] ?? '';
        $title = $data['title'] ?? '';
        $description = $data['description'] ?? '';
        $tags = $data['tags'] ?? [];
        $isPrivate = isset($data['is_private']) ? (int)$data['is_private'] : 0;
        
        if (empty($url)) {
            http_response_code(400);
            echo json_encode(['error' => 'URL is required']);
            exit;
        }
        
        // Validate URL
        if (!validateUrl($url)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid URL format']);
            exit;
        }
        
        // Validate input lengths
        try {
            if ($title !== '' && $title !== null) {
                validateLength($title, 500, 'Title');
            }
            if ($description !== '' && $description !== null) {
                validateLength($description, 5000, 'Description');
            }
            // Validate tags
            if (is_array($tags)) {
                foreach ($tags as $tag) {
                    validateLength($tag, 100, 'Tag');
                }
            }
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
        
        // Check if bookmark already exists
        $stmt = $pdo->prepare("SELECT id FROM bookmarks WHERE url = ?");
        $stmt->execute([$url]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update existing bookmark
            // Empty fields should overwrite existing values (set to empty/null)
            $finalTitle = $title !== '' ? $title : null;
            $finalDescription = $description !== '' ? $description : null;
            
            $stmt = $pdo->prepare("
                UPDATE bookmarks
                SET title = ?, description = ?, is_private = ?, updated_at = datetime('now')
                WHERE id = ?
            ");
            $stmt->execute([$finalTitle, $finalDescription, $isPrivate, $existing['id']]);
            $bookmarkId = $existing['id'];
        } else {
            // Create new bookmark
            $stmt = $pdo->prepare("
                INSERT INTO bookmarks (url, title, description, is_private, created_at, updated_at)
                VALUES (?, ?, ?, ?, datetime('now'), datetime('now'))
            ");
            $stmt->execute([$url, $title, $description, $isPrivate]);
            $bookmarkId = $pdo->lastInsertId();
        }
        
        // Update tags - always update (even if empty array, removes all tags)
        $tags = is_array($tags) ? $tags : parseTags($tags);
        
        // Remove existing tags
        $stmt = $pdo->prepare("DELETE FROM bookmark_tags WHERE bookmark_id = ?");
        $stmt->execute([$bookmarkId]);
        
        // Add new tags
        if (!empty($tags)) {
            foreach ($tags as $tagName) {
                $tagName = strtolower(trim($tagName));
                if (empty($tagName)) continue;
                
                $stmt = $pdo->prepare("SELECT id FROM tags WHERE name = ?");
                $stmt->execute([$tagName]);
                $tag = $stmt->fetch();
                
                if (!$tag) {
                    $stmt = $pdo->prepare("INSERT INTO tags (name) VALUES (?)");
                    $stmt->execute([$tagName]);
                    $tagId = $pdo->lastInsertId();
                } else {
                    $tagId = $tag['id'];
                }
                
                $stmt = $pdo->prepare("INSERT INTO bookmark_tags (bookmark_id, tag_id) VALUES (?, ?)");
                $stmt->execute([$bookmarkId, $tagId]);
            }
        }
        
        $message = $existing ? 'Bookmark updated' : 'Bookmark saved';
        echo json_encode(['success' => true, 'message' => $message]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error occurred']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'An error occurred']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

