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
                if ($isAuthenticated) {
                    $stmt = $pdo->query("
                        SELECT t.name, COUNT(bt.bookmark_id) as count
                        FROM tags t
                        LEFT JOIN bookmark_tags bt ON t.id = bt.tag_id
                        GROUP BY t.id, t.name
                        HAVING COUNT(bt.bookmark_id) > 0
                        ORDER BY count DESC, t.name ASC
                    ");
                } else {
                    $stmt = $pdo->query("
                        SELECT t.name, COUNT(bt.bookmark_id) as count
                        FROM tags t
                        LEFT JOIN bookmark_tags bt ON t.id = bt.tag_id
                        LEFT JOIN bookmarks b ON bt.bookmark_id = b.id
                        WHERE b.is_private = 0 OR b.is_private IS NULL
                        GROUP BY t.id, t.name
                        HAVING COUNT(bt.bookmark_id) > 0
                        ORDER BY count DESC, t.name ASC
                    ");
                }
            }
            
            $tags = $stmt->fetchAll();
            
            // Filter out any tags with count 0 (as a safety measure)
            $tags = array_filter($tags, function($tag) {
                return isset($tag['count']) && (int)$tag['count'] > 0;
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
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

