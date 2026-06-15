<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$isAuthenticated = isAuthenticated();

header('Content-Type: application/json');
setApiNoCacheHeaders();

$method = $_SERVER['REQUEST_METHOD'];

/**
 * Build public UI settings from raw database rows (excludes secrets)
 */
function buildPublicSettings(array $rows): array {
    $booleanKeys = ['tags_alphabetical', 'show_url', 'show_datetime'];
    $stringKeys = ['pagination_per_page', 'tag_threshold'];

    $settings = [];
    foreach ($rows as $row) {
        if (in_array($row['key'], $booleanKeys, true)) {
            $settings[$row['key']] = $row['value'] === '1' || $row['value'] === 'true';
        } elseif (in_array($row['key'], $stringKeys, true)) {
            $settings[$row['key']] = (string)$row['value'];
        }
    }

    $defaults = [
        'tags_alphabetical' => false,
        'show_url' => true,
        'show_datetime' => false,
        'pagination_per_page' => '20',
        'tag_threshold' => '2',
    ];

    foreach ($defaults as $key => $defaultValue) {
        if (!isset($settings[$key])) {
            $settings[$key] = $defaultValue;
        }
    }

    return $settings;
}

/**
 * Whether WordPress publish is ready (credentials present and connection tested)
 */
function isWordPressConfigured(array $rows): bool {
    $values = [];
    foreach ($rows as $row) {
        $values[$row['key']] = $row['value'];
    }

    $hasCredentials = !empty($values['wp_base_url'])
        && !empty($values['wp_user'])
        && !empty($values['wp_app_password']);

    $connectionTested = ($values['wp_connection_tested'] ?? '0') === '1';

    return $hasCredentials && $connectionTested;
}

switch ($method) {
    case 'GET':
        try {
            $stmt = $pdo->query("SELECT key, value FROM settings");
            $rows = $stmt->fetchAll();

            $settings = buildPublicSettings($rows);

            if ($isAuthenticated) {
                $settings['wp_configured'] = isWordPressConfigured($rows);
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
        if (!$isAuthenticated) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

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

            $validKeys = ['tags_alphabetical', 'show_url', 'show_datetime', 'pagination_per_page', 'tag_threshold'];

            foreach ($data['settings'] as $key => $value) {
                if (!in_array($key, $validKeys)) {
                    continue;
                }

                if ($key === 'pagination_per_page') {
                    $validPaginationValues = ['1', '5', '10', '20', '50', '100', '250', '500', '1000', 'unlimited'];
                    if (!in_array($value, $validPaginationValues)) {
                        continue;
                    }
                    $dbValue = $value;
                } elseif ($key === 'tag_threshold') {
                    $dbValue = (string)max(0, (int)$value);
                } else {
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

            $stmt = $pdo->query("SELECT key, value FROM settings");
            $rows = $stmt->fetchAll();
            $settings = buildPublicSettings($rows);
            $settings['wp_configured'] = isWordPressConfigured($rows);

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
