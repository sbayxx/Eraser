<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$pdo  = db_connect();
$user = web_require_login($pdo);
$canEdit = web_has_role($user, 'Administrator', 'Koordinator');

$roles = $pdo->query('SELECT id, name FROM role ORDER BY name')->fetchAll();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canEdit) {
    web_csrf_validate();

    if (($_POST['_action'] ?? '') === 'delete' && isset($_POST['id'])) {
        $pdo->prepare('UPDATE technician SET active=0, deleted=1, updated_at=? WHERE id=?')
            ->execute([now_iso(), $_POST['id']]);
        web_flash_set('ok', 'Tekniker deaktiveret.');
        header('Location: /technicians.php');
        exit;
    }

    $id = $_POST['id'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $roleNames = $_POST['roles'] ?? [];
    if ($name === '') {
        web_flash_set('bad', 'Navn er påkrævet.');
    } elseif ($id === '') {
        $tid = uuid_v4();
        $pdo->prepare('INSERT INTO technician (id, name, technician_id, organisation, initials, email, preferred_language, active, updated_at, deleted) VALUES (?,?,?,?,?,?,?,?,?,0)')
            ->execute([$tid, $name, $_POST['technician_id'] ?: null, $_POST['organisation'] ?: null, $_POST['initials'] ?: null, $_POST['email'] ?: null, $_POST['preferred_language'] ?: null, web_bool($_POST['active'] ?? '1') ? 1 : 0, now_iso()]);
        sync_tech_roles($pdo, $tid, $roleNames);
        web_flash_set('ok', 'Tekniker oprettet.');
    } else {
        $pdo->prepare('UPDATE technician SET name=?, technician_id=?, organisation=?, initials=?, email=?, preferred_language=?, active=?, updated_at=? WHERE id=?')
            ->execute([$name, $_POST['technician_id'] ?: null, $_POST['organisation'] ?: null, $_POST['initials'] ?: null, $_POST['email'] ?: null, $_POST['preferred_language'] ?: null, web_bool($_POST['active'] ?? '1') ? 1 : 0, now_iso(), $id]);
        sync_tech_roles($pdo, $id, $roleNames);
        web_flash_set('ok', 'Tekniker opdateret.');
    }
    header('Location: /technicians.php');
    exit;
}

// ---- Archive (deactivate) ----
// (Moved into the POST handler above; destructive actions are POST-only.)

function sync_tech_roles(PDO $pdo, string $tid, array $roleNames): void
{
    $del = $pdo->prepare('DELETE FROM technician_role WHERE technician_id=?');
    $del->execute([$tid]);
    if ($roleNames === []) {
        return;
    }
    $ph = implode(',', array_fill(0, count($roleNames), '?'));
    $st = $pdo->prepare("SELECT id FROM role WHERE name IN ($ph)");
    $st->execute($roleNames);
    $ins = $pdo->prepare('INSERT IGNORE INTO technician_role (technician_id, role_id) VALUES (?,?)');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        $ins->execute([$tid, $rid]);
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM technician WHERE id=? AND deleted=0');
    $st->execute([$_GET['edit']]);
    $edit = $st->fetch();
    $editRoleNames = [];
    if ($edit) {
        $r = $pdo->prepare('SELECT r.name FROM role r JOIN technician_role tr ON tr.role_id=r.id WHERE tr.technician_id=?');
        $r->execute([$edit['id']]);
        $editRoleNames = $r->fetchAll(PDO::FETCH_COLUMN);
    }
} else {
    $editRoleNames = [];
}

$rows = $pdo->query('SELECT * FROM technician WHERE deleted=0 ORDER BY name')->fetchAll();
$roleNames = [];
foreach ($rows as $r) {
    $q = $pdo->prepare('SELECT r.name FROM role r JOIN technician_role tr ON tr.role_id=r.id WHERE tr.technician_id=?');
    $q->execute([$r['id']]);
    $roleNames[$r['id']] = $q->fetchAll(PDO::FETCH_COLUMN);
}

$listRows = '';
foreach ($rows as $r) {
    $badge = web_bool($r['active']) ? '<span class="badge ok">aktiv</span>' : '<span class="badge muted">inaktiv</span>';
    $action = $canEdit
        ? '<a class="btn small" href="/technicians.php?edit=' . e($r['id']) . '">Redigér</a> '
        . web_delete_form('/technicians.php', $r['id'], 'Slet', 'Deaktivér?')
        : '';
    $listRows .= '<tr><td>' . e($r['name']) . '</td><td>' . e($r['technician_id'] ?? '') . '</td><td>' . e(implode(', ', $roleNames[$r['id']] ?? [])) . '</td><td>' . e($r['email'] ?? '') . '</td><td>' . $badge . '</td><td class="actions">' . $action . '</td></tr>';
}

$roleOpts = '';
foreach ($roles as $role) {
    $sel = $edit && in_array($role['name'], $editRoleNames, true) ? ' checked' : '';
    $roleOpts .= '<label class="check-line"><input type="checkbox" name="roles[]" value="' . e($role['name']) . '"' . $sel . '> ' . e($role['name']) . '</label>';
}

$form = '';
if ($canEdit) {
    $v = fn($k, $d = '') => $edit ? e($edit[$k] ?? $d) : e($d);
    $langSel = fn($l) => ($edit && $edit['preferred_language'] === $l) ? ' selected' : '';
    $form = '<form class="form-card" method="post" action="/technicians.php">'
        . web_csrf_field()
        . '<input type="hidden" name="id" value="' . ($edit ? e($edit['id']) : '') . '">'
        . '<fieldset><legend>' . ($edit ? 'Redigér tekniker' : 'Ny tekniker') . '</legend>'
        . '<div class="form-row"><label>Navn *</label><input type="text" name="name" value="' . $v('name') . '" required></div>'
        . '<div class="form-row"><label>Tekniker-id</label><input type="text" name="technician_id" value="' . $v('technician_id') . '"></div>'
        . '<div class="form-row"><label>Organisation</label><input type="text" name="organisation" value="' . $v('organisation') . '"></div>'
        . '<div class="form-row"><label>Initialer</label><input type="text" name="initials" value="' . $v('initials') . '"></div>'
        . '<div class="form-row"><label>E-mail</label><input type="email" name="email" value="' . $v('email') . '"></div>'
        . '<div class="form-row"><label>Sprog</label><select name="preferred_language">'
        . '<option value="da"' . $langSel('da') . '>Dansk</option><option value="en"' . $langSel('en') . '>English</option></select></div>'
        . '<div class="form-row"><label>Roller</label>' . ($roleOpts ?: '<span class="muted">Ingen roller endnu</span>') . '</div>'
        . '<div class="form-row check-line"><input type="checkbox" name="active" value="1" ' . ((!$edit || web_bool($edit['active'])) ? 'checked' : '') . '> Aktiv</div>'
        . '<div class="form-actions"><button class="btn primary" type="submit">Gem</button><a class="btn" href="/technicians.php">Annullér</a></div>'
        . '</fieldset></form>';
}

$body = '<div class="split">'
    . '<div><div class="toolbar"><span class="muted">' . count($rows) . ' teknikere</span></div>'
    . '<table class="list"><thead><tr><th>Navn</th><th>Id</th><th>Roller</th><th>E-mail</th><th>Status</th><th></th></tr></thead><tbody>' . $listRows . '</tbody></table></div>'
    . '<div>' . $form . '</div></div>';

render_page($user, 'Teknikere', 'technicians', $body);
