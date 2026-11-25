<?php
/**
 * PROJECTS resource: /api/v1/projects  (Koordinator+).
 *  GET    /api/v1/projects            (?customer_id= optional)
 *  GET    /api/v1/projects/{id}
 *  POST   /api/v1/projects
 *  PUT    /api/v1/projects/{id}
 *  DELETE /api/v1/projects/{id}       (archive)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validate.php';

function project_row(array $r): array
{
    return [
        'id'                        => $r['id'],
        'name'                      => $r['name'],
        'project_number'            => $r['project_number'],
        'customer_id'               => $r['customer_id'],
        'case_number'               => $r['case_number'],
        'reference'                 => $r['reference'],
        'order_number'              => $r['order_number'],
        'start_date'                => $r['start_date'],
        'end_date'                  => $r['end_date'],
        'status'                    => $r['status'],
        'default_profile_id'        => $r['default_profile_id'],
        'requires_serial_number'    => (int)$r['requires_serial_number'] === 1,
        'requires_asset_tag'        => (int)$r['requires_asset_tag'] === 1,
        'requires_extra_confirmation'=> (int)$r['requires_extra_confirmation'] === 1,
        'certificate_text'          => $r['certificate_text'],
        'notes'                     => $r['notes'],
        'updated_at'                => $r['updated_at'],
    ];
}

const PROJECT_COLS = 'id, name, project_number, customer_id, case_number, reference,
    order_number, start_date, end_date, status, default_profile_id,
    requires_serial_number, requires_asset_tag, requires_extra_confirmation,
    certificate_text, notes, updated_at';

function get_project_row(PDO $pdo, string $id): ?array
{
    $st = $pdo->prepare(
        'SELECT ' . PROJECT_COLS . " FROM project WHERE id = ? AND deleted = 0"
    );
    $st->execute([$id]);
    $row = $st->fetch();
    return $row === false ? null : project_row($row);
}

function project_payload(array $in, array $cur): array
{
    return [
        req('name', $in),
        opt_str('project_number', $in, 64) ?? $cur['project_number'],
        opt_uuid('customer_id', $in) ?? require_uuid($cur['customer_id']),
        opt_str('case_number', $in, 64),
        opt_str('reference', $in, 255),
        opt_str('order_number', $in, 64),
        opt_str('start_date', $in, 32),
        opt_str('end_date', $in, 32),
        opt_enum('status', $in, ['active', 'completed', 'archived']) ?? $cur['status'],
        opt_uuid('default_profile_id', $in),
        opt_bool('requires_serial_number', $in, $cur['requires_serial_number']) ? 1 : 0,
        opt_bool('requires_asset_tag', $in, $cur['requires_asset_tag']) ? 1 : 0,
        opt_bool('requires_extra_confirmation', $in, $cur['requires_extra_confirmation']) ? 1 : 0,
        isset($in['certificate_text']) ? (string)$in['certificate_text'] : null,
        isset($in['notes']) ? (string)$in['notes'] : null,
    ];
}

function route_projects(string $method, string $path, PDO $pdo): bool
{
    if (!str_starts_with($path, '/api/v1/projects')) {
        return false;
    }
    $user = require_auth($pdo);
    require_role($pdo, $user, 'Administrator', 'Koordinator', 'Revisor');

    $rest  = substr($path, strlen('/api/v1/projects'));
    $parts = $rest === '' ? [] : array_values(array_filter(explode('/', $rest)));

    if ($method === 'GET' && $parts === []) {
        $sql = 'SELECT ' . PROJECT_COLS . ' FROM project WHERE deleted = 0';
        $params = [];
        $cust = $_GET['customer_id'] ?? null;
        if ($cust !== null) {
            $sql .= ' AND customer_id = ?';
            $params[] = require_uuid($cust);
        }
        if (isset($_GET['status'])) {
            $sql .= ' AND status = ?';
            $params[] = opt_enum('status', ['status' => $_GET['status']], ['active', 'completed', 'archived']);
        }
        $sql .= ' ORDER BY status, name';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        json_respond(['projects' => array_map('project_row', $st->fetchAll())]);
    }

    if ($method === 'POST' && $parts === []) {
        require_role($pdo, $user, 'Administrator', 'Koordinator');
        $in  = request_json();
        $cur = [
            'project_number' => null, 'customer_id' => null, 'status' => 'active',
            'requires_serial_number' => false, 'requires_asset_tag' => false,
            'requires_extra_confirmation' => false,
        ];
        $name = req('name', $in);
        $project_number = opt_str('project_number', $in, 64) ?? '';
        if ($project_number === '') {
            json_error('missing_field: project_number', 422);
        }
        $customer_id = opt_uuid('customer_id', $in) ?? null;
        if ($customer_id === null) {
            json_error('missing_field: customer_id', 422);
        }
        $chk = $pdo->prepare(
            'SELECT 1 FROM project WHERE customer_id = ? AND project_number = ? AND deleted = 0'
        );
        $chk->execute([$customer_id, $project_number]);
        if ($chk->fetch() !== false) {
            json_error('conflict: project_number for this customer already exists', 409);
        }

        $id = uuid_v4();
        $p = project_payload($in, $cur);
        $p[1] = $project_number;
        $p[2] = $customer_id;
        $st = $pdo->prepare(
            'INSERT INTO project
                (id, name, project_number, customer_id, case_number, reference, order_number,
                 start_date, end_date, status, default_profile_id, requires_serial_number,
                 requires_asset_tag, requires_extra_confirmation, certificate_text, notes, updated_at, deleted)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)'
        );
        $st->execute([$id, ...$p, now_iso()]);
        json_respond(['project' => get_project_row($pdo, $id)], 201);
    }

    if ($parts === []) {
        json_error('method_not_allowed', 405);
    }
    $id = require_uuid($parts[0]);

    if ($method === 'GET') {
        $p = get_project_row($pdo, $id);
        if ($p === null) {
            json_error('not_found', 404);
        }
        json_respond(['project' => $p]);
    }

    if ($method === 'DELETE') {
        require_role($pdo, $user, 'Administrator', 'Koordinator');
        $st = $pdo->prepare(
            "UPDATE project SET status = 'archived', deleted = 1, updated_at = ? WHERE id = ?"
        );
        $st->execute([now_iso(), $id]);
        json_respond(['ok' => true]);
    }

    if ($method === 'PUT') {
        require_role($pdo, $user, 'Administrator', 'Koordinator');
        $cur = get_project_row($pdo, $id);
        if ($cur === null) {
            json_error('not_found', 404);
        }
        $in = request_json();
        $p  = project_payload($in, $cur);
        $st = $pdo->prepare(
            'UPDATE project SET name=?, project_number=?, customer_id=?, case_number=?, reference=?,
                    order_number=?, start_date=?, end_date=?, status=?, default_profile_id=?,
                    requires_serial_number=?, requires_asset_tag=?, requires_extra_confirmation=?,
                    certificate_text=?, notes=?, updated_at=?
             WHERE id = ?'
        );
        $st->execute([...$p, now_iso(), $id]);
        json_respond(['project' => get_project_row($pdo, $id)]);
    }

    json_error('method_not_allowed', 405);
}
