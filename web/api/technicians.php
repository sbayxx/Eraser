<?php
/**
 * TECHNICIANS resource: /api/v1/technicians  (Koordinator+).
 *  GET    /api/v1/technicians
 *  GET    /api/v1/technicians/{id}
 *  POST   /api/v1/technicians
 *  PUT    /api/v1/technicians/{id}
 *  DELETE /api/v1/technicians/{id}  (deactivate)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validate.php';

function technician_row(PDO $pdo, array $r): array
{
    $roles = [];
    $st = $pdo->prepare(
        'SELECT r.id, r.name, r.description
         FROM role r JOIN technician_role tr ON tr.role_id = r.id
         WHERE tr.technician_id = ? ORDER BY r.name'
    );
    $st->execute([$r['id']]);
    foreach ($st->fetchAll() as $rr) {
        $roles[] = ['id' => $rr['id'], 'name' => $rr['name'], 'description' => $rr['description']];
    }
    return [
        'id'                => $r['id'],
        'name'              => $r['name'],
        'technician_id'     => $r['technician_id'],
        'organisation'      => $r['organisation'],
        'initials'          => $r['initials'],
        'email'             => $r['email'],
        'preferred_language'=> $r['preferred_language'],
        'active'            => (int)$r['active'] === 1,
        'roles'             => $roles,
        'updated_at'        => $r['updated_at'],
    ];
}

function get_technician_row(PDO $pdo, string $id): ?array
{
    $st = $pdo->prepare(
        'SELECT id, name, technician_id, organisation, initials, email,
                preferred_language, active, updated_at
         FROM technician WHERE id = ? AND deleted = 0'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    return $row === false ? null : technician_row($pdo, $row);
}

function set_technician_roles(PDO $pdo, string $technicianId, array $roleNames): void
{
    $del = $pdo->prepare('DELETE FROM technician_role WHERE technician_id = ?');
    $del->execute([$technicianId]);
    if ($roleNames === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($roleNames), '?'));
    $st = $pdo->prepare("SELECT id FROM role WHERE name IN ($placeholders)");
    $st->execute($roleNames);
    $ids = array_column($st->fetchAll(), 'id');
    $ins = $pdo->prepare('INSERT IGNORE INTO technician_role (technician_id, role_id) VALUES (?,?)');
    foreach ($ids as $rid) {
        $ins->execute([$technicianId, $rid]);
    }
}

function route_technicians(string $method, string $path, PDO $pdo): bool
{
    if (!str_starts_with($path, '/api/v1/technicians')) {
        return false;
    }
    $user = require_auth($pdo);
    require_role($pdo, $user, 'Administrator', 'Koordinator', 'Revisor');

    $rest  = substr($path, strlen('/api/v1/technicians'));
    $parts = $rest === '' ? [] : array_values(array_filter(explode('/', $rest)));

    if ($method === 'GET' && $parts === []) {
        $rows = $pdo->query(
            'SELECT id, name, technician_id, organisation, initials, email,
                    preferred_language, active, updated_at
             FROM technician WHERE deleted = 0 ORDER BY name'
        )->fetchAll();
        json_respond(['technicians' => array_map(fn($r) => technician_row($pdo, $r), $rows)]);
    }

    if ($method === 'POST' && $parts === []) {
        require_role($pdo, $user, 'Administrator', 'Koordinator');
        $in = request_json();
        $id = uuid_v4();
        $st = $pdo->prepare(
            'INSERT INTO technician
                (id, name, technician_id, organisation, initials, email,
                 preferred_language, active, updated_at, deleted)
             VALUES (?,?,?,?,?,?,?,1,?,0)'
        );
        $st->execute([
            $id,
            req('name', $in),
            opt_str('technician_id', $in, 64),
            opt_str('organisation', $in, 255),
            opt_str('initials', $in, 16),
            opt_email('email', $in),
            opt_enum('preferred_language', $in, ['da', 'en']),
            now_iso(),
        ]);
        set_technician_roles($pdo, $id, opt_string_list('roles', $in) ?? []);
        json_respond(['technician' => get_technician_row($pdo, $id)], 201);
    }

    if ($parts === []) {
        json_error('method_not_allowed', 405);
    }
    $id = require_uuid($parts[0]);

    if ($method === 'GET') {
        $t = get_technician_row($pdo, $id);
        if ($t === null) {
            json_error('not_found', 404);
        }
        json_respond(['technician' => $t]);
    }

    if ($method === 'DELETE') {
        require_role($pdo, $user, 'Administrator', 'Koordinator');
        $st = $pdo->prepare(
            'UPDATE technician SET active = 0, deleted = 1, updated_at = ? WHERE id = ?'
        );
        $st->execute([now_iso(), $id]);
        json_respond(['ok' => true]);
    }

    if ($method === 'PUT') {
        require_role($pdo, $user, 'Administrator', 'Koordinator');
        $cur = get_technician_row($pdo, $id);
        if ($cur === null) {
            json_error('not_found', 404);
        }
        $in = request_json();
        $st = $pdo->prepare(
            'UPDATE technician SET name=?, technician_id=?, organisation=?, initials=?,
                    email=?, preferred_language=?, active=?, updated_at=?
             WHERE id = ?'
        );
        $st->execute([
            opt_str('name', $in, 255) ?? $cur['name'],
            opt_str('technician_id', $in, 64),
            opt_str('organisation', $in, 255),
            opt_str('initials', $in, 16),
            opt_email('email', $in),
            opt_enum('preferred_language', $in, ['da', 'en']),
            opt_bool('active', $in, $cur['active']) ? 1 : 0,
            now_iso(),
            $id,
        ]);
        if (isset($in['roles']) && is_array($in['roles'])) {
            set_technician_roles($pdo, $id, $in['roles']);
        }
        json_respond(['technician' => get_technician_row($pdo, $id)]);
    }

    json_error('method_not_allowed', 405);
}
