<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Test connection requires authentication
if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

// Get WordPress settings from database
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

// Check if all required settings are present
if (empty($wpSettings['wp_base_url']) || empty($wpSettings['wp_user']) || empty($wpSettings['wp_app_password'])) {
    http_response_code(400);
    echo json_encode(['error' => 'WordPress settings are not configured']);
    exit;
}

// Test WordPress connection
$wpBase = rtrim($wpSettings['wp_base_url'], '/');
$wpUser = $wpSettings['wp_user'];
$wpPassword = $wpSettings['wp_app_password'];

$testUrl = $wpBase . '/wp-json/wp/v2/users/me';
$authHeader = 'Authorization: Basic ' . base64_encode($wpUser . ':' . $wpPassword);

$ch = curl_init($testUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => [$authHeader],
    CURLOPT_USERAGENT => 'SHB-WordPress-Test/1.0',
]);
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($body === false || $status >= 400) {
    echo json_encode([
        'success' => false,
        'error' => "Connection failed (HTTP $status): " . ($err ?: 'Unknown error')
    ]);
    exit;
}

$data = json_decode($body, true);
if (isset($data['id']) && isset($data['name'])) {
    // Mark connection as tested in database
    try {
        $stmt = $pdo->prepare("
            INSERT INTO settings (key, value, updated_at)
            VALUES ('wp_connection_tested', '1', datetime('now'))
            ON CONFLICT(key) DO UPDATE SET
                value = '1',
                updated_at = datetime('now')
        ");
        $stmt->execute();
    } catch (PDOException $e) {
        // Continue even if database update fails
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Connected successfully as {$data['name']} (ID: {$data['id']})"
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Unexpected response from WordPress'
    ]);
}

