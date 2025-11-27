<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

/**
 * Check if user is authenticated
 */
function isAuthenticated() {
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

/**
 * Require authentication - redirect to login if not authenticated
 */
function requireAuth() {
    if (!isAuthenticated()) {
        if (strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
            // API endpoint - return JSON error
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        } else {
            // Web page - redirect to login
            header('Location: /login.php');
            exit;
        }
    }
}

/**
 * Login user with password
 */
function login($password) {
    global $pdo;
    
    // Check rate limit (5 attempts per 15 minutes)
    if (checkRateLimit(5, 900)) {
        recordLoginAttempt(false);
        return false;
    }
    
    if (password_verify($password, PASSWORD_HASH)) {
        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['login_time'] = time();
        recordLoginAttempt(true);
        return true;
    }
    
    recordLoginAttempt(false);
    return false;
}

/**
 * Logout user
 */
function logout() {
    $_SESSION = [];
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    session_destroy();
}

/**
 * Get current user ID (always 1 for single user)
 */
function getCurrentUserId() {
    return 1; // Single user system
}

