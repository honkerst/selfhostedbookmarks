<?php
/**
 * Utility functions
 */

/**
 * Escape output for HTML display
 */
function h($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Format date for display (UK format: 28 Dec 2005)
 */
function formatDate($datetime) {
    if (!$datetime) return '';
    $date = new DateTime($datetime);
    return $date->format('j M Y');
}

/**
 * Truncate text to specified length
 */
function truncate($text, $length = 100) {
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . '...';
}

/**
 * Parse tags from comma-separated string
 */
function parseTags($tagString) {
    if (empty($tagString)) {
        return [];
    }
    $tags = explode(',', $tagString);
    $tags = array_map('trim', $tags);
    $tags = array_filter($tags);
    $tags = array_map('strtolower', $tags);
    return array_unique($tags);
}

/**
 * Format tags array for display
 */
function formatTags($tags) {
    if (empty($tags)) {
        return [];
    }
    if (is_string($tags)) {
        return parseTags($tags);
    }
    return $tags;
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Validate URL
 */
function validateUrl($url) {
    if (empty($url)) {
        return false;
    }
    
    // Check length (URLs should be max 2048 characters)
    if (strlen($url) > 2048) {
        return false;
    }
    
    // Use filter_var to validate URL
    $filtered = filter_var($url, FILTER_VALIDATE_URL);
    if ($filtered === false) {
        return false;
    }
    
    // Ensure it's http or https
    $scheme = parse_url($filtered, PHP_URL_SCHEME);
    if (!in_array($scheme, ['http', 'https'])) {
        return false;
    }
    
    return true;
}

/**
 * Validate and sanitize input length
 */
function validateLength($value, $maxLength, $fieldName = 'Field') {
    if (strlen($value) > $maxLength) {
        throw new InvalidArgumentException("$fieldName exceeds maximum length of $maxLength characters");
    }
    return $value;
}

/**
 * Get client IP address (works behind Cloudflare proxy)
 */
function getClientIp() {
    // Check for Cloudflare header first
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    // Check for standard proxy header
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    // Fallback to REMOTE_ADDR
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Check rate limit for login attempts
 * Returns true if rate limit exceeded, false otherwise
 */
function checkRateLimit($maxAttempts = 5, $timeWindow = 900) {
    global $pdo;
    
    $ip = getClientIp();
    $cutoffTime = date('Y-m-d H:i:s', time() - $timeWindow);
    
    // Count failed attempts in the time window
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM login_attempts 
        WHERE ip_address = ? 
        AND attempt_time > ? 
        AND success = 0
    ");
    $stmt->execute([$ip, $cutoffTime]);
    $result = $stmt->fetch();
    
    return (int)$result['count'] >= $maxAttempts;
}

/**
 * Record login attempt
 */
function recordLoginAttempt($success = false) {
    global $pdo;
    
    $ip = getClientIp();
    
    $stmt = $pdo->prepare("
        INSERT INTO login_attempts (ip_address, success, attempt_time)
        VALUES (?, ?, datetime('now'))
    ");
    $stmt->execute([$ip, $success ? 1 : 0]);
    
    // Clean up old attempts (older than 24 hours)
    $cutoffTime = date('Y-m-d H:i:s', time() - 86400);
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE attempt_time < ?");
    $stmt->execute([$cutoffTime]);
}

