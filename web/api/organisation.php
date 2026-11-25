<?php
/**
 * ORGANISATION resource: /api/v1/organisation  (singleton, Admin).
 *  GET    /api/v1/organisation
 *  PUT    /api/v1/organisation
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validate.php';

function organisation_row(array $r): array
{
    return [
        'id'              => $r['id'],
        'name'            => $r['name'],
        'organisation_id' => $r['organisation_id'],
        'contact'         => $r['contact'],
        'updated_at'      => $r['updated_at'],
    ];
}

function get_organisation(PDO $pdo): ?array
{
    $row = $pdo->query(
        'SELECT id, name, organisation_id, contact, updated_at FROM organisation LIMIT 1'
    )->fetch();
    return $row === false ? null : organisation_row($row);
}

function route_organisation(string $method, string $path, PDO $pdo): bool
{
    if ($path !== '/api/v1/organisation') {
        return false;
    }
    $user = require_auth($pdo);

    if ($method === 'GET') {
        if (!has_role($pdo, $user, 'Administrator', 'Koordinator', 'Revisor')) {
            json_error('forbidden', 403);
        }
        $o = get_organisation($pdo);
        if ($o === null) {
            json_error('not_found', 404);
        }
        json_respond(['organisation' => $o]);
    }

    if ($method === 'PUT') {
        require_role($pdo, $user, 'Administrator');
        $cur = get_organisation($pdo);
        $in  = request_json();
        if ($cur === null) {
            $id = uuid_v4();
            $st = $pdo->prepare(
                'INSERT INTO organisation (id, name, organisation_id, contact, updated_at)
                 VALUES (?,?,?,?,?)'
            );
            $st->execute([
                $id,
                opt_str('name', $in, 255) ?? '',
                opt_str('organisation_id', $in, 64) ?? '',
                opt_str('contact', $in, 255),
                now_iso(),
            ]);
            json_respond(['organisation' => organisation_row([
                'id' => $id,
                'name' => opt_str('name', $in, 255) ?? '',
                'organisation_id' => opt_str('organisation_id', $in, 64) ?? '',
                'contact' => opt_str('contact', $in, 255),
                'updated_at' => now_iso(),
            ])]);
        }
        $st = $pdo->prepare(
            'UPDATE organisation SET name = ?, organisation_id = ?, contact = ?, updated_at = ?
             WHERE id = ?'
        );
        $st->execute([
            opt_str('name', $in, 255) ?? $cur['name'],
            opt_str('organisation_id', $in, 64) ?? $cur['organisation_id'],
            opt_str('contact', $in, 255),
            now_iso(),
            $cur['id'],
        ]);
        json_respond(['organisation' => get_organisation($pdo)]);
    }

    json_error('method_not_allowed', 405);
}
