<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$pdo  = db_connect();
$user = web_require_login($pdo);

$rows = $pdo->query(
    'SELECT id, title, kind, certificate_count, status, created_at, generated_at
     FROM report ORDER BY created_at DESC LIMIT 200'
)->fetchAll();

$kindLabel = fn($k) => $k === 'session_audit' ? 'Sessionsrevision' : 'Certifikatsamling';

$listRows = '';
foreach ($rows as $r) {
    $tone = $r['status'] === 'ready' ? 'ok' : ($r['status'] === 'failed' ? 'bad' : 'muted');
    $listRows .= '<tr><td>' . e($r['title'] ?? '') . '</td><td>' . e($kindLabel($r['kind'])) . '</td>'
        . '<td>' . (int)$r['certificate_count'] . '</td>'
        . '<td>' . e($r['created_at'] ?? '') . '</td><td>' . e($r['generated_at'] ?? '—') . '</td>'
        . '<td><span class="badge ' . $tone . '">' . e($r['status'] ?? '—') . '</span></td></tr>';
}
if ($listRows === '') {
    $listRows = '<tr><td colspan="6" class="muted">Ingen rapporter fundet.</td></tr>';
}

$body = '<div class="toolbar"><span class="muted">' . count($rows) . ' rapporter</span></div>'
    . '<table class="list"><thead><tr><th>Titel</th><th>Type</th><th>Antal cert.</th><th>Oprettet</th><th>Genereret</th><th>Status</th></tr></thead><tbody>' . $listRows . '</tbody></table>';

render_page($user, 'Rapporter', 'reports', $body);
