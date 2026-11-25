<?php
/**
 * GovernIT Data Eraser - PDO connection helper.
 *
 * Loads credentials from config/db.php (git-ignored). Falls back to the
 * committed sample only when the real file is absent so local dev works.
 */
declare(strict_types=1);

function app_config(): array
{
    // 1) Prefer config OUTSIDE the webroot (repo build / hosting where the
    //    deploy user can write a sibling dir).
    $outside = dirname(__DIR__, 2) . '/config/db.php';
    if (is_file($outside)) {
        return require $outside;
    }
    // 2) Gigahost/FTP layout: the FTP user cannot write above the webroot, so
    //    credentials live in <webroot>/config/db.php, which is protected from
    //    public access by <webroot>/config/.htaccess (Require all denied).
    $webroot = dirname(__DIR__) . '/config/db.php';
    if (is_file($webroot)) {
        return require $webroot;
    }
    // No fallback to committed sample files; fail loudly instead.
    throw new RuntimeException('Database config file missing (config/db.php outside webroot or in webroot/config/)');
}

function db_connect(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = app_config();
    $db = $c['db'];

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'],
        $db['port'] ?? 3306,
        $db['name'],
        $db['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

function json_respond(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): never
{
    json_respond(['error' => $message], $status);
}

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

/** RFC-4122 v4 UUID used as the client/server id (matches the Rust Id format). */
function uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/** Accept only RFC-4122 UUID-shaped ids to avoid odd input via the id path. */
function is_uuid(string $id): bool
{
    return (bool)preg_match(
        '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
        $id
    );
}

function require_uuid(string $id): string
{
    if (!is_uuid($id)) {
        json_error('invalid_uuid: ' . $id, 400);
    }
    return strtolower($id);
}

/** Parse the JSON request body into an array (or [] when empty). */
function request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error('invalid_json_body', 400);
    }
    return $data;
}
