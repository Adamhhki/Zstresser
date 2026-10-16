<?php
declare(strict_types=1);

/**
 * Uses the two conversion providers from the original logic.
 * Only use it for media you own or are authorized to download.
 */
function ytdownloader(string $url, string $format = 'mp3'): array {
    $domains = [
        ['baseURL' => 'https://ytmp3.gl/', 'apiURL' => 'https://gamma.gammacloud.net'],
        ['baseURL' => 'https://convertytmp3.org/', 'apiURL' => 'https://epsilon.epsiloncloud.org'],
    ];

    $format = strtolower($format);
    if (!in_array($format, ['mp3', 'mp4'], true)) {
        throw new Exception('MP3 or MP4 only.');
    }

    $parts = parse_url($url);
    if (!isset($parts['host'], $parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        throw new Exception('Invalid YouTube URL.');
    }

    $host = strtolower($parts['host']);
    $allowedHost = $host === 'youtu.be' || $host === 'www.youtu.be' || $host === 'youtube.com' || str_ends_with($host, '.youtube.com') || $host === 'youtube-nocookie.com' || str_ends_with($host, '.youtube-nocookie.com');
    if (!$allowedHost) {
        throw new Exception('Only supported YouTube URLs are accepted.');
    }

    $videoId = null;
    parse_str($parts['query'] ?? '', $query);
    if (isset($query['v'])) {
        $videoId = $query['v'];
    } elseif (str_ends_with($host, 'youtu.be')) {
        $pathParts = explode('/', trim($parts['path'] ?? '', '/'));
        $videoId = $pathParts[0] ?? null;
    }

    if (!$videoId && isset($parts['path'])) {
        if (preg_match('~^/(?:shorts|live|embed|v)/([A-Za-z0-9_-]{11})~', $parts['path'], $matches)) {
            $videoId = $matches[1];
        }
    }

    if (!$videoId || !preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)) {
        throw new Exception('Invalid YouTube video URL.');
    }

    $lastError = null;

    foreach ($domains as $domain) {
        try {
            return processWithProvider($domain, $videoId, $format);
        } catch (Exception $e) {
            $lastError = new Exception($domain['baseURL'] . ' failed: ' . $e->getMessage());
        }
    }

    throw $lastError ?: new Exception('All conversion providers failed.');
}

function processWithProvider(array $domain, string $videoId, string $format): array {
    $origin = rtrim($domain['baseURL'], '/');
    $defaultHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept: */*',
        'Origin: ' . $origin,
        'Referer: ' . $domain['baseURL'],
    ];

    // 1. Get API Key from HTML
    $html = httpRequest($domain['baseURL'], $defaultHeaders);
    if (!preg_match('/var\s+apiKey\s*=\s*[\'"]([^\'"]+)[\'"]\s*;?/', $html, $matches)) {
        throw new Exception('API key not found on provider page.');
    }
    $apiKey = $matches[1];

    // 2. Auth
    $authUrl = $domain['apiURL'] . '/api/v1/auth?' . http_build_query(['api_key' => $apiKey, '_' => (int)(microtime(true) * 1000)]);
    $authRaw = httpRequest($authUrl, $defaultHeaders);
    $auth = json_decode($authRaw, true);
    if (($auth['err'] ?? null) != 0 || empty($auth['key'])) {
        throw new Exception('Authentication failed.');
    }
    $token = $auth['key'];

    // 3. Init
    $initUrl = $domain['apiURL'] . '/api/v1/init?' . http_build_query(['_' => (int)(microtime(true) * 1000)]);
    $initHeaders = array_merge($defaultHeaders, ["Authorization: Bearer $token"]);
    $initRaw = httpRequest($initUrl, $initHeaders);
    $init = json_decode($initRaw, true);
    if (($init['error'] ?? null) != 0 || empty($init['convertURL'])) {
        throw new Exception('Initialization failed.');
    }

    // 4. Convert
    $convertUrl = $init['convertURL'];
    if (!str_contains($convertUrl, '://')) {
        $convertUrl = rtrim($domain['apiURL'], '/') . '/' . ltrim($convertUrl, '/');
    }
    $convertParams = [
        'v' => $videoId,
        'f' => $format,
        '_' => (int)(microtime(true) * 1000)
    ];
    $convertUrl .= (str_contains($convertUrl, '?') ? '&' : '?') . http_build_query($convertParams);
    
    $resultRaw = httpRequest($convertUrl, $defaultHeaders);
    $result = json_decode($resultRaw, true);

    // Handle initial redirect if any
    $redirects = 0;
    while (!empty($result['redirectURL']) && $redirects < 5) {
        $rUrl = $result['redirectURL'];
        if (!str_contains($rUrl, '://')) $rUrl = rtrim($domain['apiURL'], '/') . '/' . ltrim($rUrl, '/');
        $resultRaw = httpRequest($rUrl, $defaultHeaders);
        $result = json_decode($resultRaw, true);
        $redirects++;
    }

    // 5. Polling
    for ($i = 0; $i < 30 && empty($result['downloadURL']); $i++) {
        if (isset($result['error']) && !in_array($result['error'], ['0', 0, ''], true)) {
            throw new Exception('Conversion failed: ' . substr((string)$result['error'], 0, 180));
        }
        if (empty($result['progressURL'])) break;

        sleep(3);
        
        $pUrl = $result['progressURL'];
        if (!str_contains($pUrl, '://')) $pUrl = rtrim($domain['apiURL'], '/') . '/' . ltrim($pUrl, '/');
        $resultRaw = httpRequest($pUrl, $defaultHeaders);
        $result = json_decode($resultRaw, true);

        $redirects = 0;
        while (!empty($result['redirectURL']) && $redirects < 5) {
            $rUrl = $result['redirectURL'];
            if (!str_contains($rUrl, '://')) $rUrl = rtrim($domain['apiURL'], '/') . '/' . ltrim($rUrl, '/');
            $resultRaw = httpRequest($rUrl, $defaultHeaders);
            $result = json_decode($resultRaw, true);
            $redirects++;
        }
    }

    if (empty($result['downloadURL'])) {
        throw new Exception('Conversion timed out or no download URL was returned.');
    }

    $downloadUrl = $result['downloadURL'];
    if (!str_contains($downloadUrl, '://')) {
        $downloadUrl = rtrim($domain['apiURL'], '/') . '/' . ltrim($downloadUrl, '/');
    }

    return [
        'title' => (string)($result['title'] ?? ''),
        'downloadURL' => $downloadUrl,
        'format' => $format
    ];
}

function httpRequest(string $url, array $headers = []): string {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new Exception("HTTP request failed: $error");
    }
    if ($httpCode >= 400) {
        throw new Exception("HTTP request failed with status $httpCode");
    }

    return (string)$response;
}
