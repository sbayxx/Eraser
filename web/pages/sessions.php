<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$pdo  = db_connect();
$user = web_require_login($pdo);

$status = $_GET['status'] ?? null;
$sql = 'SELECT * FROM erasure_session WHERE deleted=0';
$params = [];
if ($status !== null && $status !== '') {
    $sql .= ' AND final_status = ?';
    $params[] = $status;
}
$sql .= ' ORDER BY started_at DESC LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$statusOpts = '';
foreach (['pass', 'partial', 'fail', 'aborted', 'unknown'] as $s) {
    $sel = ($status === $s) ? ' selected' : '';
    $statusOpts .= '<option value="' . $s . '"' . $sel . '>' . e($s) . '</option>';
}

$listRows = '';
foreach ($rows as $r) {
    $tone = in_array($r['final_status'], ['fail', 'aborted'], true) ? 'warn' : 'ok';
    $listRows .= '<tr><td>' . e($r['project_name'] ?? '') . '</td><td>' . e($r['customer_name'] ?? '') . '</td><td>' . e($r['asset_tag'] ?? '') . '</td><td>' . e($r['started_at'] ?? '') . '</td><td>' . e($r['finished_at'] ?? '') . '</td><td><span class="badge ' . $tone . '">' . e($r['final_status'] ?? '—') . '</span></td></tr>';
}
if ($listRows === '') {
    $listRows = '<tr><td colspan="6" class="muted">Ingen sessioner fundet.</td></tr>';
}

$body = '<div class="toolbar">'
    . '<form method="get" action="/sessions.php">'
    . '<select name="status"><option value="">Alle status</option>' . $statusOpts . '</select>'
    . '<button class="btn small" type="submit">Filtrér</button></form>'
    . '<span class="muted">' . count($rows) . ' sessioner</span></div>'
    . '<table class="list"><thead><tr><th>Projekt</th><th>Kunde</th><th>Aktiv</th><th>Startet</th><th>Sluttet</th><th>Status</th></tr></thead><tbody>' . $listRows . '</tbody></table>';

render_page($user, 'Sessioner', 'sessions', $body);
