<?php
/**
 * USERS resource: /api/v1/users  (Admin).
 *  GET    /api/v1/users
 *  GET    /api/v1/users/{id}
 *  POST   /api/v1/users
 *  PUT    /api/v1/users/{id}
 *  DELETE /api/v1/users/{id}   (deactivate)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validate.php';

function user_row(PDO $pdo, array $row): array
{
    return [
        'id'            => $row['id'],
        'username'      => $row['username'],
        'email'         => $row['email'],
        'display_name'  => $row['display_name'],
        'technician_id' => $row['technician_id'],
        'active'        => (int)$row['active'] === 1,
        'needs_password_change' => (int)$row['needs_password_change'] === 1,
        'created_at'    => $row['created_at'],
        'updated_at'    => $row['updated_at'],
        'last_login_at' => $row['last_login_at'],
        'roles'         => roles_for_user($pdo, $row['id']),
        'customer_ids'  => customer_ids_for_user($pdo, $row['id']),
    ];
}

/** Customer ids assigned to a user via user_customer_access. */
function customer_ids_for_user(PDO $pdo, string $userId): array
{
    $st = $pdo->prepare(
        'SELECT customer_id FROM user_customer_access WHERE user_id = ? ORDER BY customer_id'
    );
    $st->execute([$userId]);
    return array_column($st->fetchAll(), 'customer_id');
}

/** Replace the full set of customer ids a user may access. */
function set_user_customer_access(PDO $pdo, string $userId, array $customerIds): void
{
    $del = $pdo->prepare('DELETE FROM user_customer_access WHERE user_id = ?');
    $del->execute([$userId]);
    if ($customerIds === []) {
        return;
    }
    $ins = $pdo->prepare(
        'INSERT IGNORE INTO user_customer_access (user_id, customer_id, created_at, updated_at) VALUES (?,?,?,?)'
    );
    $now = now_iso();
    foreach ($customerIds as $cid) {
        $ins->execute([$userId, $cid, $now, $now]);
    }
}

/**
 * Restrict a list query so a scoped admin only sees users who share at least
 * one customer with them (or that admin). Returns the caller-cast kind.
 */
function scoped_user_where(PDO $pdo, array $user): array
{
    if (user_unrestricted($pdo, $user)) {
        return ['', []];
    }
    // Users overlap when either party maps to a customer the other can see.
    return [
        'EXISTS (SELECT 1 FROM user_customer_access a
                  JOIN user_customer_access b ON a.customer_id = b.customer_id
                  WHERE a.user_id = web_user.id AND b.user_id = ?)',
        [$user['id']],
    ];
}

function get_user_by_id(PDO $pdo, string $id): ?array
{
    $st = $pdo->prepare(
        'SELECT id, username, email, display_name, technician_id, active,
                needs_password_change, created_at, updated_at, last_login_at
         FROM web_user WHERE id = ?'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    return $row === false ? null : user_row($pdo, $row);
}

/** List web users (with their roles + customer access). Scoped to the caller. */
function list_users(PDO $pdo, array $caller): array
{
    [$scopeSql, $scopeParams] = scoped_user_where($pdo, $caller);
    $where = $scopeSql === '' ? '' : 'WHERE ' . $scopeSql;
    $st = $pdo->prepare(
        'SELECT id, username, email, display_name, technician_id, active,
                needs_password_change, created_at, updated_at, last_login_at
         FROM web_user ' . $where . ' ORDER BY username'
    );
    $st->execute($scopeParams);
    return array_map(fn($r) => user_row($pdo, $r), $st->fetchAll());
}

/** Resolve role names -> role ids (skip unknown names). */
function resolve_role_ids(PDO $pdo, array $roleNames): array
{
    if ($roleNames === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($roleNames), '?'));
    $st = $pdo->prepare("SELECT id, name FROM role WHERE name IN ($placeholders)");
    $st->execute($roleNames);
    return array_column($st->fetchAll(), 'id');
}

function set_user_roles(PDO $pdo, string $userId, array $roleNames): void
{
    $del = $pdo->prepare('DELETE FROM web_user_role WHERE user_id = ?');
    $del->execute([$userId]);
    $ins = $pdo->prepare(
        'INSERT IGNORE INTO web_user_role (user_id, role_id) VALUES (?,?)'
    );
    foreach (resolve_role_ids($pdo, $roleNames) as $roleId) {
        $ins->execute([$userId, $roleId]);
    }
}

/**
 * Assert the caller may see/manage the given target user. Full-access roles
 * always pass; a scoped admin must share at least one customer with the target.
 */
function require_manage_user(PDO $pdo, array $caller, string $targetId): void
{
    if (user_unrestricted($pdo, $caller)) {
        return;
    }
    $ids = customer_ids_for_user($pdo, $caller['id']);
    if ($ids === []) {
        json_error('forbidden', 403);
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        "SELECT 1 FROM user_customer_access
         WHERE user_id = ? AND customer_id IN ($ph)"
    );
    $st->execute(array_merge([$targetId], $ids));
    if ($st->fetch() === false) {
        json_error('forbidden', 403);
    }
}

/**
 * When the caller is a scoped admin, forbid granting customer ids outside
 * their own scope. Returns the (validated) customer id list to persist.
 */
function scoped_customer_selection(PDO $pdo, array $caller, array $requested): array
{
    if (user_unrestricted($pdo, $caller)) {
        return $requested;
    }
    $mine = customer_ids_for_user($pdo, $caller['id']);
    foreach ($requested as $cid) {
        if (!in_array($cid, $mine, true)) {
            json_error('forbidden: customer_out_of_scope', 403);
        }
    }
    return $requested;
}

function route_users(string $method, string $path, PDO $pdo): bool
{
    if (!str_starts_with($path, '/api/v1/users')) {
        return false;
    }
    $user = require_auth($pdo);
    require_role($pdo, $user, 'SystemAdministrator', 'Administrator', 'Koordinator', 'KundeAdministrator');

    $rest  = substr($path, strlen('/api/v1/users'));
    $parts = $rest === '' ? [] : array_values(array_filter(explode('/', $rest)));

    // GET /api/v1/users
    if ($method === 'GET' && $parts === []) {
        json_respond(['users' => list_users($pdo, $user)]);
    }

    // POST /api/v1/users
    if ($method === 'POST' && $parts === []) {
        $in = request_json();
        $username = opt_str('username', $in, 64) ?? null;
        if ($username === null) {
            $username = opt_email('email', $in) ?? null;
        }
        if ($username === null) {
            json_error('missing_field: username or email', 422);
        }
        $email    = opt_email('email', $in);
        $invite   = opt_bool('invite', $in, false);
        $pass     = $invite ? null : req('password', $in);
        if (!$invite && strlen($pass) < 8) {
            json_error('invalid_field: password (min 8 chars)', 422);
        }
        $roleNames    = opt_string_list('roles', $in) ?? [];
        $customerIds  = scoped_customer_selection($pdo, $user, opt_string_list('customer_ids', $in) ?? []);

        $chk = $pdo->prepare('SELECT 1 FROM web_user WHERE username = ? OR (email IS NOT NULL AND email = ?)');
        $chk->execute([$username, $email]);
        if ($chk->fetch() !== false) {
            json_error('conflict: username or email already exists', 409);
        }

        $newId  = uuid_v4();
        $active = $invite ? 0 : 1;
        $st = $pdo->prepare(
            'INSERT INTO web_user
                (id, username, email, display_name, password_hash, technician_id, active, needs_password_change, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $newId,
            $username,
            $email,
            opt_str('display_name', $in, 255) ?? $username,
            $invite ? null : password_hash($pass, PASSWORD_BCRYPT),
            opt_uuid('technician_id', $in),
            $active,
            $invite ? 1 : 0,
            now_iso(),
            now_iso(),
        ]);
        set_user_roles($pdo, $newId, $roleNames);
        set_user_customer_access($pdo, $newId, $customerIds);
        json_respond(['user' => get_user_by_id($pdo, $newId)], 201);
    }

    // The remaining methods require a {id}.
    if ($parts === []) {
        json_error('method_not_allowed', 405);
    }
    $id = require_uuid($parts[0]);

    // GET /api/v1/users/{id}
    if ($method === 'GET') {
        require_manage_user($pdo, $user, $id);
        $u = get_user_by_id($pdo, $id);
        if ($u === null) {
            json_error('not_found', 404);
        }
        json_respond(['user' => $u]);
    }

    // DELETE /api/v1/users/{id} -> deactivate
    if ($method === 'DELETE') {
        if ($id === $user['id']) {
            json_error('cannot_deactivate_self', 422);
        }
        require_manage_user($pdo, $user, $id);
        $st = $pdo->prepare('UPDATE web_user SET active = 0, updated_at = ? WHERE id = ?');
        $st->execute([now_iso(), $id]);
        json_respond(['ok' => true]);
    }

    // PUT /api/v1/users/{id}
    if ($method === 'PUT') {
        require_manage_user($pdo, $user, $id);
        $in     = request_json();
        $email  = opt_email('email', $in);
        $tech   = opt_uuid('technician_id', $in);
        $active = opt_bool('active', $in, true);

        $now      = now_iso();
        $existing = get_user_by_id($pdo, $id);
        if ($existing === null) {
            json_error('not_found', 404);
        }
        $st = $pdo->prepare(
            'UPDATE web_user SET email = ?, display_name = ?, technician_id = ?, active = ?, updated_at = ?
             WHERE id = ?'
        );
        $st->execute([
            $email,
            opt_str('display_name', $in, 255) ?? $existing['display_name'],
            $tech,
            $active ? 1 : 0,
            $now,
            $id,
        ]);
        if (isset($in['roles']) && is_array($in['roles'])) {
            set_user_roles($pdo, $id, $in['roles']);
        }
        if (isset($in['customer_ids']) && is_array($in['customer_ids'])) {
            $cleaned = [];
            foreach ($in['customer_ids'] as $cid) {
                $cleaned[] = require_uuid($cid);
            }
            set_user_customer_access($pdo, $id, scoped_customer_selection($pdo, $user, $cleaned));
        }
        if (isset($in['password'])) {
            if (strlen((string)$in['password']) < 8) {
                json_error('invalid_field: password (min 8 chars)', 422);
            }
            $pw = $pdo->prepare('UPDATE web_user SET password_hash = ?, needs_password_change = 0 WHERE id = ?');
            $pw->execute([password_hash((string)$in['password'], PASSWORD_BCRYPT), $id]);
        }
        json_respond(['user' => get_user_by_id($pdo, $id)]);
    }

    json_error('method_not_allowed', 405);
}
