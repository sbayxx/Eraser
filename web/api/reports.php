<?php
/**
 * REPORTS resource: server-side PDF compilation of pushed certificates.
 *
 *   POST /api/v1/reports/generate        build a certificate compilation PDF
 *   GET  /api/v1/reports                  list generated reports
 *   GET  /api/v1/reports/{id}             report metadata
 *   GET  /api/v1/reports/{id}/pdf         download the PDF
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/pdfreport.php';

function report_row(array $r): array
{
    return [
        'id'                => $r['id'],
        'title'             => $r['title'],
        'kind'              => $r['kind'],
        'certificate_count' => (int)$r['certificate_count'],
        'status'            => $r['status'],
        'created_at'        => $r['created_at'],
        'generated_at'      => $r['generated_at'],
        'error'             => $r['error'],
        'download_url'      => $r['status'] === 'ready'
            ? "/api/v1/reports/{$r['id']}/pdf"
            : null,
    ];
}

function get_report_by_id(PDO $pdo, string $id): ?array
{
    $st = $pdo->prepare(
        'SELECT id, title, kind, certificate_count, status, created_at, generated_at, error
         FROM report WHERE id = ?'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    return $row === false ? null : report_row($row);
}

/** Select certificate ids matching the requested filters. */
function select_certificate_ids(PDO $pdo, array $in): array
{
    $sql  = 'SELECT id FROM certificate WHERE deleted = 0 AND status != "ABORTED"';
    $params = [];

    if (!empty($in['certificate_ids']) && is_array($in['certificate_ids'])) {
        $ids = array_values(array_unique(array_filter($in['certificate_ids'], 'is_uuid')));
        if ($ids === []) {
            json_error('invalid_filter: certificate_ids', 422);
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql .= " AND id IN ($ph)";
        $params = array_merge($params, $ids);
    }

    foreach (['project_number', 'project_name', 'customer_number', 'customer_name'] as $f) {
        if (isset($in[$f])) {
            $sql .= " AND $f = ?";
            $params[] = mb_substr((string)$in[$f], 0, 255);
        }
    }
    foreach (['since', 'until'] as $f) {
        if (isset($in[$f]) && $in[$f] !== null) {
            $sql .= $f === 'since' ? ' AND generation_date >= ?' : ' AND generation_date <= ?';
            $params[] = mb_substr((string)$in[$f], 0, 32);
        }
    }
    if (isset($in['technician_id'])) {
        $sql .= ' AND technician_id = ?';
        $params[] = require_uuid((string)$in['technician_id']);
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return array_column($st->fetchAll(), 'id');
}

function build_compilation_pdf(PDO $pdo, array $certIds): string
{
    $pdf = new PdfReport();
    $pdf->header('Certifikatsamling', 'GovernIT Data Eraser - genereret ' . now_iso());

    // Title / summary
    $pdf->section('Oversigt');
    $pdf->field('Antal certifikater', (string)count($certIds));
    $pdf->field('Genereret', now_iso());
    $pdf->spacer(6);

    $pdf->section('Kunde / Projekt');
    $pdf->tableRow(
        [160, 90, 70, 70, 80, 80],
        ['Kunde', 'Kundenr.', 'Projekt', 'Projektnr.', 'Dato', 'Status'],
        true
    );

    $summaries = [];
    if ($certIds !== []) {
        $ph = implode(',', array_fill(0, count($certIds), '?'));
        $st = $pdo->prepare(
            "SELECT id, customer_name, customer_number, project_name, project_number,
                    generation_date, status, technician_name
             FROM certificate WHERE id IN ($ph) ORDER BY generation_date"
        );
        $st->execute($certIds);
        $summaries = $st->fetchAll();
    }
    foreach ($summaries as $s) {
        $pdf->tableRow(
            [160, 90, 70, 70, 80, 80],
            [$s['customer_name'], $s['customer_number'], $s['project_name'] ?? '-', $s['project_number'] ?? '-', $s['generation_date'] ?? '-', $s['status'] ?? '-']
        );
    }

    $pdf->pageBreak();

    // Detail per certificate
    foreach ($summaries as $i => $s) {
        if ($i > 0) {
            $pdf->pageBreak();
        }
        $pdf->section('Certifikat #' . ($i + 1) . ' - ' . ($s['customer_name'] ?? ''));
        $pdf->field('Certifikat-id', $s['id']);
        $pdf->field('Status', $s['status'] ?? '-');
        $pdf->field('Generering', $s['generation_date'] ?? '-');
        $pdf->field('Tekniker', $s['technician_name'] ?? '-');
        $pdf->spacer(6);

        $pdf->section('Detaljer');
        $st = $pdo->prepare('SELECT * FROM certificate WHERE id = ?');
        $st->execute([$s['id']]);
        $c = $st->fetch();
        if ($c !== false) {
            $pdf->field('Kunde', $c['customer_name'] . ' (' . $c['customer_number'] . ')');
            $pdf->field('Projekt', ($c['project_name'] ?? '-'));
            $pdf->field('Drev', ($c['drive_manufacturer'] ?? '') . ' ' . ($c['drive_model'] ?? ''));
            $pdf->field('Serienr. (drev)', $c['drive_serial'] ?? '-');
            $pdf->field('Serienr. (computer)', $c['computer_serial'] ?? '-');
            $pdf->field('Asset tag', $c['asset_tag'] ?? '-');
            $pdf->field('Profil', $c['profile_name'] ?? '-');
            $pdf->field('Metode', $c['actual_method'] ?? '-');
            $pdf->field('Start', $c['started_at'] ?? '-');
            $pdf->field('Slut', $c['finished_at'] ?? '-');
            if ($c['notes']) {
                $pdf->spacer(4);
                $pdf->field('Noter', mb_substr((string)$c['notes'], 0, 120));
            }
        }
    }

    $pdf->section('Integritet');
    $pdf->paragraph('Dokumentet er genereret af GovernIT Data Eraser Management Portal. ' .
        'Certifikaterne er verifikationsbaserede og klassificeret i henhold til den anvendte sletningsprofil.');

    return $pdf->output();
}

function route_reports(string $method, string $path, PDO $pdo): bool
{
    if (!str_starts_with($path, '/api/v1/reports')) {
        return false;
    }
    $user = require_auth($pdo);
    require_role($pdo, $user, 'Administrator', 'Koordinator', 'Revisor');

    $rest  = substr($path, strlen('/api/v1/reports'));
    $parts = $rest === '' ? [] : array_values(array_filter(explode('/', $rest)));

    if ($method === 'GET' && $parts === []) {
        $rows = $pdo->query(
            'SELECT id, title, kind, certificate_count, status, created_at, generated_at, error
             FROM report ORDER BY created_at DESC'
        )->fetchAll();
        json_respond(['reports' => array_map('report_row', $rows)]);
    }

    if ($method === 'POST' && $parts === []) {
        $in = request_json();
        $certIds = select_certificate_ids($pdo, $in);
        if ($certIds === []) {
            json_error('no_certificates_match', 422);
        }

        $id = uuid_v4();
        $now = now_iso();
        $st = $pdo->prepare(
            'INSERT INTO report
                (id, title, kind, filter_json, certificate_count, status, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $title = 'Certifikatsamling ' . mb_substr($now, 0, 10) . ' (' . count($certIds) . ')';
        $st->execute([
            $id,
            $title,
            'certificate_compilation',
            json_encode(['certificate_ids' => $certIds], JSON_UNESCAPED_SLASHES),
            count($certIds),
            'pending',
            $user['id'],
            $now,
        ]);

        try {
            $bytes = build_compilation_pdf($pdo, $certIds);
            $filename = 'certifikatsamling_' . mb_substr($now, 0, 10) . '.pdf';
            $blob = $pdo->prepare(
                'INSERT INTO report_blob (report_id, mime_type, filename, bytes, size_bytes, generated_at)
                 VALUES (?,?,?,?,?,?)'
            );
            $blob->execute([$id, 'application/pdf', $filename, $bytes, strlen($bytes), $now]);
            $done = $pdo->prepare(
                "UPDATE report SET status = 'ready', generated_at = ? WHERE id = ?"
            );
            $done->execute([$now, $id]);
        } catch (Throwable $e) {
            $err = $pdo->prepare(
                "UPDATE report SET status = 'failed', error = ? WHERE id = ?"
            );
            $err->execute([mb_substr($e->getMessage(), 0, 255), $id]);
            json_error('report_generation_failed', 500);
        }

        json_respond(['report' => get_report_by_id($pdo, $id)], 201);
    }

    if ($parts === []) {
        json_error('method_not_allowed', 405);
    }
    $id = require_uuid($parts[0]);

    if ($method === 'GET' && count($parts) === 1) {
        $r = get_report_by_id($pdo, $id);
        if ($r === null) {
            json_error('not_found', 404);
        }
        json_respond(['report' => $r]);
    }

    if ($method === 'GET' && count($parts) === 2 && $parts[1] === 'pdf') {
        $r = get_report_by_id($pdo, $id);
        if ($r === null) {
            json_error('not_found', 404);
        }
        if ($r['status'] !== 'ready') {
            json_error('report_not_ready', 409);
        }
        $st = $pdo->prepare(
            'SELECT mime_type, filename, bytes FROM report_blob WHERE report_id = ?'
        );
        $st->execute([$id]);
        $blob = $st->fetch();
        if ($blob === false) {
            json_error('not_found', 404);
        }
        http_response_code(200);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $blob['filename'] . '"');
        header('Content-Length: ' . strlen($blob['bytes']));
        echo $blob['bytes'];
        exit;
    }

    json_error('method_not_allowed', 405);
}
