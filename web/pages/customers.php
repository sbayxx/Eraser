<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$pdo  = db_connect();
$user = web_require_login($pdo);
$canEdit = web_has_role($user, 'Administrator', 'Koordinator');

// ---- Create / Update / Delete ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canEdit) {
    web_csrf_validate();

    // Delete (deactivate customer + archive projects)
    if (($_POST['_action'] ?? '') === 'delete' && isset($_POST['id'])) {
        $delId = $_POST['id'];
        $now = now_iso();
        $pdo->prepare("UPDATE project SET status='archived', deleted=1, updated_at=? WHERE customer_id=?")
            ->execute([$now, $delId]);
        $pdo->prepare('UPDATE customer SET active=0, deleted=1, updated_at=? WHERE id=?')
            ->execute([$now, $delId]);
        web_flash_set('ok', 'Kunde deaktiveret. Tilhørende projekter er arkiveret.');
        header('Location: /customers.php');
        exit;
    }

    $id   = $_POST['id'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $num  = trim($_POST['customer_number'] ?? '');
    $active = web_bool($_POST['active'] ?? '1') ? 1 : 0;
    if ($name === '' || $num === '') {
        web_flash_set('bad', 'Navn og kundenummer er påkrævet.');
    } elseif ($id === '') {
        $pdo->prepare(
            'INSERT INTO customer (id, name, customer_number, address, contact_person, contact_email, contact_phone, notes, active, updated_at, deleted)
             VALUES (?,?,?,?,?,?,?,?,?,?,0)'
        )->execute([uuid_v4(), $name, $num, $_POST['address'] ?: null, $_POST['contact_person'] ?: null, $_POST['contact_email'] ?: null, $_POST['contact_phone'] ?: null, $_POST['notes'] ?: null, $active, now_iso()]);
        web_flash_set('ok', 'Kunde oprettet.');
    } else {
        $pdo->prepare(
            'UPDATE customer SET name=?, customer_number=?, address=?, contact_person=?, contact_email=?, contact_phone=?, notes=?, active=?, updated_at=? WHERE id=?'
        )->execute([$name, $num, $_POST['address'] ?: null, $_POST['contact_person'] ?: null, $_POST['contact_email'] ?: null, $_POST['contact_phone'] ?: null, $_POST['notes'] ?: null, $active, now_iso(), $id]);
        web_flash_set('ok', 'Kunde opdateret.');
    }
    header('Location: /customers.php');
    exit;
}

// ---- Delete (deactivate) ----
// (Moved into the POST handler above; destructive actions are POST-only.)

$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM customer WHERE id=? AND deleted=0');
    $st->execute([$_GET['edit']]);
    $edit = $st->fetch();
}

$rows = $pdo->query('SELECT * FROM customer WHERE deleted=0 ORDER BY name')->fetchAll();
$listRows = '';
foreach ($rows as $r) {
    $badge = web_bool($r['active']) ? '<span class="badge ok">aktiv</span>' : '<span class="badge muted">inaktiv</span>';
    $actions = '';
    if ($canEdit) {
        $actions = '<a class="btn small" href="/customers.php?edit=' . e($r['id']) . '">Redigér</a>'
            . ' ' . web_delete_form('/customers.php', $r['id'], 'Slet', 'Deaktivér kunden?');
    }
    $listRows .= '<tr><td>' . e($r['name']) . '</td><td>' . e($r['customer_number']) . '</td><td>' . e($r['contact_person'] ?? '') . '</td><td>' . e($r['contact_email'] ?? '') . '</td><td>' . $badge . '</td><td class="actions">' . $actions . '</td></tr>';
}

$form = '';
if ($canEdit && $edit) {
    $form = '<form class="form-card" method="post" action="/customers.php">'
        . web_csrf_field()
        . '<input type="hidden" name="id" value="' . e($edit['id']) . '">'
        . '<fieldset><legend>Redigér kunde</legend>'
        . '<div class="form-row"><label>Navn *</label><input type="text" name="name" value="' . e($edit['name']) . '" required></div>'
        . '<div class="form-row"><label>Kundenummer *</label><input type="text" name="customer_number" value="' . e($edit['customer_number']) . '" required></div>'
        . '<div class="form-row"><label>Adresse</label><input type="text" name="address" value="' . e($edit['address'] ?? '') . '"></div>'
        . '<div class="form-row"><label>Kontaktperson</label><input type="text" name="contact_person" value="' . e($edit['contact_person'] ?? '') . '"></div>'
        . '<div class="form-row"><label>E-mail</label><input type="email" name="contact_email" value="' . e($edit['contact_email'] ?? '') . '"></div>'
        . '<div class="form-row"><label>Telefon</label><input type="text" name="contact_phone" value="' . e($edit['contact_phone'] ?? '') . '"></div>'
        . '<div class="form-row"><label>Noter</label><textarea name="notes">' . e($edit['notes'] ?? '') . '</textarea></div>'
        . '<div class="form-row check-line"><input type="checkbox" name="active" value="1" ' . (web_bool($edit['active']) ? 'checked' : '') . '> Aktiv</div>'
        . '<div class="form-actions"><button class="btn primary" type="submit">Gem</button><a class="btn" href="/customers.php">Annullér</a></div>'
        . '</fieldset></form>';
} elseif ($canEdit) {
    $form = '<form class="form-card" method="post" action="/customers.php">'
        . web_csrf_field()
        . '<fieldset><legend>Ny kunde</legend>'
        . '<div class="form-row"><label>Navn *</label><input type="text" name="name" required></div>'
        . '<div class="form-row"><label>Kundenummer *</label><input type="text" name="customer_number" required></div>'
        . '<div class="form-row"><label>Adresse</label><input type="text" name="address"></div>'
        . '<div class="form-row"><label>Kontaktperson</label><input type="text" name="contact_person"></div>'
        . '<div class="form-row"><label>E-mail</label><input type="email" name="contact_email"></div>'
        . '<div class="form-row"><label>Telefon</label><input type="text" name="contact_phone"></div>'
        . '<div class="form-row"><label>Noter</label><textarea name="notes"></textarea></div>'
        . '<div class="form-row check-line"><input type="checkbox" name="active" value="1" checked> Aktiv</div>'
        . '<div class="form-actions"><button class="btn primary" type="submit">Opret</button></div>'
        . '</fieldset></form>';
}

if (!$canEdit) {
    $form = '<div class="muted">Du har kun læseadgang.</div>';
}

$body = '<div class="split narrow">'
    . '<div>'
    . '<div class="toolbar"><span class="muted">' . count($rows) . ' kunder</span></div>'
    . '<table class="list"><thead><tr><th>Navn</th><th>Nummer</th><th>Kontakt</th><th>E-mail</th><th>Status</th><th></th></tr></thead><tbody>' . $listRows . '</tbody></table>'
    . ($listRows === '' ? '<div class="empty mt" style="margin-top:14px">Ingen kunder endnu. <a href="/customers.php?new=1">Opret den første</a>.</div>' : '')
    . '</div>'
    . '<div>' . $form . '</div></div>';

render_page($user, 'Kunder', 'customers', $body);
