<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$pdo  = db_connect();
$user = web_require_login($pdo);
$canEdit = web_has_role($user, 'Administrator', 'Koordinator');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canEdit) {
    web_csrf_validate();

    if (($_POST['_action'] ?? '') === 'delete' && isset($_POST['id'])) {
        $pdo->prepare('UPDATE erasure_profile SET active=0, deleted=1, updated_at=? WHERE id=?')
            ->execute([now_iso(), $_POST['id']]);
        web_flash_set('ok', 'Profil deaktiveret.');
        header('Location: /profiles.php');
        exit;
    }

    $id = $_POST['id'] ?? '';
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        web_flash_set('bad', 'Navn er påkrævet.');
    } else {
        $m = ['clear', 'purge', 'cryptographic_erase', 'device_sanitize', 'overwrite', 'custom'];
        $method = in_array($_POST['method'] ?? '', $m, true) ? $_POST['method'] : 'overwrite';
        $media = array_values(array_filter($_POST['allowed_media_types'] ?? []));
        $req = isset($_POST['required_fields']) && is_array($_POST['required_fields'])
            ? json_encode(array_values($_POST['required_fields']), JSON_UNESCAPED_SLASHES) : '[]';
        $num = $_POST['overwrite_passes'] !== '' ? (int)$_POST['overwrite_passes'] : null;
        $active = web_bool($_POST['active'] ?? '1') ? 1 : 0;
        $data = [
            $name, $_POST['version'] ?: '1', $method, json_encode($media, JSON_UNESCAPED_SLASHES), '[]',
            $_POST['overwrite_pattern'] ?: null, $num, $_POST['verification'] ?: 'readback',
            $req, web_bool($_POST['approval_required']) ? 1 : 0, $_POST['certificate_text'] ?: null,
            $active, now_iso(),
        ];
        if ($id === '') {
            $pdo->prepare('INSERT INTO erasure_profile (id, name, version, method, allowed_media_types, standards, overwrite_pattern, overwrite_passes, verification, required_fields, approval_required, certificate_text, active, updated_at, deleted) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)')
                ->execute([uuid_v4(), ...$data]);
            web_flash_set('ok', 'Profil oprettet.');
        } else {
            $pdo->prepare('UPDATE erasure_profile SET name=?, version=?, method=?, allowed_media_types=?, standards=?, overwrite_pattern=?, overwrite_passes=?, verification=?, required_fields=?, approval_required=?, certificate_text=?, active=?, updated_at=? WHERE id=?')
                ->execute([...$data, $id]);
            web_flash_set('ok', 'Profil opdateret.');
        }
    }
    header('Location: /profiles.php');
    exit;
}

// ---- Deactivate ----
// (Moved into the POST handler above; destructive actions are POST-only.)

$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM erasure_profile WHERE id=? AND deleted=0');
    $st->execute([$_GET['edit']]);
    $edit = $st->fetch();
    $editMedia = $edit ? json_decode($edit['allowed_media_types'] ?? '[]', true) : [];
    $editVerification = $edit['verification'];
} else {
    $editMedia = [];
    $editVerification = 'readback';
}

$mediaOpts = [
    'hdd' => 'HDD (mekanisk)', 'ssd' => 'SSD', 'nvme' => 'NVMe', 'usb' => 'USB', 'sd' => 'SD-kort',
];
$mediaHtml = '';
foreach ($mediaOpts as $val => $label) {
    $sel = in_array($val, $editMedia, true) || (!$edit && $val === 'hdd') ? 'checked' : '';
    $mediaHtml .= '<label class="check-line"><input type="checkbox" name="allowed_media_types[]" value="' . $val . '"' . ($sel ? ' checked' : '') . '> ' . $label . '</label>';
}

$methods = ['clear', 'purge', 'cryptographic_erase', 'device_sanitize', 'overwrite', 'custom'];
$v = fn($k, $d = '') => $edit ? e($edit[$k] ?? $d) : e($d);
$rows = $pdo->query('SELECT * FROM erasure_profile WHERE deleted=0 ORDER BY name')->fetchAll();
$listRows = '';
foreach ($rows as $r) {
    $badge = web_bool($r['active']) ? '<span class="badge ok">aktiv</span>' : '<span class="badge muted">inaktiv</span>';
    $action = $canEdit
        ? '<a class="btn small" href="/profiles.php?edit=' . e($r['id']) . '">Redigér</a> '
        . web_delete_form('/profiles.php', $r['id'], 'Slet', 'Deaktivér?')
        : '';
    $listRows .= '<tr><td>' . e($r['name']) . '</td><td>' . e($r['version']) . '</td><td>' . e($r['method']) . '</td><td>' . e(implode(', ', json_decode($r['allowed_media_types'] ?? '[]', true) ?? [])) . '</td><td>' . $badge . '</td><td class="actions">' . $action . '</td></tr>';
}

$methodOpts = '';
foreach ($methods as $m) {
    $sel = ($edit && $edit['method'] === $m) ? ' selected' : '';
    $methodOpts .= '<option value="' . $m . '"' . $sel . '>' . e($m) . '</option>';
}
$form = '';
if ($canEdit) {
    $form = '<form class="form-card" method="post" action="/profiles.php">'
        . web_csrf_field()
        . '<input type="hidden" name="id" value="' . ($edit ? e($edit['id']) : '') . '">'
        . '<fieldset><legend>' . ($edit ? 'Redigér profil' : 'Ny sletteprofil') . '</legend>'
        . '<div class="form-row"><label>Navn *</label><input type="text" name="name" value="' . $v('name') . '" required></div>'
        . '<div class="form-row"><label>Version</label><input type="text" name="version" value="' . $v('version', '1') . '"></div>'
        . '<div class="form-row"><label>Metode</label><select name="method">' . $methodOpts . '</select></div>'
        . '<div class="form-row"><label>Medietyper</label>' . $mediaHtml . '</div>'
        . '<div class="form-row"><label>Overwrite-mønster</label><input type="text" name="overwrite_pattern" value="' . $v('overwrite_pattern') . '"></div>'
        . '<div class="form-row"><label>Overwrite-gennemløb</label><input type="number" name="overwrite_passes" value="' . $v('overwrite_passes') . '"></div>'
        . '<div class="form-row"><label>Verifikation</label><select name="verification">'
        . '<option value="readback">Readback</option><option value="device_status">Device status</option>'
        . '<option value="readback_and_device_status">Readback + status</option><option value="none">Ingen</option></select></div>'
        . '<div class="form-row check-line"><input type="checkbox" name="approval_required" value="1" ' . (($edit && web_bool($edit['approval_required'])) ? 'checked' : '') . '> Godkendelse påkrævet</div>'
        . '<div class="form-row"><label>Certifikattekst</label><textarea name="certificate_text">' . $v('certificate_text') . '</textarea></div>'
        . '<div class="form-row check-line"><input type="checkbox" name="active" value="1" ' . ((!$edit || web_bool($edit['active'])) ? 'checked' : '') . '> Aktiv</div>'
        . '<div class="form-actions"><button class="btn primary" type="submit">Gem</button><a class="btn" href="/profiles.php">Annullér</a></div>'
        . '</fieldset></form>';
}

$body = '<div class="split">'
    . '<div><div class="toolbar"><span class="muted">' . count($rows) . ' profiler</span></div>'
    . '<table class="list"><thead><tr><th>Navn</th><th>Version</th><th>Metode</th><th>Medietyper</th><th>Status</th><th></th></tr></thead><tbody>' . $listRows . '</tbody></table></div>'
    . '<div>' . $form . '</div></div>';

render_page($user, 'Sletteprofiler', 'profiles', $body);
