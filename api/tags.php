<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

// Tags API is public, but filters out tags from private bookmarks for non-authenticated users
$isAuthenticated = isAuthenticated();

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $query = $_GET['q'] ?? '';
        $all = isset($_GET['all']) && $_GET['all'] === '1';
        
        try {
            if (!empty($query)) {
                // Autocomplete - find tags matching query
                if ($isAuthenticated) {
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT t.name, COUNT(bt.bookmark_id) as count
                        FROM tags t
                        LEFT JOIN bookmark_tags bt ON t.id = bt.tag_id
                        WHERE t.name LIKE ?
                        GROUP BY t.id, t.name
                        HAVING COUNT(bt.bookmark_id) > 0
                        ORDER BY count DESC, t.name ASC
                        LIMIT 10
                    ");
                    $stmt->execute(['%' . $query . '%']);
                } else {
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT t.name, COUNT(bt.bookmark_id) as count
                        FROM tags t
                        LEFT JOIN bookmark_tags bt ON t.id = bt.tag_id
                        LEFT JOIN bookmarks b ON bt.bookmark_id = b.id
                        WHERE t.name LIKE ? AND (b.is_private = 0 OR b.is_private IS NULL)
                        GROUP BY t.id, t.name
                        HAVING COUNT(bt.bookmark_id) > 0
                        ORDER BY count DESC, t.name ASC
                        LIMIT 10
                    ");
                    $stmt->execute(['%' . $query . '%']);
                }
            } else {
                // Get all tags with counts
                // Get tag threshold from settings (default to 0 to show all tags)
                // If 'all' parameter is set, bypass threshold (for management page)
                if ($all) {
                    $threshold = 0; // Show all tags regardless of threshold
                } else {
                    $thresholdStmt = $pdo->prepare("SELECT value FROM settings WHERE key = 'tag_threshold'");
                    $thresholdStmt->execute();
                    $thresholdRow = $thresholdStmt->fetch();
                    // If threshold is not set, default to 0 (show all tags)
                    // Otherwise use the value from settings, ensuring it's at least 0
                    if ($thresholdRow && $thresholdRow['value'] !== null && $thresholdRow['value'] !== '') {
                        $threshold = max(0, (int)$thresholdRow['value']);
                    } else {
                        $threshold = 0; // Default to 0 to show all tags
                    }
                }
                
                if ($isAuthenticated) {
                    // For authenticated users, count all bookmarks
                    // SQLite PDO has issues with parameter binding in HAVING clauses, so we filter in PHP
                    $sql = "
                        SELECT t.name, COUNT(bt.bookmark_id) as count
                        FROM tags t
                        INNER JOIN bookmark_tags bt ON t.id = bt.tag_id
                        GROUP BY t.id, t.name
                        ORDER BY count DESC, t.name ASC
                    ";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute();
                } else {
                    // For non-authenticated users, only count public bookmarks
                    // SQLite PDO has issues with parameter binding in HAVING clauses, so we filter in PHP
                    $sql = "
                        SELECT t.name, COUNT(bt.bookmark_id) as count
                        FROM tags t
                        INNER JOIN bookmark_tags bt ON t.id = bt.tag_id
                        INNER JOIN bookmarks b ON bt.bookmark_id = b.id
                        WHERE b.is_private = 0
                        GROUP BY t.id, t.name
                        ORDER BY count DESC, t.name ASC
                    ";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute();
                }
            }
            
            $tags = $stmt->fetchAll();
            
            // Convert count to integer
            $tags = array_map(function($tag) {
                $tag['count'] = (int)$tag['count'];
                return $tag;
            }, $tags);
            
            // Filter by threshold in PHP (SQLite PDO has issues with parameter binding in HAVING clauses)
            $tags = array_filter($tags, function($tag) use ($threshold) {
                return isset($tag['count']) && $tag['count'] >= $threshold && $tag['count'] > 0;
            });
            
            // Re-index array after filtering
            $tags = array_values($tags);
            echo json_encode(['tags' => $tags]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error occurred']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'An error occurred']);
        }
        break;
        
    case 'DELETE':
        // Delete tag requires authentication
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
            $tagName = $data['name'] ?? '';
            
            if (empty($tagName)) {
                http_response_code(400);
                echo json_encode(['error' => 'Tag name is required']);
                exit;
            }
            
            // Get tag ID
            $stmt = $pdo->prepare("SELECT id FROM tags WHERE name = ?");
            $stmt->execute([$tagName]);
            $tag = $stmt->fetch();
            
            if (!$tag) {
                http_response_code(404);
                echo json_encode(['error' => 'Tag not found']);
                exit;
            }
            
            // Delete the tag (CASCADE will automatically remove bookmark_tags associations)
            $stmt = $pdo->prepare("DELETE FROM tags WHERE id = ?");
            $stmt->execute([$tag['id']]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Tag deleted successfully'
            ]);
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

