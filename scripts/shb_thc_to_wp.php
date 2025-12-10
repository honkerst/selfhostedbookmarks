#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Sync newest SHB bookmark with a given tag to WordPress via REST API.
 *
 * Defaults are set for bookmarks.thoughton.co.uk -> thoughton.co.uk/_wp.
 * Override via environment variables:
 *   SHB_BASE_URL         e.g. https://bookmarks.thoughton.co.uk
 *   SHB_TAG              e.g. thc
 *   WP_BASE_URL          e.g. https://thoughton.co.uk/_wp
 *   WP_USER              e.g. selfhostedbookmarks
 *   WP_APP_PASSWORD      WordPress application password (required)
 *   SHB_WP_STATE_FILE    Path to last-id file (default: ../data/wp_sync_last_id.txt)
 */

$config = [
    'shb_base'       => getenv('SHB_BASE_URL') ?: 'https://bookmarks.thoughton.co.uk',
    'tag'            => getenv('SHB_TAG') ?: 'thc',
    // WP_BASE_URL should be the root where wp-json lives (no trailing slash)
    'wp_base'        => rtrim(getenv('WP_BASE_URL') ?: 'https://thoughton.co.uk', '/'),
    'wp_user'        => getenv('WP_USER') ?: 'timh',
    'wp_app_password'=> getenv('WP_APP_PASSWORD') ?: '',
    'wp_tags'        => getenv('WP_TAGS') ?: 'interesting,thc,shb',
    'wp_categories'  => getenv('WP_CATEGORIES') ?: 'Interesting stuff',
    'state_file'     => getenv('SHB_WP_STATE_FILE') ?: realpath(__DIR__ . '/..') . '/data/wp_sync_last_id.txt',
];

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

    $bookmark = fetchLatestBookmark($config['shb_base'], $config['tag']);
    if ($bookmark === null) {
        logInfo("No bookmarks found for tag '{$config['tag']}'.");
        exit(0);
    }

    if ((int)$bookmark['id'] === $lastId) {
        logInfo("No new bookmark (last id {$lastId}).");
        exit(0);
    }

    logInfo("Posting bookmark id {$bookmark['id']} titled '{$bookmark['title']}'");
    if ($debug) {
        logInfo("Bookmark payload: " . json_encode($bookmark, JSON_PRETTY_PRINT));
    }

    postToWordPress($config, $bookmark, $debug);
    file_put_contents($config['state_file'], (string)$bookmark['id']);
    logInfo("Synced bookmark id {$bookmark['id']} to WordPress.");
} catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * Fetch newest bookmark for a tag from SHB.
 */
function fetchLatestBookmark(string $base, string $tag): ?array
{
    $url = rtrim($base, '/') . '/api/bookmarks.php?tag=' . rawurlencode($tag);
    $resp = httpGetJson($url);
    if ($resp === null || empty($resp['bookmarks'])) {
        return null;
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
    if (empty($matches)) {
        return null;
    }
    // Bookmarks are already ordered newest-first by API
    return $matches[0];
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
function httpGetJson(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'SHB-WordPress-Sync/1.0',
    ]);
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
        $resp = httpGetJson($baseTagsUrl . '?slug=' . rawurlencode($safeSlug));
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
        $resp = httpGetJson($baseCatsUrl . '?slug=' . rawurlencode($safeSlug));
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

