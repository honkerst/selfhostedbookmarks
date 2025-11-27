<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Bookmarks API is public, but filters private bookmarks for non-authenticated users
$isAuthenticated = isAuthenticated();

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

/**
 * Get or create tag ID
 */
function getOrCreateTag($name) {
    global $pdo;
    $name = strtolower(trim($name));
    
    $stmt = $pdo->prepare("SELECT id FROM tags WHERE name = ?");
    $stmt->execute([$name]);
    $tag = $stmt->fetch();
    
    if ($tag) {
        return $tag['id'];
    }
    
    $stmt = $pdo->prepare("INSERT INTO tags (name) VALUES (?)");
    $stmt->execute([$name]);
    return $pdo->lastInsertId();
}

/**
 * Update bookmark tags
 */
function updateBookmarkTags($bookmarkId, $tags) {
    global $pdo;
    
    // Remove existing tags
    $stmt = $pdo->prepare("DELETE FROM bookmark_tags WHERE bookmark_id = ?");
    $stmt->execute([$bookmarkId]);
    
    // Add new tags
    if (!empty($tags)) {
        $tagIds = [];
        foreach ($tags as $tagName) {
            $tagIds[] = getOrCreateTag($tagName);
        }
        
        $stmt = $pdo->prepare("INSERT INTO bookmark_tags (bookmark_id, tag_id) VALUES (?, ?)");
        foreach ($tagIds as $tagId) {
            $stmt->execute([$bookmarkId, $tagId]);
        }
    }
}

switch ($method) {
    case 'GET':
        try {
            // Get pagination setting from database
            $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = 'pagination_per_page'");
            $stmt->execute();
            $paginationSetting = $stmt->fetch();
            $perPageSetting = $paginationSetting ? $paginationSetting['value'] : '20';
            
            $tagFilter = $_GET['tag'] ?? '';
            $search = $_GET['search'] ?? '';
            $isPrivate = isset($_GET['private']) ? (int)$_GET['private'] : null;
            $page = max(1, (int)($_GET['page'] ?? 1));
            
            // Handle "unlimited" - use a very large number
            if ($perPageSetting === 'unlimited') {
                $perPage = 999999;
            } else {
                $perPage = max(1, (int)$perPageSetting);
            }
            
            $offset = ($page - 1) * $perPage;
            
            // Build WHERE clause and params
            $where = [];
            $params = [];
            
            if (!empty($tagFilter)) {
                // When filtering by tag, we need to ensure the tag exists in the JOIN
                $where[] = "EXISTS (
                    SELECT 1 FROM bookmark_tags bt2 
                    JOIN tags t2 ON bt2.tag_id = t2.id 
                    WHERE bt2.bookmark_id = b.id AND t2.name = ?
                )";
                $params[] = $tagFilter;
            }
            
            if (!empty($search)) {
                $where[] = "(b.title LIKE ? OR b.description LIKE ? OR b.url LIKE ?)";
                $searchTerm = '%' . $search . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            // Filter out private bookmarks if user is not authenticated
            if (!$isAuthenticated) {
                $where[] = "b.is_private = 0";
            } elseif ($isPrivate !== null) {
                // If authenticated, allow filtering by private status if explicitly requested
                $where[] = "b.is_private = ?";
                $params[] = $isPrivate;
            }
            
            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            
            // Get bookmarks
            // Handle "unlimited" - don't use LIMIT
            if ($perPageSetting === 'unlimited') {
                $sql = "
                    SELECT b.*, 
                           GROUP_CONCAT(DISTINCT t.name) as tags
                    FROM bookmarks b
                    LEFT JOIN bookmark_tags bt ON b.id = bt.bookmark_id
                    LEFT JOIN tags t ON bt.tag_id = t.id
                    $whereClause
                    GROUP BY b.id
                    ORDER BY b.created_at DESC
                ";
                $queryParams = $params;
            } else {
                $sql = "
                    SELECT b.*, 
                           GROUP_CONCAT(DISTINCT t.name) as tags
                    FROM bookmarks b
                    LEFT JOIN bookmark_tags bt ON b.id = bt.bookmark_id
                    LEFT JOIN tags t ON bt.tag_id = t.id
                    $whereClause
                    GROUP BY b.id
                    ORDER BY b.created_at DESC
                    LIMIT ? OFFSET ?
                ";
                $queryParams = array_merge($params, [$perPage, $offset]);
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($queryParams);
            $bookmarks = $stmt->fetchAll();
            
            // Format tags
            foreach ($bookmarks as &$bookmark) {
                $bookmark['tags'] = $bookmark['tags'] ? explode(',', $bookmark['tags']) : [];
                $bookmark['is_private'] = (bool)$bookmark['is_private'];
            }
            
            // Get total count
            $countSql = "
                SELECT COUNT(DISTINCT b.id) as total
                FROM bookmarks b
                $whereClause
            ";
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $total = $countStmt->fetch()['total'];
            
            // Calculate pages (for unlimited, show 1 page)
            if ($perPageSetting === 'unlimited') {
                $totalPages = 1;
            } else {
                $totalPages = ceil($total / $perPage);
            }
            
            echo json_encode([
                'bookmarks' => $bookmarks,
                'pagination' => [
                    'page' => $page,
                    'perPage' => $perPageSetting === 'unlimited' ? 'unlimited' : $perPage,
                    'total' => $total,
                    'pages' => $totalPages
                ]
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error occurred']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'An error occurred']);
        }
        break;
        
    case 'POST':
        // Create bookmark requires authentication
        if (!$isAuthenticated) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        
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
            $isPrivate = isset($data['is_private']) ? (int)$data['is_private'] : 0;
            $tags = $data['tags'] ?? [];
            
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
            
            // Check if bookmark already exists by URL
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
                // Insert new bookmark
                $stmt = $pdo->prepare("
                    INSERT INTO bookmarks (url, title, description, is_private, created_at, updated_at)
                    VALUES (?, ?, ?, ?, datetime('now'), datetime('now'))
                ");
                $stmt->execute([$url, $title, $description, $isPrivate]);
                $bookmarkId = $pdo->lastInsertId();
            }
            
            // Update tags (always update, even if empty)
            $tags = is_array($tags) ? $tags : parseTags($tags);
            updateBookmarkTags($bookmarkId, $tags);
            
            // Fetch created bookmark with tags
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
            $bookmark['tags'] = $bookmark['tags'] ? explode(',', $bookmark['tags']) : [];
            $bookmark['is_private'] = (bool)$bookmark['is_private'];
            
            http_response_code(201);
            echo json_encode(['bookmark' => $bookmark, 'message' => 'Bookmark created']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error occurred']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'An error occurred']);
        }
        break;
        
    case 'PUT':
        // Update bookmark requires authentication
        if (!$isAuthenticated) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        
        // Verify CSRF token
        $data = json_decode(file_get_contents('php://input'), true);
        $csrfToken = $data['csrf_token'] ?? '';
        if (!verifyCSRFToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid security token']);
            exit;
        }
        
        try {
            $id = $data['id'] ?? 0;
            
            if (empty($id)) {
                http_response_code(400);
                echo json_encode(['error' => 'Bookmark ID is required']);
                exit;
            }
            
            $url = $data['url'] ?? '';
            $title = $data['title'] ?? '';
            $description = $data['description'] ?? '';
            $isPrivate = isset($data['is_private']) ? (int)$data['is_private'] : 0;
            $tags = $data['tags'] ?? [];
            
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
            
            // Update bookmark
            $stmt = $pdo->prepare("
                UPDATE bookmarks
                SET url = ?, title = ?, description = ?, is_private = ?, updated_at = datetime('now')
                WHERE id = ?
            ");
            $stmt->execute([$url, $title, $description, $isPrivate, $id]);
            
            // Update tags
            $tags = is_array($tags) ? $tags : parseTags($tags);
            updateBookmarkTags($id, $tags);
            
            // Fetch updated bookmark with tags
            $stmt = $pdo->prepare("
                SELECT b.*, GROUP_CONCAT(DISTINCT t.name) as tags
                FROM bookmarks b
                LEFT JOIN bookmark_tags bt ON b.id = bt.bookmark_id
                LEFT JOIN tags t ON bt.tag_id = t.id
                WHERE b.id = ?
                GROUP BY b.id
            ");
            $stmt->execute([$id]);
            $bookmark = $stmt->fetch();
            $bookmark['tags'] = $bookmark['tags'] ? explode(',', $bookmark['tags']) : [];
            $bookmark['is_private'] = (bool)$bookmark['is_private'];
            
            echo json_encode(['bookmark' => $bookmark, 'message' => 'Bookmark updated']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error occurred']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'An error occurred']);
        }
        break;
        
    case 'DELETE':
        // Delete bookmark requires authentication
        if (!$isAuthenticated) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        
        // Verify CSRF token
        $csrfToken = $_GET['csrf_token'] ?? '';
        if (!verifyCSRFToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid security token']);
            exit;
        }
        
        try {
            $id = $_GET['id'] ?? 0;
            
            if (empty($id)) {
                http_response_code(400);
                echo json_encode(['error' => 'Bookmark ID is required']);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM bookmarks WHERE id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['message' => 'Bookmark deleted']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error occurred']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'An error occurred']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

