<?php
/**
 * ROLES resource: /api/v1/roles  (Admin).
 *  GET    /api/v1/roles
 *  GET    /api/v1/roles/{id}
 *  POST   /api/v1/roles
 *  PUT    /api/v1/roles/{id}
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validate.php';

function role_row(array $r): array
{
    return ['id' => $r['id'], 'name' => $r['name'], 'description' => $r['description']];
}

function get_role_by_id(PDO $pdo, string $id): ?array
{
    $st = $pdo->prepare('SELECT id, name, description FROM role WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row === false ? null : role_row($row);
}

function route_roles(string $method, string $path, PDO $pdo): bool
{
    if (!str_starts_with($path, '/api/v1/roles')) {
        return false;
    }
    $user = require_auth($pdo);
    require_role($pdo, $user, 'Administrator');

    $rest  = substr($path, strlen('/api/v1/roles'));
    $parts = $rest === '' ? [] : array_values(array_filter(explode('/', $rest)));

    if ($method === 'GET' && $parts === []) {
        json_respond(['roles' => array_map('role_row', $pdo->query(
            'SELECT id, name, description FROM role ORDER BY name'
        )->fetchAll())]);
    }

    if ($method === 'POST' && $parts === []) {
        $in = request_json();
        $name = opt_str('name', $in, 64) ?? null;
        if ($name === null) {
            json_error('missing_field: name', 422);
        }
        $chk = $pdo->prepare('SELECT 1 FROM role WHERE name = ?');
        $chk->execute([$name]);
        if ($chk->fetch() !== false) {
            json_error('conflict: role already exists', 409);
        }
        $id = uuid_v4();
        $st = $pdo->prepare('INSERT INTO role (id, name, description) VALUES (?,?,?)');
        $st->execute([$id, $name, opt_str('description', $in, 255)]);
        json_respond(['role' => get_role_by_id($pdo, $id)], 201);
    }

    if ($parts === []) {
        json_error('method_not_allowed', 405);
    }
    $id = require_uuid($parts[0]);

    if ($method === 'GET') {
        $r = get_role_by_id($pdo, $id);
        if ($r === null) {
            json_error('not_found', 404);
        }
        json_respond(['role' => $r]);
    }

    if ($method === 'PUT') {
        if (get_role_by_id($pdo, $id) === null) {
            json_error('not_found', 404);
        }
        $in  = request_json();
        $st  = $pdo->prepare('UPDATE role SET name = ?, description = ? WHERE id = ?');
        $st->execute([
            opt_str('name', $in, 64) ?? get_role_by_id($pdo, $id)['name'],
            opt_str('description', $in, 255),
            $id,
        ]);
        json_respond(['role' => get_role_by_id($pdo, $id)]);
    }

    json_error('method_not_allowed', 405);
}
