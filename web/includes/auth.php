<?php
/**
 * GovernIT Data Eraser - Authentication & RBAC.
 *
 * - Login issues a bearer token stored (hashed) in web_session.
 * - require_auth() enforces a valid token and returns the user id.
 * - require_role() / has_role() implement role-based access control against
 *   the union of the user's own roles (web_user_role) and, when the user maps
 *   to a technician, the technician's roles (technician_role).
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Load a web_user row by username (for login). */
function find_user_by_username(PDO $pdo, string $username): ?array
{
    $st = $pdo->prepare(
        'SELECT id, username, email, display_name, password_hash,
                technician_id, active, last_login_at
         FROM web_user WHERE username = ?'
    );
    $st->execute([$username]);
    $u = $st->fetch();
    return $u === false ? null : $u;
}

function find_user_by_id(PDO $pdo, string $id): ?array
{
    $st = $pdo->prepare(
        'SELECT id, username, email, display_name, password_hash,
                technician_id, active, last_login_at
         FROM web_user WHERE id = ?'
    );
    $st->execute([$id]);
    $u = $st->fetch();
    return $u === false ? null : $u;
}

/** All role names granted to a user (own + inherited via technician). */
function roles_for_user(PDO $pdo, string $userId): array
{
    $st = $pdo->prepare(
        'SELECT DISTINCT r.name
         FROM role r
         JOIN web_user_role ur ON ur.role_id = r.id
         WHERE ur.user_id = ?'
    );
    $st->execute([$userId]);
    $own = $st->fetchAll(PDO::FETCH_COLUMN);

    $tech = [];
    $tc = $pdo->prepare('SELECT technician_id FROM web_user WHERE id = ?');
    $tc->execute([$userId]);
    $technicianId = $tc->fetchColumn();
    if ($technicianId) {
        $st2 = $pdo->prepare(
            'SELECT DISTINCT r.name
             FROM role r
             JOIN technician_role tr ON tr.role_id = r.id
             WHERE tr.technician_id = ?'
        );
        $st2->execute([$technicianId]);
        $tech = $st2->fetchAll(PDO::FETCH_COLUMN);
    }
    return array_values(array_unique([...$own, ...$tech]));
}

const PORTAL_SESSION_COOKIE = '__Host-eraser_portal';

function is_portal_request(): bool
{
    // Browser-controlled Fetch Metadata plus an explicit same-origin API header.
    return ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'same-origin'
        && ($_SERVER['HTTP_X_ERASER_PORTAL'] ?? '') === '1';
}

function portal_session_token(): ?string
{
    if (!is_portal_request()) {
        return null;
    }
    $token = $_COOKIE[PORTAL_SESSION_COOKIE] ?? '';
    return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) === 1 ? $token : null;
}

function set_portal_session_cookie(string $token, int $expires): void
{
    if (!setcookie(PORTAL_SESSION_COOKIE, $token, [
        'expires' => $expires,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ])) {
        throw new RuntimeException('Could not set portal session cookie');
    }
}

function request_auth_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header !== '') {
        return preg_match('/^Bearer\s+([A-Za-z0-9.\-_]{16,})$/i', trim($header), $match) === 1
            ? $match[1] : null;
    }
    return portal_session_token();
}

/** Validate the API token and return its active user. */
function require_auth(PDO $pdo): array
{
    $token = request_auth_token();
    if ($token === null) {
        json_error('unauthorized', 401);
    }
    $hash = hash('sha256', $token);

    $st = $pdo->prepare(
        'SELECT ws.user_id, ws.expires_at, u.active
         FROM web_session ws
         JOIN web_user u ON u.id = ws.user_id
         WHERE ws.token_hash = ?'
    );
    $st->execute([$hash]);
    $row = $st->fetch();
    if ($row === false || (int)$row['active'] !== 1) {
        json_error('unauthorized', 401);
    }
    if ($row['expires_at'] < now_iso()) {
        $del = $pdo->prepare('DELETE FROM web_session WHERE token_hash = ?');
        $del->execute([$hash]);
        json_error('token_expired', 401);
    }
    $user = find_user_by_id($pdo, $row['user_id']);
    if ($user === null || (int)$user['active'] !== 1) {
        json_error('unauthorized', 401);
    }
    $user['roles'] = roles_for_user($pdo, $user['id']);
    return $user;
}

/** True when the current user holds every role in $roles. */
function has_role(PDO $pdo, array $user, string ...$roles): bool
{
    $granted = functions_role_cache($pdo, $user['id']);
    foreach ($roles as $r) {
        if (in_array($r, $granted, true)) {
            return true;
        }
    }
    return false;
}

function require_role(PDO $pdo, array $user, string ...$roles): void
{
    if (!has_role($pdo, $user, ...$roles)) {
        json_error('forbidden', 403);
    }
}

/** Per-request memo so we don't re-query roles for every check. */
function functions_role_cache(PDO $pdo, string $userId): array
{
    static $cache = [];
    if (!isset($cache[$userId])) {
        $cache[$userId] = roles_for_user($pdo, $userId);
    }
    return $cache[$userId];
}

/** Issue a bearer token (returns raw token; only its hash is stored). */
function issue_token(PDO $pdo, string $userId): array
{
    $token = bin2hex(random_bytes(32));
    $ttl   = 60 * 60 * 24 * 7; // 7 days
    $expires = gmdate('Y-m-d\TH:i:s\Z', time() + $ttl);

    $st = $pdo->prepare(
        'INSERT INTO web_session
            (id, user_id, token_hash, expires_at, created_at, ip_address, user_agent)
         VALUES (?,?,?,?,?,?,?)'
    );
    $st->execute([
        uuid_v4(),
        $userId,
        hash('sha256', $token),
        $expires,
        now_iso(),
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
    return ['token' => $token, 'expires_at' => $expires, 'token_type' => 'Bearer'];
}

function revoke_token(PDO $pdo, string $token): void
{
    $st = $pdo->prepare('DELETE FROM web_session WHERE token_hash = ?');
    $st->execute([hash('sha256', $token)]);
}

function record_login(PDO $pdo, string $userId): void
{
    $st = $pdo->prepare('UPDATE web_user SET last_login_at = ? WHERE id = ?');
    $st->execute([now_iso(), $userId]);
}

/**
 * Roles whose holders see every customer (unrestricted). Scoped roles
 * (KundeAdministrator, KundeRapporter) are limited to user_customer_access.
 */
function UNRESTRICTED_ROLES(): array
{
    return ['SystemAdministrator', 'Administrator', 'Koordinator', 'Revisor'];
}

/**
 * True when $user is not limited to specific customers (sees the whole tenant).
 */
function user_unrestricted(PDO $pdo, array $user): bool
{
    return has_role($pdo, $user, ...UNRESTRICTED_ROLES());
}

/**
 * Customer ids a user may access. Returns null when unrestricted (meaning "all"),
 * otherwise the list from user_customer_access. The caller must filter queries
 * by this set when it is non-null.
 */
function user_customer_ids(PDO $pdo, array $user): ?array
{
    if (user_unrestricted($pdo, $user)) {
        return null; // unrestricted: every customer
    }
    $st = $pdo->prepare(
        'SELECT customer_id FROM user_customer_access WHERE user_id = ? ORDER BY customer_id'
    );
    $st->execute([$user['id']]);
    return array_column($st->fetchAll(), 'customer_id');
}

/**
 * Require that the current user may access the given customer id.
 * Exits with 403 otherwise. Full-access roles always pass.
 */
function require_customer_access(PDO $pdo, array $user, string $customerId): void
{
    $ids = user_customer_ids($pdo, $user);
    if ($ids === null || in_array($customerId, $ids, true)) {
        return;
    }
    json_error('forbidden', 403);
}

/**
 * Build a reusable SQL fragment + params that restrict a query to the customers
 * the user may access. $alias must be the table alias owning customer_id.
 *
 *   [$frag, $params] = customer_scope_sql($pdo, $user, 'c');
 *   SELECT ... WHERE ... AND $frag   (execute with $params)
 *
 * When the user is unrestricted $frag is '' and $params is [].
 */
function customer_scope_sql(PDO $pdo, array $user, string $alias): array
{
    $ids = user_customer_ids($pdo, $user);
    if ($ids === null) {
        return ['', []];
    }
    if ($ids === []) {
        return ['1 = 0', []]; // scoped but no customers -> nothing
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return [$alias . '.customer_id IN (' . $placeholders . ')', array_values($ids)];
}
