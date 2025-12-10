#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Sync newest SHB bookmark with a given tag to WordPress via REST API.
 *
 * Reads settings from the SHB database settings table (set in Settings page),
 * with environment variables as optional overrides:
 *   SHB_BASE_URL, SHB_TAG, WP_BASE_URL, WP_USER, WP_APP_PASSWORD, WP_TAGS, WP_CATEGORIES, SHB_WP_STATE_FILE
 * Defaults remain: base SHB https://bookmarks.thoughton.co.uk, tag=thc, WP_BASE=https://thoughton.co.uk,
 * WP_TAGS=interesting,thc,shb, WP_CATEGORIES=Interesting stuff.
 */

require_once __DIR__ . '/../includes/config.php';

$dbSettings = loadWpSyncSettings();
$config = mergeConfigWithEnvAndDefaults($dbSettings);

if ($config['wp_app_password'] === '') {
    fwrite(STDERR, "WP_APP_PASSWORD is required (WordPress application password).\n");
    exit(1);
}

if (!is_dir(dirname($config['state_file']))) {
    mkdir(dirname($config['state_file']), 0755, true);
}

$debug = getenv('DEBUG') === '1';

try {
    $lastId = is_file($config['state_file']) ? (int)file_get_contents($config['state_file']) : 0;

    $bookmarks = fetchBookmarksWithTag($config['shb_base'], $config['tag']);
    if (empty($bookmarks)) {
        logInfo("No bookmarks found for tag '{$config['tag']}'.");
        exit(0);
    }

    $processed = 0;
    $skipped = 0;

    foreach ($bookmarks as $bookmark) {
        // Stop if we've reached a bookmark we've already processed
        if ((int)$bookmark['id'] === $lastId) {
            if ($processed === 0 && $skipped === 0) {
                logInfo("No new bookmark (last id {$lastId}).");
            }
            break;
        }

        // Check if this URL already exists in WordPress
        if (urlExistsInWordPress($config, $bookmark['url'], $debug)) {
            logInfo("Bookmark URL already exists in WordPress, skipping id {$bookmark['id']}.");
            file_put_contents($config['state_file'], (string)$bookmark['id']);
            $skipped++;
            continue;
        }

        // Post the bookmark
        logInfo("Posting bookmark id {$bookmark['id']} titled '{$bookmark['title']}'");
        if ($debug) {
            logInfo("Bookmark payload: " . json_encode($bookmark, JSON_PRETTY_PRINT));
        }

        postToWordPress($config, $bookmark, $debug);
        file_put_contents($config['state_file'], (string)$bookmark['id']);
        logInfo("Synced bookmark id {$bookmark['id']} to WordPress.");
        $processed++;
    }

    if ($processed > 0 || $skipped > 0) {
        logInfo("Processed {$processed} new bookmark(s), skipped {$skipped} existing bookmark(s).");
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * Fetch all bookmarks with a tag from SHB (ordered newest first).
 * Fetches the first page - if pagination is set to unlimited, gets all bookmarks.
 */
function fetchBookmarksWithTag(string $base, string $tag): array
{
    $url = rtrim($base, '/') . '/api/bookmarks.php?tag=' . rawurlencode($tag);
    $resp = httpGetJson($url);
    if ($resp === null || empty($resp['bookmarks'])) {
        return [];
    }
    // Extra safety: filter by tag client-side (case-insensitive) in case server-side
    // filtering changes or fails.
    $tagLower = strtolower($tag);
    $matches = array_values(array_filter($resp['bookmarks'], function ($b) use ($tagLower) {
        if (!isset($b['tags']) || !is_array($b['tags'])) {
            return false;
        }
        foreach ($b['tags'] as $t) {
            if (strtolower($t) === $tagLower) {
                return true;
            }
        }
        return false;
    }));
    // Bookmarks are already ordered newest-first by API
    return $matches;
}

/**
 * Check if a URL already exists in WordPress posts.
 */
function urlExistsInWordPress(array $config, string $url, bool $debug = false): bool
{
    $authHeader = 'Authorization: Basic ' . base64_encode($config['wp_user'] . ':' . $config['wp_app_password']);
    $searchUrl = rtrim($config['wp_base'], '/') . '/wp-json/wp/v2/posts?search=' . rawurlencode($url) . '&per_page=100';

    try {
        $resp = httpGetJson($searchUrl, [$authHeader]);
        if ($resp === null || empty($resp)) {
            return false;
        }

        // Check if any post content contains the URL
        foreach ($resp as $post) {
            if (isset($post['content']['rendered']) && strpos($post['content']['rendered'], $url) !== false) {
                if ($debug) {
                    logInfo("Found existing WordPress post #{$post['id']} with URL: {$url}");
                }
                return true;
            }
        }

        return false;
    } catch (Exception $e) {
        if ($debug) {
            logInfo("Error checking for existing URL: " . $e->getMessage());
        }
        // If check fails, assume it doesn't exist (safer to post than skip)
        return false;
    }
}

/**
 * Publish bookmark to WordPress.
 */
function postToWordPress(array $config, array $bookmark, bool $debug = false): void
{
    $wpUrl = rtrim($config['wp_base'], '/') . '/wp-json/wp/v2/posts';

    $title = $bookmark['title'] ?: $bookmark['url'];
    $desc  = trim((string)($bookmark['description'] ?? ''));
    if (strtolower($desc) === 'uncategorized') {
        $desc = '';
    }

    $contentParts = [];
    if ($desc !== '') {
        $contentParts[] = '<p>' . htmlspecialchars($desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }
    $contentParts[] = '<p><a href="' . htmlspecialchars($bookmark['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Link</a></p>';
    $content = implode("\n", $contentParts);

    $payload = [
        'title'   => $title,
        'content' => $content,
        'status'  => 'publish',
    ];

    // Set post date from bookmark's created_at if available
    if (isset($bookmark['created_at']) && !empty($bookmark['created_at'])) {
        // Convert SHB datetime to WordPress format (ISO 8601)
        $bookmarkDate = new DateTime($bookmark['created_at']);
        $payload['date'] = $bookmarkDate->format('c'); // ISO 8601 format
        if ($debug) {
            logInfo("Setting post date to: " . $payload['date']);
        }
    }

    // Ensure tags exist and attach them
    $tagSlugs = array_filter(array_map('trim', explode(',', $config['wp_tags'])));
    if (!empty($tagSlugs)) {
        $payload['tags'] = ensureTagsExist($config, $tagSlugs, $debug);
    }

    // Ensure categories exist and attach them
    $catSlugs = array_filter(array_map('trim', explode(',', $config['wp_categories'])));
    if (!empty($catSlugs)) {
        $payload['categories'] = ensureCategoriesExist($config, $catSlugs, $debug);
    }

    $authHeader = 'Authorization: Basic ' . base64_encode($config['wp_user'] . ':' . $config['wp_app_password']);
    $resp = httpPostJson($wpUrl, $payload, [$authHeader], $debug);

    if ($resp === null || !isset($resp['id'])) {
        throw new RuntimeException('Failed to create WordPress post.');
    }
}

/**
 * Simple GET that returns decoded JSON.
 */
function httpGetJson(string $url, array $headers = []): ?array
{
    $ch = curl_init($url);
    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'SHB-WordPress-Sync/1.0',
    ];
    if (!empty($headers)) {
        $curlOpts[CURLOPT_HTTPHEADER] = $headers;
    }
    curl_setopt_array($ch, $curlOpts);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status >= 400) {
        throw new RuntimeException("GET $url failed (status $status): $err");
    }

    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Simple POST with JSON body, returns decoded JSON.
 */
function httpPostJson(string $url, array $payload, array $headers = [], bool $debug = false): ?array
{
    $headers = array_merge($headers, ['Content-Type: application/json']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'SHB-WordPress-Sync/1.0',
        CURLOPT_FOLLOWLOCATION => true,          // follow redirects
        CURLOPT_UNRESTRICTED_AUTH => true,       // keep auth on redirects
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($debug) {
        logInfo("POST $url status=$status");
        logInfo("Response: " . substr($body ?: '', 0, 500));
    }

    if ($body === false || $status >= 400) {
        throw new RuntimeException("POST $url failed (status $status): $err | Body: " . substr((string)$body, 0, 500));
    }

    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Ensure tags exist in WordPress and return their IDs.
 */
function ensureTagsExist(array $config, array $slugs, bool $debug = false): array
{
    $authHeader = 'Authorization: Basic ' . base64_encode($config['wp_user'] . ':' . $config['wp_app_password']);
    $baseTagsUrl = rtrim($config['wp_base'], '/') . '/wp-json/wp/v2/tags';

    $ids = [];
    foreach ($slugs as $slug) {
        if ($slug === '') {
            continue;
        }

        $safeSlug = normalizeSlug($slug);

        // Fetch existing tag by slug
        $resp = httpGetJson($baseTagsUrl . '?slug=' . rawurlencode($safeSlug), [$authHeader]);
        if (!empty($resp) && isset($resp[0]['id'])) {
            $ids[] = $resp[0]['id'];
            continue;
        }

        // Create tag if missing
        $createResp = httpPostJson(
            $baseTagsUrl,
            ['name' => $slug, 'slug' => $safeSlug],
            [$authHeader],
            $debug
        );

        if (isset($createResp['id'])) {
            $ids[] = $createResp['id'];
        }
    }

    return $ids;
}

/**
 * Ensure categories exist in WordPress and return their IDs.
 */
function ensureCategoriesExist(array $config, array $slugs, bool $debug = false): array
{
    $authHeader = 'Authorization: Basic ' . base64_encode($config['wp_user'] . ':' . $config['wp_app_password']);
    $baseCatsUrl = rtrim($config['wp_base'], '/') . '/wp-json/wp/v2/categories';

    $ids = [];
    foreach ($slugs as $slug) {
        if ($slug === '') {
            continue;
        }

        $safeSlug = normalizeSlug($slug);

        // Fetch existing category by slug
        $resp = httpGetJson($baseCatsUrl . '?slug=' . rawurlencode($safeSlug), [$authHeader]);
        if (!empty($resp) && isset($resp[0]['id'])) {
            $ids[] = $resp[0]['id'];
            continue;
        }

        // Create category if missing
        $createResp = httpPostJson(
            $baseCatsUrl,
            ['name' => $slug, 'slug' => $safeSlug],
            [$authHeader],
            $debug
        );

        if (isset($createResp['id'])) {
            $ids[] = $createResp['id'];
        }
    }

    return $ids;
}

/**
 * Normalize a string into a slug compatible with WordPress defaults.
 */
function normalizeSlug(string $value): string
{
    $value = strtolower(trim($value));
    // Replace non-alphanumeric with hyphens
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    return trim($value, '-');
}

function logInfo(string $msg): void
{
    fwrite(STDOUT, '[' . date('c') . "] $msg\n");
}

/**
 * Load WP sync settings from the database.
 */
function loadWpSyncSettings(): array
{
    global $pdo;
    $keys = [
        'wp_base_url',
        'wp_user',
        'wp_app_password',
        'wp_watch_tag',
        'wp_post_tags',
        'wp_post_categories',
        'shb_base_url',
        'shb_tag'
    ];

    $settings = [];
    try {
        $placeholders = rtrim(str_repeat('?,', count($keys)), ',');
        $stmt = $pdo->prepare("SELECT key, value FROM settings WHERE key IN ($placeholders)");
        $stmt->execute($keys);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }
    } catch (PDOException $e) {
        // Fail silently; will fall back to defaults/env
    }

    return $settings;
}

/**
 * Merge DB settings with env and defaults.
 */
function mergeConfigWithEnvAndDefaults(array $db): array
{
    $defaults = [
        'shb_base'       => 'https://bookmarks.thoughton.co.uk',
        'tag'            => 'thc',
        'wp_base'        => 'https://thoughton.co.uk',
        'wp_user'        => '',
        'wp_app_password'=> '',
        'wp_tags'        => 'interesting,thc,shb',
        'wp_categories'  => 'Interesting stuff',
        'state_file'     => realpath(__DIR__ . '/..') . '/data/wp_sync_last_id.txt',
    ];

    // Populate from DB (if present)
    $fromDb = [
        'shb_base'      => $db['shb_base_url'] ?? null,
        'tag'           => $db['wp_watch_tag'] ?? null,
        'wp_base'       => $db['wp_base_url'] ?? null,
        'wp_user'       => $db['wp_user'] ?? null,
        'wp_app_password'=> $db['wp_app_password'] ?? null,
        'wp_tags'       => $db['wp_post_tags'] ?? null,
        'wp_categories' => $db['wp_post_categories'] ?? null,
    ];

    // Environment overrides (if set)
    $fromEnv = [
        'shb_base'       => getenv('SHB_BASE_URL') ?: null,
        'tag'            => getenv('SHB_TAG') ?: null,
        'wp_base'        => getenv('WP_BASE_URL') ?: null,
        'wp_user'        => getenv('WP_USER') ?: null,
        'wp_app_password'=> getenv('WP_APP_PASSWORD') ?: null,
        'wp_tags'        => getenv('WP_TAGS') ?: null,
        'wp_categories'  => getenv('WP_CATEGORIES') ?: null,
        'state_file'     => getenv('SHB_WP_STATE_FILE') ?: null,
    ];

    $config = $defaults;

    foreach ($fromDb as $key => $val) {
        if ($val !== null && $val !== '') {
            $config[$key] = $val;
        }
    }
    foreach ($fromEnv as $key => $val) {
        if ($val !== null && $val !== '') {
            $config[$key] = $val;
        }
    }

    // Normalize
    $config['wp_base'] = rtrim($config['wp_base'], '/');
    $config['shb_base'] = rtrim($config['shb_base'], '/');

    return $config;
}

