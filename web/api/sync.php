<?php
/**
 * SYNC + QUERY resources.
 *
 * The local Management app authenticates either as a web user (Bearer) or via
 * the shared sync token (X-Sync-Token header) for the push/pull endpoints.
 *
 *   GET  /api/v1/query/master-data   full master config
 *   GET  /api/v1/query/sessions      sessions + certificates (reports)
 *   GET  /api/v1/sync/pull-config    master data changed since ?since=<updated_at>
 *   POST /api/v1/sync/push-results   upsert sessions + certificates from a stick
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validate.php';

function sync_configured_token(): ?string
{
    $t = app_config()['app']['sync_token'] ?? null;
    return is_string($t) && $t !== '' && !str_starts_with($t, 'SHARED') ? $t : null;
}

/** Authorise an API user or the shared Management sync credential. */
function require_sync_auth(PDO $pdo, string ...$roles): array
{
    $header = $_SERVER['HTTP_X_SYNC_TOKEN'] ?? '';
    $shared = sync_configured_token();
    if ($shared !== null && is_string($header) && hash_equals($shared, $header)) {
        return ['sync' => true, 'roles' => ['Administrator']];
    }
    $user = require_auth($pdo);
    if ($roles !== []) {
        require_role($pdo, $user, ...$roles);
    }
    return $user;
}

function master_data(PDO $pdo): array
{
    $org = $pdo->query(
        'SELECT id, name, organisation_id, contact, updated_at FROM organisation LIMIT 1'
    )->fetch() ?: null;

    $customers = $pdo->query(
        'SELECT id, name, customer_number, address, contact_person, contact_email,
                contact_phone, notes, active, updated_at
         FROM customer WHERE deleted = 0 ORDER BY name'
    )->fetchAll();

    $projects = $pdo->query(
        'SELECT id, name, project_number, customer_id, case_number, reference, order_number,
                start_date, end_date, status, default_profile_id, requires_serial_number,
                requires_asset_tag, requires_extra_confirmation, certificate_text, notes, updated_at
         FROM project WHERE deleted = 0 ORDER BY name'
    )->fetchAll();

    $technicians = $pdo->query(
        'SELECT id, name, technician_id, organisation, initials, email, preferred_language,
                active, updated_at
         FROM technician WHERE deleted = 0 ORDER BY name'
    )->fetchAll();
    foreach ($technicians as &$t) {
        $st = $pdo->prepare(
            'SELECT r.name FROM role r JOIN technician_role tr ON tr.role_id = r.id
             WHERE tr.technician_id = ? ORDER BY r.name'
        );
        $st->execute([$t['id']]);
        $t['roles'] = $st->fetchAll(PDO::FETCH_COLUMN);
    }
    unset($t);

    $roles = $pdo->query('SELECT id, name, description FROM role ORDER BY name')->fetchAll();

    $profiles = $pdo->query(
        'SELECT id, name, version, method, allowed_media_types, standards, overwrite_pattern,
                overwrite_passes, verification, required_fields, approval_required,
                certificate_text, active, updated_at
         FROM erasure_profile WHERE deleted = 0 AND active = 1 ORDER BY name'
    )->fetchAll();
    foreach ($profiles as &$p) {
        $p['allowed_media_types'] = json_decode($p['allowed_media_types'], true) ?: [];
        $p['standards']           = json_decode($p['standards'], true) ?: [];
        $p['required_fields']     = json_decode($p['required_fields'], true) ?: [];
        $p['overwrite_passes']    = $p['overwrite_passes'] === null ? null : (int)$p['overwrite_passes'];
    }
    unset($p);

    return [
        'organisation' => $org,
        'customers'    => $customers,
        'projects'     => $projects,
        'technicians'  => $technicians,
        'roles'        => $roles,
        'profiles'     => $profiles,
        'generated_at' => now_iso(),
    ];
}

/** Link a technician to roles by name (role names must already exist). */
function sync_set_technician_roles(PDO $pdo, string $technicianId, array $roleNames): void
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

function route_sync(string $method, string $path, PDO $pdo): bool
{
    if ($path === '/api/v1/query/master-data' && $method === 'GET') {
        require_sync_auth($pdo, ...UNRESTRICTED_ROLES());
        json_respond(['data' => master_data($pdo)]);
    }

    if ($path === '/api/v1/sync/pull-config' && $method === 'GET') {
        require_sync_auth($pdo, ...UNRESTRICTED_ROLES());
        $since = $_GET['since'] ?? null;
        $data  = master_data($pdo);
        if ($since) {
            foreach (['customers', 'projects', 'technicians', 'profiles'] as $key) {
                $data[$key] = array_values(array_filter(
                    $data[$key],
                    fn($row) => ($row['updated_at'] ?? '') > $since
                ));
            }
        }
        json_respond(['data' => $data]);
    }

    if ($path === '/api/v1/query/sessions' && $method === 'GET') {
        $actor = require_sync_auth($pdo);
        [$scope, $customerIds] = isset($actor['sync']) ? ['', []] : customer_scope_sql($pdo, $actor, 's');
        $limit = max(0, min((int)($_GET['limit'] ?? 100), 500));
        $status = $_GET['status'] ?? null;
        $sql = 'SELECT id, boot_session_id, usb_installation_id, phase, operator_name,
                       customer_id, customer_name, project_name, project_number, case_number,
                       reference, order_number, asset_tag, started_at, finished_at, final_status
                FROM erasure_session s WHERE s.deleted = 0';
        $params = $customerIds;
        if ($scope !== '') {
            $sql .= ' AND ' . $scope;
        }
        if ($status !== null) {
            $sql .= ' AND final_status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY started_at DESC LIMIT ' . (int)$limit;
        $st = $pdo->prepare($sql);
        $st->execute($params);

        $certSql = 'SELECT id, session_id, certificate_hash, status, template_version, generation_date,
                    qr_reference, log_reference, product_version, customer_name, customer_number,
                    project_name, project_number, case_number, reference, order_number,
                    computer_manufacturer, computer_model, computer_serial, asset_tag,
                    drive_manufacturer, drive_model, drive_serial, drive_firmware,
                    drive_capacity_bytes, drive_media_type, drive_interface, profile_name,
                    actual_method, started_at, finished_at, notes, verification_method,
                    verification_scope, verification_result, technician_name, technician_id,
                    organisation, updated_at
             FROM certificate WHERE deleted = 0';
        if ($scope !== '') {
            $certSql .= ' AND EXISTS (SELECT 1 FROM erasure_session s
                WHERE s.id = certificate.session_id AND s.deleted = 0 AND ' . $scope . ')';
        }
        $certQuery = $pdo->prepare($certSql . ' ORDER BY generation_date DESC');
        $certQuery->execute($customerIds);
        $certs = $certQuery->fetchAll();

        json_respond(['sessions' => $st->fetchAll(), 'certificates' => $certs]);
    }

    if ($path === '/api/v1/sync/push-results' && $method === 'POST') {
        require_sync_auth($pdo, 'SystemAdministrator', 'Administrator', 'Koordinator');
        $in = request_json();
        $stickId = $in['usb_installation_id'] ?? null;
        $sessions = $in['sessions'] ?? [];
        $certificates = $in['certificates'] ?? [];

        if (!is_array($sessions) || !is_array($certificates)) {
            json_error('invalid_payload', 422);
        }

        $created = 0;
        $updated = 0;

        foreach ($sessions as $s) {
            if (!is_array($s) || !isset($s['id'], $s['boot_session_id'], $s['started_at'])) {
                continue;
            }
            $syncKey = (string)($s['sync_key'] ?? $s['id']);
            $existing = $pdo->prepare('SELECT id FROM erasure_session WHERE sync_key = ? OR id = ?');
            $existing->execute([$syncKey, $s['id']]);
            $row = $existing->fetch();

            $payload = json_encode($s, JSON_UNESCAPED_SLASHES);
            if ($row === false) {
                $ins = $pdo->prepare(
                    'INSERT INTO erasure_session
                        (id, boot_session_id, usb_installation_id, phase, operator_name,
                         customer_id, customer_name, project_name, project_number, case_number,
                         reference, order_number, asset_tag, started_at, finished_at, final_status,
                         payload_json, sync_key, updated_at, deleted)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)'
                );
                $ins->execute([
                    $s['id'],
                    $s['boot_session_id'],
                    $stickId ?? $s['usb_installation_id'] ?? null,
                    $s['phase'] ?? 'unknown',
                    $s['operator_name'] ?? null,
                    $s['customer_id'] ?? null,
                    $s['customer_name'] ?? null,
                    $s['project_name'] ?? null,
                    $s['project_number'] ?? null,
                    $s['case_number'] ?? null,
                    $s['reference'] ?? null,
                    $s['order_number'] ?? null,
                    $s['asset_tag'] ?? null,
                    $s['started_at'],
                    $s['finished_at'] ?? null,
                    $s['final_status'] ?? null,
                    $payload,
                    $syncKey,
                    now_iso(),
                ]);
                $created++;
            } else {
                $upd = $pdo->prepare(
                    'UPDATE erasure_session SET phase=?, final_status=?, finished_at=?,
                            payload_json=?, operator_name=?, updated_at=? WHERE id = ?'
                );
                $upd->execute([
                    $s['phase'] ?? null,
                    $s['final_status'] ?? null,
                    $s['finished_at'] ?? null,
                    $payload,
                    $s['operator_name'] ?? null,
                    now_iso(),
                    $row['id'],
                ]);
                $updated++;
            }
        }

        foreach ($certificates as $c) {
            if (!is_array($c) || !isset($c['id'])) {
                continue;
            }
            $syncKey = (string)($c['sync_key'] ?? $c['id']);
            // Legacy/older certs carry an empty content hash; store NULL so the
            // UNIQUE(certificate_hash) key accepts several such records and one
            // cannot clobber another via a hash-collision upsert.
            $hash = (isset($c['certificate_hash']) && $c['certificate_hash'] !== '')
                ? $c['certificate_hash'] : null;
            // Optional authentic certificate PDF (generated by the Boot system).
            $pdfRaw = (isset($c['pdf_base64']) && is_string($c['pdf_base64']) && $c['pdf_base64'] !== '')
                ? base64_decode($c['pdf_base64'], true)
                : null;
            $pdfName = (isset($c['pdf_filename']) && is_string($c['pdf_filename']) && $c['pdf_filename'] !== '')
                ? mb_substr($c['pdf_filename'], 0, 255)
                : null;

            $idExists = $pdo->prepare('SELECT id, pdf_bytes FROM certificate WHERE id = ?');
            $idExists->execute([$c['id']]);
            $existingPdfRow = $idExists->fetch();
            // Preserve already-stored PDF bytes when the client does not resend them.
            $pdfValue = $pdfRaw ?? ($existingPdfRow !== false ? $existingPdfRow['pdf_bytes'] : null);

            $cols = 'id, session_id, certificate_hash, pdf_bytes, pdf_filename,
                     status, template_version, generation_date,
                     qr_reference, log_reference, product_version, customer_name, customer_number,
                     project_name, project_number, case_number, reference, order_number,
                     computer_manufacturer, computer_model, computer_serial, asset_tag,
                     drive_manufacturer, drive_model, drive_serial, drive_firmware,
                     drive_capacity_bytes, drive_media_type, drive_interface, profile_name,
                     actual_method, started_at, finished_at, notes, verification_method,
                     verification_scope, verification_result, verification_deviations,
                     technician_name, technician_id, organisation, sync_key, updated_at, deleted';
            $vals = [
                $c['id'],
                $c['session_id'] ?? null,
                $hash,
                $pdfValue,
                $pdfName,
                $c['status'] ?? 'PASS',
                $c['template_version'] ?? null,
                $c['generation_date'] ?? null,
                $c['qr_reference'] ?? null,
                $c['log_reference'] ?? null,
                $c['product_version'] ?? null,
                $c['customer_name'],
                $c['customer_number'],
                $c['project_name'] ?? null,
                $c['project_number'] ?? null,
                $c['case_number'] ?? null,
                $c['reference'] ?? null,
                $c['order_number'] ?? null,
                $c['computer_manufacturer'] ?? null,
                $c['computer_model'] ?? null,
                $c['computer_serial'] ?? null,
                $c['asset_tag'] ?? null,
                $c['drive_manufacturer'] ?? null,
                $c['drive_model'] ?? null,
                $c['drive_serial'] ?? null,
                $c['drive_firmware'] ?? null,
                $c['drive_capacity_bytes'] ?? 0,
                $c['drive_media_type'] ?? null,
                $c['drive_interface'] ?? null,
                $c['profile_name'] ?? null,
                $c['actual_method'] ?? null,
                $c['started_at'] ?? null,
                $c['finished_at'] ?? null,
                $c['notes'] ?? null,
                $c['verification_method'] ?? null,
                $c['verification_scope'] ?? null,
                $c['verification_result'] ?? null,
                json_encode($c['verification_deviations'] ?? [], JSON_UNESCAPED_SLASHES),
                $c['technician_name'] ?? null,
                $c['technician_id'] ?? null,
                $c['organisation'] ?? null,
                $syncKey,
                now_iso(),
                0,
            ];

            $ph = implode(',', array_fill(0, count($vals), '?'));
            if ($existingPdfRow === false) {
                $ins = $pdo->prepare("INSERT INTO certificate ($cols) VALUES ($ph)");
                $ins->execute($vals);
                $created++;
            } else {
                $updCols = [
                    'session_id', 'certificate_hash', 'pdf_bytes', 'pdf_filename',
                    'status', 'template_version', 'generation_date',
                    'qr_reference', 'log_reference', 'product_version',
                    'customer_name', 'customer_number',
                    'project_name', 'project_number', 'case_number', 'reference', 'order_number',
                    'computer_manufacturer', 'computer_model', 'computer_serial', 'asset_tag',
                    'drive_manufacturer', 'drive_model', 'drive_serial', 'drive_firmware',
                    'drive_capacity_bytes', 'drive_media_type', 'drive_interface', 'profile_name',
                    'actual_method', 'started_at', 'finished_at', 'notes', 'verification_method',
                    'verification_scope', 'verification_result', 'verification_deviations',
                    'technician_name', 'technician_id', 'organisation', 'sync_key', 'updated_at', 'deleted',
                ];
                $assigns = implode(',', array_map(fn($col) => "`$col`=?", $updCols));
                $upd = $pdo->prepare("UPDATE certificate SET $assigns WHERE id = ?");
                $upd->execute(array_merge(
                    array_slice($vals, 1), // skip id
                    [$c['id']],
                ));
                $updated++;
            }
        }

        if ($stickId) {
            $lg = $pdo->prepare(
                'INSERT INTO sync_log
                    (stick_installation_id, direction, entity, occurred_at, status)
                 VALUES (?,?,?,?,?)'
            );
            $lg->execute([
                $stickId,
                'up',
                'session+certificate',
                now_iso(),
                "{$created} created, {$updated} updated",
            ]);
        }

        json_respond([
            'ok'      => true,
            'created' => $created,
            'updated' => $updated,
            'synced_at' => now_iso(),
        ]);
    }

    if ($path === '/api/v1/sync/push-master' && $method === 'POST') {
        require_sync_auth($pdo, 'SystemAdministrator', 'Administrator');
        $in = request_json();
        $counts = [
            'organisation' => 0,
            'customers'    => 0,
            'projects'     => 0,
            'technicians'  => 0,
        ];
        $pdo->beginTransaction();

        try {
            // --- Organisation (upsert a single row) ---
            if (isset($in['organisation']) && is_array($in['organisation'])) {
                $org = $in['organisation'];
                $name = opt_str('name', $org, 255) ?? '';
                $orgId = opt_str('organisation_id', $org, 64) ?? '';
                if ($name !== '') {
                    $cur = $pdo->query('SELECT id, deleted FROM organisation LIMIT 1')->fetch();
                    if ($cur === false) {
                        $pdo->prepare(
                            'INSERT INTO organisation (id, name, organisation_id, contact, updated_at, deleted)
                             VALUES (?,?,?,?,?,0)'
                        )->execute([uuid_v4(), $name, $orgId, opt_str('contact', $org, 255), now_iso()]);
                    } else {
                        $pdo->prepare(
                            'UPDATE organisation SET name=?, organisation_id=?, contact=?, updated_at=?
                             WHERE id = ?'
                        )->execute([$name, $orgId, opt_str('contact', $org, 255), now_iso(), $cur['id']]);
                    }
                    $counts['organisation']++;
                }
            }

            // --- Customers (upsert keyed on id, fallback customer_number) ---
            if (isset($in['customers']) && is_array($in['customers'])) {
                $chkId = $pdo->prepare('SELECT id FROM customer WHERE id = ?');
                $chkNum = $pdo->prepare('SELECT id FROM customer WHERE customer_number = ? AND id != ?');
                $ins = $pdo->prepare(
                    'INSERT INTO customer (id, name, customer_number, address, contact_person,
                         contact_email, contact_phone, notes, active, updated_at, deleted)
                     VALUES (?,?,?,?,?,?,?,?,?,?,0)'
                );
                $upd = $pdo->prepare(
                    'UPDATE customer SET name=?, customer_number=?, address=?, contact_person=?,
                         contact_email=?, contact_phone=?, notes=?, active=?, updated_at=?
                     WHERE id = ?'
                );
                foreach ($in['customers'] as $c) {
                    if (!is_array($c) || !isset($c['id']) || !isset($c['name'], $c['customer_number'])) {
                        continue;
                    }
                    $chkId->execute([$c['id']]);
                    $row = $chkId->fetch();
                    if ($row === false) {
                        $chkNum->execute([$c['customer_number'], $c['id']]);
                        $row = $chkNum->fetch();
                    }
                    $vals = [
                        $c['name'],
                        $c['customer_number'],
                        $c['address'] ?? null,
                        $c['contact_person'] ?? null,
                        $c['contact_email'] ?? null,
                        $c['contact_phone'] ?? null,
                        $c['notes'] ?? null,
                        isset($c['active']) ? ((int)$c['active'] ? 1 : 0) : 1,
                        now_iso(),
                    ];
                    if ($row === false) {
                        $ins->execute([$c['id'], ...$vals]);
                    } else {
                        $upd->execute([...$vals, $row['id']]);
                    }
                    $counts['customers']++;
                }
            }

            // --- Projects (upsert keyed on id, fallback customer_id+project_number) ---
            if (isset($in['projects']) && is_array($in['projects'])) {
                $chkId = $pdo->prepare('SELECT id FROM project WHERE id = ?');
                $chkUniq = $pdo->prepare(
                    'SELECT id FROM project WHERE customer_id = ? AND project_number = ? AND id != ?'
                );
                $ins = $pdo->prepare(
                    'INSERT INTO project (id, name, project_number, customer_id, case_number, reference,
                         order_number, start_date, end_date, status, default_profile_id,
                         requires_serial_number, requires_asset_tag, requires_extra_confirmation,
                         certificate_text, notes, updated_at, deleted)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)'
                );
                $upd = $pdo->prepare(
                    'UPDATE project SET name=?, project_number=?, customer_id=?, case_number=?,
                         reference=?, order_number=?, start_date=?, end_date=?, status=?,
                         default_profile_id=?, requires_serial_number=?, requires_asset_tag=?,
                         requires_extra_confirmation=?, certificate_text=?, notes=?, updated_at=?
                     WHERE id = ?'
                );
                foreach ($in['projects'] as $p) {
                    if (!is_array($p) || !isset($p['id'], $p['name'], $p['project_number'], $p['customer_id'])) {
                        continue;
                    }
                    $chkId->execute([$p['id']]);
                    $row = $chkId->fetch();
                    if ($row === false) {
                        $chkUniq->execute([$p['customer_id'], $p['project_number'], $p['id']]);
                        $row = $chkUniq->fetch();
                    }
                    $vals = [
                        $p['name'],
                        $p['project_number'],
                        $p['customer_id'],
                        $p['case_number'] ?? null,
                        $p['reference'] ?? null,
                        $p['order_number'] ?? null,
                        $p['start_date'] ?? null,
                        $p['end_date'] ?? null,
                        opt_enum('status', $p, ['active', 'completed', 'archived']) ?? 'active',
                        $p['default_profile_id'] ?? null,
                        isset($p['requires_serial_number']) ? ((int)$p['requires_serial_number'] ? 1 : 0) : 0,
                        isset($p['requires_asset_tag']) ? ((int)$p['requires_asset_tag'] ? 1 : 0) : 0,
                        isset($p['requires_extra_confirmation']) ? ((int)$p['requires_extra_confirmation'] ? 1 : 0) : 0,
                        $p['certificate_text'] ?? null,
                        $p['notes'] ?? null,
                        now_iso(),
                    ];
                    if ($row === false) {
                        $ins->execute([$p['id'], ...$vals]);
                    } else {
                        $upd->execute([...$vals, $row['id']]);
                    }
                    $counts['projects']++;
                }
            }

            // --- Technicians (upsert keyed on id) ---
            if (isset($in['technicians']) && is_array($in['technicians'])) {
                $chkId = $pdo->prepare('SELECT id FROM technician WHERE id = ?');
                $ins = $pdo->prepare(
                    'INSERT INTO technician (id, name, technician_id, organisation, initials, email,
                         preferred_language, active, updated_at, deleted)
                     VALUES (?,?,?,?,?,?,?,?,?,0)'
                );
                $upd = $pdo->prepare(
                    'UPDATE technician SET name=?, technician_id=?, organisation=?, initials=?,
                         email=?, preferred_language=?, active=?, updated_at=?
                     WHERE id = ?'
                );
                foreach ($in['technicians'] as $t) {
                    if (!is_array($t) || !isset($t['id'], $t['name'])) {
                        continue;
                    }
                    $chkId->execute([$t['id']]);
                    $row = $chkId->fetch();
                    $vals = [
                        $t['name'],
                        $t['technician_id'] ?? null,
                        $t['organisation'] ?? null,
                        $t['initials'] ?? null,
                        $t['email'] ?? null,
                        opt_enum('preferred_language', $t, ['da', 'en']),
                        isset($t['active']) ? ((int)$t['active'] ? 1 : 0) : 1,
                        now_iso(),
                    ];
                    if ($row === false) {
                        $ins->execute([$t['id'], ...$vals]);
                    } else {
                        $upd->execute([...$vals, $row['id']]);
                    }
                    if (isset($t['roles']) && is_array($t['roles'])) {
                        sync_set_technician_roles($pdo, $t['id'], array_map('strval', $t['roles']));
                    }
                    $counts['technicians']++;
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_error('push_master_failed: ' . $e->getMessage(), 500);
        }

        json_respond([
            'ok'      => true,
            'pushed'  => $counts,
            'synced_at' => now_iso(),
        ]);
    }

    return false;
}
