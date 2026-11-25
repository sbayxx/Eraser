<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$pdo   = db_connect();
$user  = web_require_login($pdo);

$counts = [];
foreach (['customer', 'project', 'technician', 'web_user', 'erasure_profile', 'erasure_session', 'certificate'] as $t) {
    try {
        $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    } catch (Throwable $e) {
        $counts[$t] = 0;
    }
}

$recent = $pdo->query(
    'SELECT id, customer_name, project_name, started_at, final_status
     FROM erasure_session ORDER BY started_at DESC LIMIT 8'
)->fetchAll();

$cards = [
    ['/customers.php', 'Kunder', $counts['customer']],
    ['/projects.php', 'Projekter', $counts['project']],
    ['/technicians.php', 'Teknikere', $counts['technician']],
    ['/users.php', 'Brugere', $counts['web_user']],
    ['/profiles.php', 'Sletteprofiler', $counts['erasure_profile']],
];
$cardHtml = '';
foreach ($cards as [$href, $label, $n]) {
    $cardHtml .= '<a class="stat-card" href="' . $href . '">'
        . '<div class="label">' . e($label) . '</div>'
        . '<div class="value">' . (int)$n . '</div></a>';
}

$sessionRows = '';
foreach ($recent as $r) {
    $status = e($r['final_status'] ?? '—');
    $sessionRows .= '<tr><td>' . e($r['project_name'] ?? '') . '</td><td>' . e($r['customer_name'] ?? '') . '</td><td>' . e($r['started_at'] ?? '') . '</td><td><span class="badge muted">' . $status . '</span></td></tr>';
}
if ($sessionRows === '') {
    $sessionRows = '<tr><td colspan="4" class="muted">Ingen sessioner endnu.</td></tr>';
}

$recentHtml = '<table class="list"><thead><tr><th>Projekt</th><th>Kunde</th><th>Startet</th><th>Status</th></tr></thead><tbody>' . $sessionRows . '</tbody></table>';

$body = '<div class="stat-grid">' . $cardHtml . '</div>'
    . '<h2>Seneste sessioner</h2>' . $recentHtml;

render_page($user, 'Dashboard', 'dashboard', $body);
