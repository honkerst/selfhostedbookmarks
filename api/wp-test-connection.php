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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

header('Content-Type: application/json');
setApiNoCacheHeaders();

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$csrfToken = $data['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token']);
    exit;
}

// Use credentials from request body (settings form) when provided, otherwise database
$fromForm = isset($data['wp_base_url'], $data['wp_user'], $data['wp_app_password']);
if ($fromForm) {
    $wpBase = rtrim(trim((string)$data['wp_base_url']), '/');
    $wpUser = trim((string)$data['wp_user']);
    $wpPassword = normalizeWpAppPassword((string)$data['wp_app_password']);
} else {
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

    $wpBase = rtrim($wpSettings['wp_base_url'] ?? '', '/');
    $wpUser = trim($wpSettings['wp_user'] ?? '');
    $wpPassword = normalizeWpAppPassword($wpSettings['wp_app_password'] ?? '');
}

if ($wpBase === '' || $wpUser === '' || $wpPassword === '') {
    http_response_code(400);
    echo json_encode(['error' => 'WordPress URL, username, and application password are required']);
    exit;
}

$testUrl = $wpBase . '/wp-json/wp/v2/users/me';
$authHeader = 'Authorization: Basic ' . base64_encode($wpUser . ':' . $wpPassword);

$ch = curl_init($testUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => [$authHeader],
    CURLOPT_USERAGENT => 'SHB-WordPress-Test/1.0',
    CURLOPT_UNRESTRICTED_AUTH => true,
]);
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($body === false || $status >= 400) {
    $error = "Connection failed (HTTP $status): " . ($err ?: 'Unknown error');
    if ($status === 401) {
        $error .= '. Check the WordPress username and application password. Use the login username (not email, unless that is your login). Paste the app password exactly as shown in WordPress.';
    }
    echo json_encode([
        'success' => false,
        'error' => $error
    ]);
    exit;
}

$response = json_decode($body, true);
if (isset($response['id']) && isset($response['name'])) {
    try {
        if ($fromForm) {
            $saveKeys = [
                'wp_base_url' => $wpBase,
                'wp_user' => $wpUser,
                'wp_app_password' => $wpPassword,
            ];
            $stmt = $pdo->prepare("
                INSERT INTO settings (key, value, updated_at)
                VALUES (?, ?, datetime('now'))
                ON CONFLICT(key) DO UPDATE SET
                    value = excluded.value,
                    updated_at = datetime('now')
            ");
            foreach ($saveKeys as $key => $value) {
                $stmt->execute([$key, $value]);
            }
        }

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
        'message' => "Connected successfully as {$response['name']} (ID: {$response['id']})"
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Unexpected response from WordPress'
    ]);
}
