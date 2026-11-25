<?php
/**
 * CUSTOMERS resource: /api/v1/customers  (Koordinator+).
 *  GET    /api/v1/customers
 *  GET    /api/v1/customers/{id}
 *  POST   /api/v1/customers
 *  PUT    /api/v1/customers/{id}
 *  DELETE /api/v1/customers/{id}  (deactivate)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validate.php';

function customer_row(array $r): array
{
    return [
        'id'             => $r['id'],
        'name'           => $r['name'],
        'customer_number'=> $r['customer_number'],
        'address'        => $r['address'],
        'contact_person' => $r['contact_person'],
        'contact_email'  => $r['contact_email'],
        'contact_phone'  => $r['contact_phone'],
        'notes'          => $r['notes'],
        'active'         => (int)$r['active'] === 1,
        'updated_at'     => $r['updated_at'],
    ];
}

function get_customer_by_id(PDO $pdo, string $id): ?array
{
    $st = $pdo->prepare(
        'SELECT id, name, customer_number, address, contact_person, contact_email,
                contact_phone, notes, active, updated_at
         FROM customer WHERE id = ? AND deleted = 0'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    return $row === false ? null : customer_row($row);
}

function route_customers(string $method, string $path, PDO $pdo): bool
{
    if (!str_starts_with($path, '/api/v1/customers')) {
        return false;
    }
    $user = require_auth($pdo);
    require_role($pdo, $user, 'SystemAdministrator', 'Administrator', 'Koordinator', 'Revisor', 'KundeAdministrator', 'KundeRapporter');

    $rest  = substr($path, strlen('/api/v1/customers'));
    $parts = $rest === '' ? [] : array_values(array_filter(explode('/', $rest)));

    if ($method === 'GET' && $parts === []) {
        [$scopeSql, $scopeParams] = customer_scope_sql($pdo, $user, 'customer');
        $where = $scopeSql === '' ? '' : 'AND ' . $scopeSql;
        $st = $pdo->prepare(
            'SELECT id, name, customer_number, address, contact_person, contact_email,
                    contact_phone, notes, active, updated_at
             FROM customer WHERE deleted = 0 ' . $where . ' ORDER BY name'
        );
        $st->execute($scopeParams);
        json_respond(['customers' => array_map('customer_row', $st->fetchAll())]);
    }

    if ($method === 'POST' && $parts === []) {
        require_role($pdo, $user, 'Administrator', 'Koordinator');
        $in = request_json();
        $num = req('customer_number', $in);

        $chk = $pdo->prepare('SELECT 1 FROM customer WHERE customer_number = ? AND deleted = 0');
        $chk->execute([$num]);
        if ($chk->fetch() !== false) {
            json_error('conflict: customer_number already exists', 409);
        }

        $id = uuid_v4();
        $st = $pdo->prepare(
            'INSERT INTO customer
                (id, name, customer_number, address, contact_person, contact_email,
                 contact_phone, notes, active, updated_at, deleted)
             VALUES (?,?,?,?,?,?,?,?,1,?,0)'
        );
        $st->execute([
            $id,
            req('name', $in),
            $num,
            opt_str('address', $in, 255),
            opt_str('contact_person', $in, 255),
            opt_email('contact_email', $in),
            opt_str('contact_phone', $in, 64),
            isset($in['notes']) ? (string)$in['notes'] : null,
            now_iso(),
        ]);
        json_respond(['customer' => get_customer_by_id($pdo, $id)], 201);
    }

    if ($parts === []) {
        json_error('method_not_allowed', 405);
    }
    $id = require_uuid($parts[0]);

    if ($method === 'GET') {
        require_customer_access($pdo, $user, $id);
        $c = get_customer_by_id($pdo, $id);
        if ($c === null) {
            json_error('not_found', 404);
        }
        json_respond(['customer' => $c]);
    }

    if ($method === 'DELETE') {
        require_role($pdo, $user, 'SystemAdministrator', 'Administrator', 'Koordinator');
        require_customer_access($pdo, $user, $id);
        $st = $pdo->prepare(
            'UPDATE customer SET active = 0, deleted = 1, updated_at = ? WHERE id = ?'
        );
        $st->execute([now_iso(), $id]);
        json_respond(['ok' => true]);
    }

    if ($method === 'PUT') {
        require_role($pdo, $user, 'SystemAdministrator', 'Administrator', 'Koordinator');
        require_customer_access($pdo, $user, $id);
        $cur = get_customer_by_id($pdo, $id);
        if ($cur === null) {
            json_error('not_found', 404);
        }
        $in = request_json();
        $st = $pdo->prepare(
            'UPDATE customer SET name=?, customer_number=?, address=?, contact_person=?,
                    contact_email=?, contact_phone=?, notes=?, active=?, updated_at=?
             WHERE id = ?'
        );
        $st->execute([
            opt_str('name', $in, 255) ?? $cur['name'],
            opt_str('customer_number', $in, 64) ?? $cur['customer_number'],
            opt_str('address', $in, 255),
            opt_str('contact_person', $in, 255),
            opt_email('contact_email', $in),
            opt_str('contact_phone', $in, 64),
            isset($in['notes']) ? (string)$in['notes'] : null,
            opt_bool('active', $in, $cur['active']) ? 1 : 0,
            now_iso(),
            $id,
        ]);
        json_respond(['customer' => get_customer_by_id($pdo, $id)]);
    }

    json_error('method_not_allowed', 405);
}
