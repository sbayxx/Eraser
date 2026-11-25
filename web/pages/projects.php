<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$pdo  = db_connect();
$user = web_require_login($pdo);
$canEdit = web_has_role($user, 'Administrator', 'Koordinator');

// ---- Customer scoping ---------------------------------------
[$scopeSql, $scopeParams] = customer_scope_sql($pdo, $user, 'p');
$scopedIds = [];
if ($scopeSql !== '') {
    $st = $pdo->prepare("SELECT c.id FROM customer c WHERE c.deleted=0 AND c.active=1 AND "
        . str_replace('p.customer_id', 'c.id', $scopeSql));
    $st->execute($scopeParams);
    $scopedIds = array_column($st->fetchAll(), 'id');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canEdit) {
    web_csrf_validate();

    // Archive (soft-delete) a project
    if (($_POST['_action'] ?? '') === 'delete' && isset($_POST['id'])) {
        $delSql = "UPDATE project p SET status='archived', deleted=1, updated_at=? WHERE p.id=?"
            . ($scopeSql !== '' ? ' AND ' . $scopeSql : '');
        $pdo->prepare($delSql)->execute([now_iso(), $_POST['id'], ...$scopeParams]);
        web_flash_set('ok', 'Projekt arkiveret.');
        header('Location: /projects.php');
        exit;
    }

    $id   = $_POST['id'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $num  = trim($_POST['project_number'] ?? '');
    $cust = $_POST['customer_id'] ?? '';
    // A scoped user may only attach projects to customers they can access.
    if ($scopeSql !== '' && !in_array($cust, $scopedIds, true)) {
        web_flash_set('bad', 'Du har ikke adgang til den valgte kunde.');
    } elseif ($name === '' || $num === '' || $cust === '') {
        web_flash_set('bad', 'Navn, projektnummer og kunde er påkrævet.');
    } elseif ($id === '') {
        $pdo->prepare(
            'INSERT INTO project (id, name, project_number, customer_id, case_number, reference, order_number, start_date, end_date, status, requires_serial_number, requires_asset_tag, requires_extra_confirmation, certificate_text, notes, updated_at, deleted)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)'
        )->execute([
            uuid_v4(), $name, $num, $cust, $_POST['case_number'] ?: null, $_POST['reference'] ?: null,
            $_POST['order_number'] ?: null, $_POST['start_date'] ?: null, $_POST['end_date'] ?: null,
            $_POST['status'] ?: 'active',
            web_bool($_POST['requires_serial_number']) ? 1 : 0,
            web_bool($_POST['requires_asset_tag']) ? 1 : 0,
            web_bool($_POST['requires_extra_confirmation']) ? 1 : 0,
            $_POST['certificate_text'] ?: null, $_POST['notes'] ?: null, now_iso(),
        ]);
        web_flash_set('ok', 'Projekt oprettet.');
    } else {
        $pdo->prepare(
            'UPDATE project SET name=?, project_number=?, customer_id=?, case_number=?, reference=?, order_number=?, start_date=?, end_date=?, status=?, requires_serial_number=?, requires_asset_tag=?, requires_extra_confirmation=?, certificate_text=?, notes=?, updated_at=? WHERE id=?'
        )->execute([
            $name, $num, $cust, $_POST['case_number'] ?: null, $_POST['reference'] ?: null,
            $_POST['order_number'] ?: null, $_POST['start_date'] ?: null, $_POST['end_date'] ?: null,
            $_POST['status'] ?: 'active',
            web_bool($_POST['requires_serial_number']) ? 1 : 0,
            web_bool($_POST['requires_asset_tag']) ? 1 : 0,
            web_bool($_POST['requires_extra_confirmation']) ? 1 : 0,
            $_POST['certificate_text'] ?: null, $_POST['notes'] ?: null, now_iso(), $id,
        ]);
        web_flash_set('ok', 'Projekt opdateret.');
    }
    header('Location: /projects.php');
    exit;
}

// ---- Archive (deactivate) ----
// (Moved into the POST handler above; destructive actions are POST-only.)

// Only customers within the user's scope may be selected.
$custSt = $pdo->prepare(
    'SELECT id, name FROM customer WHERE deleted=0 AND active=1'
    . ($scopeSql !== '' ? ' AND ' . str_replace('p.customer_id', 'customer.id', $scopeSql) : '')
    . ' ORDER BY name'
);
$custSt->execute($scopeParams);
$custList = $custSt->fetchAll();
$custOpts = '';
foreach ($custList as $c) {
    $custOpts .= '<option value="' . e($c['id']) . '">' . e($c['name']) . '</option>';
}

$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM project WHERE id=? AND deleted=0');
    $st->execute([$_GET['edit']]);
    $edit = $st->fetch();
    if ($edit) {
        $custOpts = '';
        foreach ($custList as $c) {
            $sel = $c['id'] === $edit['customer_id'] ? ' selected' : '';
            $custOpts .= '<option value="' . e($c['id']) . '"' . $sel . '>' . e($c['name']) . '</option>';
        }
    }
}

// Customer name lookup map
$custName = [];
foreach ($custList as $c) {
    $custName[$c['id']] = $c['name'];
}

$rowsSt = $pdo->prepare('SELECT p.* FROM project p WHERE p.deleted=0'
    . ($scopeSql !== '' ? ' AND ' . $scopeSql : '') . ' ORDER BY p.name');
$rowsSt->execute($scopeParams);
$rows = $rowsSt->fetchAll();
$listRows = '';
foreach ($rows as $r) {
    $statusBadge = match ($r['status']) {
        'completed' => '<span class="badge ok">gennemført</span>',
        'archived' => '<span class="badge muted">arkiveret</span>',
        default => '<span class="badge warn">aktiv</span>',
    };
    $actions = $canEdit
        ? '<a class="btn small" href="/projects.php?edit=' . e($r['id']) . '">Redigér</a> '
        . web_delete_form('/projects.php', $r['id'], 'Slet', 'Arkivér projektet?')
        : '';
    $listRows .= '<tr><td>' . e($r['name']) . '</td><td>' . e($r['project_number']) . '</td><td>' . e($custName[$r['customer_id']] ?? '') . '</td><td>' . e($r['reference'] ?? '') . '</td><td>' . $statusBadge . '</td><td class="actions">' . $actions . '</td></tr>';
}

$v = fn($k, $d = '') => $edit ? e($edit[$k] ?? $d) : e($d);
$chk = fn($k) => (!$edit || web_bool($edit[$k])) ? 'checked' : '';
$form = '';
if ($canEdit) {
    $form = '<form class="form-card" method="post" action="/projects.php">'
        . web_csrf_field()
        . '<input type="hidden" name="id" value="' . ($edit ? e($edit['id']) : '') . '">'
        . '<fieldset><legend>' . ($edit ? 'Redigér projekt' : 'Nyt projekt') . '</legend>'
        . '<div class="form-row"><label>Navn *</label><input type="text" name="name" value="' . $v('name') . '" required></div>'
        . '<div class="form-row"><label>Projektnummer *</label><input type="text" name="project_number" value="' . $v('project_number') . '" required></div>'
        . '<div class="form-row"><label>Kunde *</label><select name="customer_id" required>' . $custOpts . '</select></div>'
        . '<div class="form-row"><label>Sagsnummer</label><input type="text" name="case_number" value="' . $v('case_number') . '"></div>'
        . '<div class="form-row"><label>Reference</label><input type="text" name="reference" value="' . $v('reference') . '"></div>'
        . '<div class="form-row"><label>Ordrenummer</label><input type="text" name="order_number" value="' . $v('order_number') . '"></div>'
        . '<div class="form-row"><label>Startdato</label><input type="text" name="start_date" value="' . $v('start_date') . '" placeholder="2026-01-15"></div>'
        . '<div class="form-row"><label>Slutdato</label><input type="text" name="end_date" value="' . $v('end_date') . '" placeholder="2026-06-30"></div>'
        . '<div class="form-row"><label>Status</label><select name="status">'
        . '<option value="active"' . (($edit && $edit['status']==='active')?' selected':'') . '>Aktiv</option>'
        . '<option value="completed"' . (($edit && $edit['status']==='completed')?' selected':'') . '>Gennemført</option>'
        . '<option value="archived"' . (($edit && $edit['status']==='archived')?' selected':'') . '>Arkiveret</option>'
        . '</select></div>'
        . '<div class="form-row check-line"><input type="checkbox" name="requires_serial_number" ' . $chk('requires_serial_number') . '> Kræver serienummer</div>'
        . '<div class="form-row check-line"><input type="checkbox" name="requires_asset_tag" ' . $chk('requires_asset_tag') . '> Kræver aktivnummer</div>'
        . '<div class="form-row check-line"><input type="checkbox" name="requires_extra_confirmation" ' . $chk('requires_extra_confirmation') . '> Kræver ekstra bekræftelse</div>'
        . '<div class="form-row"><label>Certifikattekst</label><textarea name="certificate_text">' . $v('certificate_text') . '</textarea></div>'
        . '<div class="form-row"><label>Noter</label><textarea name="notes">' . $v('notes') . '</textarea></div>'
        . '<div class="form-actions"><button class="btn primary" type="submit">Gem</button><a class="btn" href="/projects.php">Annullér</a></div>'
        . '</fieldset></form>';
}

$body = '<div class="split">'
    . '<div><div class="toolbar"><span class="muted">' . count($rows) . ' projekter</span></div>'
    . '<table class="list"><thead><tr><th>Navn</th><th>Nummer</th><th>Kunde</th><th>Reference</th><th>Status</th><th></th></tr></thead><tbody>' . $listRows . '</tbody></table></div>'
    . '<div>' . $form . '</div></div>';

render_page($user, 'Projekter', 'projects', $body);
