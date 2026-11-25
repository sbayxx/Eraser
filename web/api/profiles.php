<?php
/**
 * PROFILES resource: /api/v1/profiles  (Koordinator+ for writes).
 *  GET    /api/v1/profiles
 *  GET    /api/v1/profiles/{id}
 *  POST   /api/v1/profiles
 *  PUT    /api/v1/profiles/{id}
 *  DELETE /api/v1/profiles/{id}  (deactivate)
 *
 * Enum values follow the local Rust model (serde snake_case):
 *   method:        clear|purge|cryptographic_erase|device_sanitize|overwrite|custom
 *   verification:  readback|device_status|readback_and_device_status|none
 * List fields (allowed_media_types, standards, required_fields) are JSON arrays.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validate.php';

const PROFILE_METHOD_CODES = [
    'clear'               => 'CLEAR',
    'purge'               => 'PURGE',
    'cryptographic_erase' => 'CRYPTO_ERASE',
    'device_sanitize'     => 'DEVICE_SANITIZE',
    'overwrite'           => 'OVERWRITE',
    'custom'              => 'CUSTOM',
];

const PROFILE_METHODS = ['clear', 'purge', 'cryptographic_erase', 'device_sanitize', 'overwrite', 'custom'];
const PROFILE_VERIFICATIONS = ['readback', 'device_status', 'readback_and_device_status', 'none'];

function profile_row(array $r): array
{
    $method = $r['method'];
    return [
        'id'                  => $r['id'],
        'name'                => $r['name'],
        'version'             => $r['version'],
        'method'              => $method,
        'method_code'         => PROFILE_METHOD_CODES[$method] ?? strtoupper($method),
        'allowed_media_types' => json_decode($r['allowed_media_types'], true) ?: [],
        'standards'           => json_decode($r['standards'], true) ?: [],
        'overwrite_pattern'   => $r['overwrite_pattern'],
        'overwrite_passes'    => $r['overwrite_passes'] === null ? null : (int)$r['overwrite_passes'],
        'verification'        => $r['verification'],
        'required_fields'     => json_decode($r['required_fields'], true) ?: [],
        'approval_required'   => (int)$r['approval_required'] === 1,
        'certificate_text'    => $r['certificate_text'],
        'active'              => (int)$r['active'] === 1,
        'updated_at'          => $r['updated_at'],
    ];
}

function get_profile_row(PDO $pdo, string $id): ?array
{
    $st = $pdo->prepare(
        'SELECT id, name, version, method, allowed_media_types, standards, overwrite_pattern,
                overwrite_passes, verification, required_fields, approval_required,
                certificate_text, active, updated_at
         FROM erasure_profile WHERE id = ? AND deleted = 0'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    return $row === false ? null : profile_row($row);
}

function validate_profile_payload(array $in): void
{
    $method = $in['method'] ?? null;
    if ($method === 'overwrite' && empty($in['overwrite_pattern'])) {
        json_error('invalid_field: overwrite must specify a pattern', 422);
    }
    $passes = $in['overwrite_passes'] ?? null;
    if ($passes !== null && (int)$passes === 0) {
        json_error('invalid_field: overwrite_passes must be > 0', 422);
    }
    if (($in['allowed_media_types'] ?? []) === []) {
        json_error('invalid_field: at least one allowed_media_type required', 422);
    }
    if (($in['verification'] ?? null) === 'none') {
        json_error('invalid_field: verification may not be none', 422);
    }
}

function route_profiles(string $method, string $path, PDO $pdo): bool
{
    if (!str_starts_with($path, '/api/v1/profiles')) {
        return false;
    }
    $user = require_auth($pdo);
    require_role($pdo, $user, 'Administrator', 'Koordinator', 'Revisor');

    $rest  = substr($path, strlen('/api/v1/profiles'));
    $parts = $rest === '' ? [] : array_values(array_filter(explode('/', $rest)));

    if ($method === 'GET' && $parts === []) {
        $sql  = 'SELECT id, name, version, method, allowed_media_types, standards, overwrite_pattern,
                        overwrite_passes, verification, required_fields, approval_required,
                        certificate_text, active, updated_at
                 FROM erasure_profile WHERE deleted = 0';
        $params = [];
        if (isset($_GET['active'])) {
            $sql .= ' AND active = ?';
            $params[] = opt_bool('active', ['active' => $_GET['active']]) ? 1 : 0;
        }
        $sql .= ' ORDER BY name';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        json_respond(['profiles' => array_map('profile_row', $st->fetchAll())]);
    }

    if ($method === 'POST' && $parts === []) {
        require_role($pdo, $user, 'Administrator', 'Koordinator');
        $in = request_json();
        $method = opt_enum('method', $in, PROFILE_METHODS) ?? null;
        $verification = opt_enum('verification', $in, PROFILE_VERIFICATIONS) ?? null;
        if ($method === null) {
            json_error('missing_field: method', 422);
        }
        if ($verification === null) {
            json_error('missing_field: verification', 422);
        }
        validate_profile_payload([
            'method' => $method,
            'overwrite_pattern' => $in['overwrite_pattern'] ?? null,
            'overwrite_passes'  => $in['overwrite_passes'] ?? null,
            'allowed_media_types'=> $in['allowed_media_types'] ?? [],
            'verification'      => $verification,
        ]);

        $id = uuid_v4();
        $st = $pdo->prepare(
            'INSERT INTO erasure_profile
                (id, name, version, method, allowed_media_types, standards, overwrite_pattern,
                 overwrite_passes, verification, required_fields, approval_required,
                 certificate_text, active, updated_at, deleted)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,0)'
        );
        $st->execute([
            $id,
            req('name', $in),
            opt_str('version', $in, 32) ?? '1',
            $method,
            json_encode(opt_string_list('allowed_media_types', $in) ?? [], JSON_UNESCAPED_SLASHES),
            json_encode(opt_string_list('standards', $in) ?? [], JSON_UNESCAPED_SLASHES),
            opt_str('overwrite_pattern', $in, 255),
            isset($in['overwrite_passes']) ? (int)$in['overwrite_passes'] : null,
            $verification,
            json_encode(opt_string_list('required_fields', $in) ?? [], JSON_UNESCAPED_SLASHES),
            opt_bool('approval_required', $in) ? 1 : 0,
            isset($in['certificate_text']) ? (string)$in['certificate_text'] : null,
            now_iso(),
        ]);
        json_respond(['profile' => get_profile_row($pdo, $id)], 201);
    }

    if ($parts === []) {
        json_error('method_not_allowed', 405);
    }
    $id = require_uuid($parts[0]);

    if ($method === 'GET') {
        $p = get_profile_row($pdo, $id);
        if ($p === null) {
            json_error('not_found', 404);
        }
        json_respond(['profile' => $p]);
    }

    if ($method === 'DELETE') {
        require_role($pdo, $user, 'Administrator', 'Koordinator');
        $st = $pdo->prepare(
            'UPDATE erasure_profile SET active = 0, deleted = 1, updated_at = ? WHERE id = ?'
        );
        $st->execute([now_iso(), $id]);
        json_respond(['ok' => true]);
    }

    if ($method === 'PUT') {
        require_role($pdo, $user, 'Administrator', 'Koordinator');
        $cur = get_profile_row($pdo, $id);
        if ($cur === null) {
            json_error('not_found', 404);
        }
        $in = request_json();
        $method = opt_enum('method', $in, PROFILE_METHODS) ?? $cur['method'];
        $verification = opt_enum('verification', $in, PROFILE_VERIFICATIONS) ?? $cur['verification'];
        validate_profile_payload([
            'method' => $method,
            'overwrite_pattern' => $in['overwrite_pattern'] ?? $cur['overwrite_pattern'],
            'overwrite_passes'  => $in['overwrite_passes'] ?? $cur['overwrite_passes'],
            'allowed_media_types'=> $in['allowed_media_types'] ?? $cur['allowed_media_types'],
            'verification'      => $verification,
        ]);

        $st = $pdo->prepare(
            'UPDATE erasure_profile SET name=?, version=?, method=?, allowed_media_types=?,
                    standards=?, overwrite_pattern=?, overwrite_passes=?, verification=?,
                    required_fields=?, approval_required=?, certificate_text=?, active=?, updated_at=?
             WHERE id = ?'
        );
        $st->execute([
            opt_str('name', $in, 255) ?? $cur['name'],
            opt_str('version', $in, 32) ?? $cur['version'],
            $method,
            json_encode($in['allowed_media_types'] ?? $cur['allowed_media_types'], JSON_UNESCAPED_SLASHES),
            json_encode($in['standards'] ?? $cur['standards'], JSON_UNESCAPED_SLASHES),
            opt_str('overwrite_pattern', $in, 255),
            isset($in['overwrite_passes']) ? (int)$in['overwrite_passes'] : $cur['overwrite_passes'],
            $verification,
            json_encode($in['required_fields'] ?? $cur['required_fields'], JSON_UNESCAPED_SLASHES),
            opt_bool('approval_required', $in, $cur['approval_required']) ? 1 : 0,
            isset($in['certificate_text']) ? (string)$in['certificate_text'] : $cur['certificate_text'],
            opt_bool('active', $in, $cur['active']) ? 1 : 0,
            now_iso(),
            $id,
        ]);
        json_respond(['profile' => get_profile_row($pdo, $id)]);
    }

    json_error('method_not_allowed', 405);
}
