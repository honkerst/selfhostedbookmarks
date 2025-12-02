<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Import requires authentication
requireAuth();

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

/**
 * Get or create tag ID
 */
function getOrCreateTag($name) {
    global $pdo;
    $name = strtolower(trim($name));
    
    if (empty($name)) {
        return null;
    }
    
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
            $tagId = getOrCreateTag($tagName);
            if ($tagId) {
                $tagIds[] = $tagId;
            }
        }
        
        if (!empty($tagIds)) {
            $stmt = $pdo->prepare("INSERT INTO bookmark_tags (bookmark_id, tag_id) VALUES (?, ?)");
            foreach ($tagIds as $tagId) {
                $stmt->execute([$bookmarkId, $tagId]);
            }
        }
    }
}

/**
 * Parse Netscape-style bookmarks HTML
 */
function parseNetscapeBookmarks($html, $additionalTags = []) {
    $bookmarks = [];
    
    // Load HTML into DOMDocument
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    
    // Find all <A> tags (bookmarks)
    $xpath = new DOMXPath($dom);
    $links = $xpath->query('//a[@href]');
    
    foreach ($links as $link) {
        $url = $link->getAttribute('href');
        $title = trim($link->textContent);
        $addDate = $link->getAttribute('add_date');
        $icon = $link->getAttribute('icon');
        
        // Skip invalid URLs
        if (empty($url) || !validateUrl($url)) {
            continue;
        }
        
        // Extract description from next sibling if it's a <DD> tag
        $description = '';
        $nextSibling = $link->nextSibling;
        while ($nextSibling) {
            if ($nextSibling->nodeType === XML_ELEMENT_NODE && $nextSibling->nodeName === 'DD') {
                $description = trim($nextSibling->textContent);
                break;
            }
            $nextSibling = $nextSibling->nextSibling;
        }
        
        // Extract tags from parent folders (folder names can be used as tags)
        // Find all H3 elements (folders) that are ancestors or siblings of this bookmark
        $tags = [];
        $current = $link;
        
        // Navigate up the tree to find folder structure
        while ($current) {
            // Check if current node or its siblings contain an H3 (folder)
            $parent = $current->parentNode;
            if ($parent) {
                // Look for H3 in parent's children (siblings of current)
                foreach ($parent->childNodes as $child) {
                    if ($child->nodeType === XML_ELEMENT_NODE) {
                        // Check if this child is an H3
                        if ($child->nodeName === 'H3') {
                            $folderName = trim($child->textContent);
                            if (!empty($folderName) && 
                                $folderName !== 'Bookmarks Bar' && 
                                $folderName !== 'Bookmarks' &&
                                $folderName !== 'Bookmarks Menu') {
                                // Normalize folder name as tag
                                $tagName = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $folderName));
                                $tagName = trim($tagName, '_');
                                if (!empty($tagName) && !in_array($tagName, $tags)) {
                                    $tags[] = $tagName;
                                }
                            }
                        }
                        // Check if this child is a DT containing an H3
                        if ($child->nodeName === 'DT') {
                            $h3 = $child->getElementsByTagName('h3')->item(0);
                            if ($h3) {
                                $folderName = trim($h3->textContent);
                                if (!empty($folderName) && 
                                    $folderName !== 'Bookmarks Bar' && 
                                    $folderName !== 'Bookmarks' &&
                                    $folderName !== 'Bookmarks Menu') {
                                    $tagName = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $folderName));
                                    $tagName = trim($tagName, '_');
                                    if (!empty($tagName) && !in_array($tagName, $tags)) {
                                        $tags[] = $tagName;
                                    }
                                }
                            }
                        }
                    }
                }
            }
            $current = $parent;
        }
        
        // Add additional tags
        if (!empty($additionalTags)) {
            $additionalTagsArray = is_array($additionalTags) ? $additionalTags : parseTags($additionalTags);
            $tags = array_merge($tags, $additionalTagsArray);
        }
        
        // Remove duplicates and normalize
        $tags = array_unique(array_map('strtolower', array_map('trim', $tags)));
        $tags = array_filter($tags);
        
        $bookmarks[] = [
            'url' => $url,
            'title' => $title ?: null,
            'description' => $description ?: null,
            'tags' => array_values($tags),
            'add_date' => $addDate
        ];
    }
    
    return $bookmarks;
}

switch ($method) {
    case 'GET':
        // Get import history
        try {
            $stmt = $pdo->query("
                SELECT id, filename, bookmark_ids, created_count, updated_count, additional_tags, created_at
                FROM imports
                ORDER BY created_at DESC
            ");
            $imports = $stmt->fetchAll();
            
            // Decode bookmark_ids JSON
            foreach ($imports as &$import) {
                $import['bookmark_ids'] = json_decode($import['bookmark_ids'], true) ?: [];
                $import['bookmark_count'] = count($import['bookmark_ids']);
            }
            
            echo json_encode(['imports' => $imports]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error occurred']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'An error occurred']);
        }
        break;
        
    case 'POST':
        // Verify CSRF token
        $data = json_decode(file_get_contents('php://input'), true);
        $csrfToken = $data['csrf_token'] ?? '';
        if (!verifyCSRFToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid security token']);
            exit;
        }
        
        try {
            $html = $data['html'] ?? '';
            $additionalTags = $data['additional_tags'] ?? [];
            $filename = $data['filename'] ?? null;
            
            if (empty($html)) {
                http_response_code(400);
                echo json_encode(['error' => 'Bookmarks HTML is required']);
                exit;
            }
            
            // Parse bookmarks
            $parsedBookmarks = parseNetscapeBookmarks($html, $additionalTags);
            
            if (empty($parsedBookmarks)) {
                http_response_code(400);
                echo json_encode(['error' => 'No valid bookmarks found in file']);
                exit;
            }
            
            $importedIds = [];
            $updatedCount = 0;
            $createdCount = 0;
            $errors = [];
            
            // Start transaction
            $pdo->beginTransaction();
            
            try {
                foreach ($parsedBookmarks as $bookmark) {
                    $url = $bookmark['url'];
                    $title = $bookmark['title'];
                    $description = $bookmark['description'];
                    $tags = $bookmark['tags'];
                    
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
                        $errors[] = "Skipped bookmark: {$e->getMessage()}";
                        continue;
                    }
                    
                    // Check if bookmark already exists by URL
                    $stmt = $pdo->prepare("SELECT id FROM bookmarks WHERE url = ?");
                    $stmt->execute([$url]);
                    $existing = $stmt->fetch();
                    
                    if ($existing) {
                        // Update existing bookmark - only update if new values are provided
                        // If title/description are null or empty, keep existing values
                        if ($title !== '' && $title !== null) {
                            $stmt = $pdo->prepare("UPDATE bookmarks SET title = ?, updated_at = datetime('now') WHERE id = ?");
                            $stmt->execute([$title, $existing['id']]);
                        }
                        if ($description !== '' && $description !== null) {
                            $stmt = $pdo->prepare("UPDATE bookmarks SET description = ?, updated_at = datetime('now') WHERE id = ?");
                            $stmt->execute([$description, $existing['id']]);
                        }
                        // Always update the updated_at timestamp
                        if (($title === '' || $title === null) && ($description === '' || $description === null)) {
                            $stmt = $pdo->prepare("UPDATE bookmarks SET updated_at = datetime('now') WHERE id = ?");
                            $stmt->execute([$existing['id']]);
                        }
                        $bookmarkId = $existing['id'];
                        $updatedCount++;
                    } else {
                        // Insert new bookmark
                        $stmt = $pdo->prepare("
                            INSERT INTO bookmarks (url, title, description, is_private, created_at, updated_at)
                            VALUES (?, ?, ?, 0, datetime('now'), datetime('now'))
                        ");
                        $stmt->execute([$url, $title, $description]);
                        $bookmarkId = $pdo->lastInsertId();
                        $createdCount++;
                    }
                    
                    // Update tags (merge with existing tags if updating)
                    if ($existing) {
                        // Get existing tags
                        $stmt = $pdo->prepare("
                            SELECT t.name 
                            FROM tags t
                            JOIN bookmark_tags bt ON t.id = bt.tag_id
                            WHERE bt.bookmark_id = ?
                        ");
                        $stmt->execute([$bookmarkId]);
                        $existingTags = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        // Merge tags (existing + new)
                        $allTags = array_unique(array_merge($existingTags, $tags));
                    } else {
                        $allTags = $tags;
                    }
                    
                    updateBookmarkTags($bookmarkId, $allTags);
                    $importedIds[] = $bookmarkId;
                }
                
                // Commit transaction
                $pdo->commit();
                
                // Store import history
                $additionalTagsStr = !empty($additionalTags) ? (is_array($additionalTags) ? implode(', ', $additionalTags) : $additionalTags) : null;
                
                $stmt = $pdo->prepare("
                    INSERT INTO imports (filename, bookmark_ids, created_count, updated_count, additional_tags, created_at)
                    VALUES (?, ?, ?, ?, ?, datetime('now'))
                ");
                $stmt->execute([
                    $filename,
                    json_encode($importedIds),
                    $createdCount,
                    $updatedCount,
                    $additionalTagsStr
                ]);
                $importId = $pdo->lastInsertId();
                
                echo json_encode([
                    'success' => true,
                    'message' => "Imported {$createdCount} new bookmarks, updated {$updatedCount} existing bookmarks",
                    'created' => $createdCount,
                    'updated' => $updatedCount,
                    'total' => count($importedIds),
                    'imported_ids' => $importedIds,
                    'import_id' => $importId,
                    'errors' => $errors
                ]);
            } catch (Exception $e) {
                // Rollback on error
                $pdo->rollBack();
                throw $e;
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error occurred']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'An error occurred: ' . $e->getMessage()]);
        }
        break;
        
    case 'DELETE':
        // Undo import - delete bookmarks by import ID or bookmark IDs
        // Verify CSRF token
        $data = json_decode(file_get_contents('php://input'), true);
        $csrfToken = $data['csrf_token'] ?? '';
        if (!verifyCSRFToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid security token']);
            exit;
        }
        
        try {
            $importId = $data['import_id'] ?? null;
            $bookmarkIds = $data['bookmark_ids'] ?? [];
            
            // If import_id is provided, get bookmark IDs from import record
            if ($importId) {
                $stmt = $pdo->prepare("SELECT bookmark_ids FROM imports WHERE id = ?");
                $stmt->execute([$importId]);
                $import = $stmt->fetch();
                
                if (!$import) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Import not found']);
                    exit;
                }
                
                $bookmarkIds = json_decode($import['bookmark_ids'], true) ?: [];
                
                // Delete the import record
                $stmt = $pdo->prepare("DELETE FROM imports WHERE id = ?");
                $stmt->execute([$importId]);
            }
            
            if (empty($bookmarkIds) || !is_array($bookmarkIds)) {
                http_response_code(400);
                echo json_encode(['error' => 'Bookmark IDs are required']);
                exit;
            }
            
            // Validate all IDs are integers
            $bookmarkIds = array_filter(array_map('intval', $bookmarkIds));
            
            if (empty($bookmarkIds)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid bookmark IDs']);
                exit;
            }
            
            // Delete bookmarks (CASCADE will handle tag associations)
            $placeholders = implode(',', array_fill(0, count($bookmarkIds), '?'));
            $stmt = $pdo->prepare("DELETE FROM bookmarks WHERE id IN ($placeholders)");
            $stmt->execute($bookmarkIds);
            
            $deletedCount = $stmt->rowCount();
            
            echo json_encode([
                'success' => true,
                'message' => "Deleted {$deletedCount} bookmarks",
                'deleted' => $deletedCount
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

