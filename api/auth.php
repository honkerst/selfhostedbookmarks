<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        $action = $_POST['action'] ?? '';
        
        // CSRF protection for logout (login doesn't need it, logout does)
        if ($action === 'logout') {
            $csrfToken = $_POST['csrf_token'] ?? '';
            if (!verifyCSRFToken($csrfToken)) {
                http_response_code(403);
                echo json_encode(['error' => 'Invalid security token']);
                exit;
            }
        }
        
        if ($action === 'login') {
            $password = $_POST['password'] ?? '';
            
            if (empty($password)) {
                http_response_code(400);
                echo json_encode(['error' => 'Password is required']);
                exit;
            }
            
            if (login($password)) {
                echo json_encode(['success' => true, 'message' => 'Login successful']);
            } else {
                http_response_code(401);
                echo json_encode(['error' => 'Invalid password']);
            }
        } elseif ($action === 'logout') {
            logout();
            echo json_encode(['success' => true, 'message' => 'Logged out']);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }
        break;
        
    case 'GET':
        // Check authentication status
        echo json_encode(['authenticated' => isAuthenticated()]);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

