<?php
/**
 * ========================================================================================================
 *  Drop this file on any PHP 8+ host.  Edit the CONFIG section only. if you dont want to mess something up
 * ========================================================================================================
 */

/* ============================================================================================================
   CONFIG  —-------------------------------------------------------------------------------------------------- */
const ALLOWED_ORIGINS = [
    /*  ←  put every domain that is allowed to call this endpoint You can have any number of domains you want  */
    'https://example.com',
    'https://example2.com',
];

const REDIS_DSN = 'rediss://default:YOUR_REDIS_PASSWORD@YOUR_REDIS_HOST:6379';

const API_HIANIME = 'https://api.your-hianime-api.com/api/stream';   // primary
const API_BACKUP  = 'https://api.your-backup-api.com/api/stream';    // fallback

/* cache each successful HiAnime response for (seconds)                */
const CACHE_TTL = 600;

/* default server order (fallback priority)                            */
const SERVER_PRIORITY = ['hd-2', 'hd-1', 'hd-3'];
/* ==================================================================== */


/* ====================================================================
   0.  BASIC CORS / DOMAIN-LOCK
   ==================================================================== */
header('Content-Type: application/json');

$origin  = $_SERVER['HTTP_ORIGIN']  ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';

if (!$origin && $referer) {
    $p = parse_url($referer);
    if (!empty($p['scheme']) && !empty($p['host'])) {
        $origin = "{$p['scheme']}://{$p['host']}";
    }
}

if (!$origin || !in_array($origin, ALLOWED_ORIGINS, true)) {
    http_response_code(403);
    exit(json_encode(['success'=>false,'error'=>'Access denied']));
}

header("Access-Control-Allow-Origin: $origin");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/* ====================================================================
   1.  REDIS INITIALISATION
   ==================================================================== */
$redisConnected = false;
$redis          = null;

if (extension_loaded('redis') && class_exists('Redis')) {
    try {
        $redis    = new Redis();
        $dsn      = parse_url(REDIS_DSN);          // user / pass / host / port
        $redis->connect(
            $dsn['host'],
            $dsn['port'],
            2.5,
            null,
            0,
            0,
            ['ssl' => ['verify_peer'=>false,'verify_peer_name'=>false]]
        );
        if (!empty($dsn['pass'])) {
            $redis->auth($dsn['pass']);
        }
        $redisConnected = true;
    } catch (Throwable $e) {
        $redisConnected = false;   // silently continue without cache
    }
}

/* ====================================================================
   2.  HELPERS
   ==================================================================== */
function httpJson(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['User-Agent: Mozilla/5.0'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $body) ? json_decode($body, true) : null;
}

function hasStream(?array $data): bool {
    return $data
        && ($data['success'] ?? false)
        && !empty($data['results']['streamingLink']);
}

function addApiMeta(array $data, string $source, string $server): array {
    $data['api_info'] = [
        'source'      => $source,                // 'hianime' | 'backup'
        'server_used' => $server,
        'provider'    => $source === 'hianime' ? 'HiAnime' : 'Backup',
    ];
    return $data;
}

/** reorder servers so the user-preferred one is attempted first */
function buildServerList(?string $preferred): array {
    if (!$preferred) return SERVER_PRIORITY;
    $list = [$preferred];
    foreach (SERVER_PRIORITY as $s) if ($s !== $preferred) $list[] = $s;
    return $list;
}

function fetchWithCache(
    string $baseUrl,
    string $id,
    string $server,
    string $type,
    bool   $useCache,
    string $apiSource,
    ?Redis $redis,
    bool   $connected
): ?array {
    $url = $baseUrl
         . '?id='     . urlencode($id)
         . '&server=' . urlencode($server)
         . '&type='   . urlencode($type);

    $key = 'anime_stream:' . md5($url);

    /* Redis read */
    if ($useCache && $connected) {
        $cached = $redis->get($key);
        if ($cached) {
            $decoded = json_decode($cached, true);
            if (hasStream($decoded)) {
                return addApiMeta($decoded, $apiSource, $server);
            }
        }
    }

    /* Fresh fetch */
    $fresh = httpJson($url);
    if ($fresh) {
        $fresh = addApiMeta($fresh, $apiSource, $server);
        if ($useCache && $connected && hasStream($fresh)) {
            $redis->setex($key, CACHE_TTL, json_encode($fresh));
        }
    }
    return $fresh;
}

/* ====================================================================
   3.  REQUEST PARAMS
   ==================================================================== */
$id     = $_GET['id']     ?? '';
$server = $_GET['server'] ?? null;   // it can be null so we move on 
$type   = $_GET['type']   ?? 'sub';

if (!$id) {
    http_response_code(400);
    exit(json_encode(['success'=>false,'error'=>'Missing id']));
}

/* ====================================================================
   4.  HIANIME STREAM  (primary)
   ==================================================================== */
$result = null;
foreach (buildServerList($server) as $srv) {
    $result = fetchWithCache(
        API_HIANIME,
        $id,
        $srv,
        $type,
        true,                 // cache primary links
        'hianime',
        $redis,
        $redisConnected
    );
    if (hasStream($result)) break;
}

/* ====================================================================
   5.  BACKUP API  (fallback)
   ==================================================================== */
if (!hasStream($result)) {
    /* backup mirrors never use hd-3 */
    $backupOrder = ['hd-2', 'hd-1']; // MegaPlay VidPlay
    $servers     = $server && in_array($server, $backupOrder, true)
                 ? array_merge([$server], array_diff($backupOrder, [$server]))
                 : $backupOrder;

    foreach ($servers as $srv) {
        $result = fetchWithCache(
            API_BACKUP,
            $id,
            $srv,
            $type,
            false,            // No caching backup links but if want to cache this as well then you can change this to true 
            'backup',
            null,
            false
        );
        if (hasStream($result)) break;
    }
}

/* ====================================================================
   6.  OUTPUT
   ==================================================================== */
if (hasStream($result)) {
    echo json_encode($result);
} else {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error'   => 'All servers returned empty links. Refresh to try again.',
    ]);
}
?>
