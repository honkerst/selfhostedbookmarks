<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Settings API requires authentication
$isAuthenticated = isAuthenticated();

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Public read - settings can be read by anyone
        try {
            $stmt = $pdo->query("SELECT key, value FROM settings");
            $rows = $stmt->fetchAll();
            
            $settings = [];
            foreach ($rows as $row) {
                // Handle pagination_per_page and tag_threshold as string, others as boolean
                if ($row['key'] === 'pagination_per_page' || $row['key'] === 'tag_threshold') {
                    $settings[$row['key']] = $row['value'];
                } else {
                    $settings[$row['key']] = $row['value'] === '1' || $row['value'] === 'true';
                }
            }
            
            // Set defaults if not in database
            $defaults = [
                'tags_alphabetical' => false,
                'show_url' => true,
                'show_datetime' => false,
                'pagination_per_page' => '20',
                'tag_threshold' => '2'
            ];
            
            foreach ($defaults as $key => $defaultValue) {
                if (!isset($settings[$key])) {
                    $settings[$key] = $defaultValue;
                }
            }
            
            echo json_encode(['settings' => $settings]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error occurred']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'An error occurred']);
        }
        break;
        
    case 'PUT':
        // Update settings requires authentication
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
            
            if (!isset($data['settings']) || !is_array($data['settings'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid settings data']);
                exit;
            }
            
            // Valid setting keys
            $validKeys = ['tags_alphabetical', 'show_url', 'show_datetime', 'pagination_per_page', 'tag_threshold'];
            
            foreach ($data['settings'] as $key => $value) {
                if (!in_array($key, $validKeys)) {
                    continue; // Skip invalid keys
                }
                
                // Handle pagination_per_page and tag_threshold as string, others as boolean
                if ($key === 'pagination_per_page') {
                    // Validate pagination value
                    $validPaginationValues = ['1', '5', '10', '20', '50', '100', '250', '500', '1000', 'unlimited'];
                    if (!in_array($value, $validPaginationValues)) {
                        continue; // Skip invalid pagination value
                    }
                    $dbValue = $value;
                } elseif ($key === 'tag_threshold') {
                    // Validate and ensure it's a non-negative integer
                    $dbValue = (string)max(0, (int)$value);
                } else {
                    // Convert boolean to string
                    $dbValue = ($value === true || $value === 'true' || $value === '1') ? '1' : '0';
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO settings (key, value, updated_at)
                    VALUES (?, ?, datetime('now'))
                    ON CONFLICT(key) DO UPDATE SET
                        value = excluded.value,
                        updated_at = datetime('now')
                ");
                $stmt->execute([$key, $dbValue]);
            }
            
            // Return updated settings
            $stmt = $pdo->query("SELECT key, value FROM settings");
            $rows = $stmt->fetchAll();
            
            $settings = [];
            foreach ($rows as $row) {
                // Handle pagination_per_page and tag_threshold as string, others as boolean
                if ($row['key'] === 'pagination_per_page' || $row['key'] === 'tag_threshold') {
                    $settings[$row['key']] = $row['value'];
                } else {
                    $settings[$row['key']] = $row['value'] === '1' || $row['value'] === 'true';
                }
            }
            
            // Include defaults for any missing settings
            $defaults = [
                'tags_alphabetical' => false,
                'show_url' => true,
                'show_datetime' => false,
                'pagination_per_page' => '20',
                'tag_threshold' => '2'
            ];
            
            foreach ($defaults as $key => $defaultValue) {
                if (!isset($settings[$key])) {
                    $settings[$key] = $defaultValue;
                }
            }
            
            echo json_encode(['settings' => $settings, 'message' => 'Settings updated']);
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

