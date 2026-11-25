<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$pdo  = db_connect();
$user = web_require_login($pdo);
web_require_role($user, 'Administrator');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    web_csrf_validate();

    if (($_POST['_action'] ?? '') === 'delete' && isset($_POST['id'])) {
        $pdo->prepare('DELETE FROM role WHERE id=?')->execute([$_POST['id']]);
        web_flash_set('ok', 'Rolle slettet.');
        header('Location: /roles.php');
        exit;
    }

    $id = $_POST['id'] ?? '';
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        web_flash_set('bad', 'Navn er påkrævet.');
    } elseif ($id === '') {
        try {
            $pdo->prepare('INSERT INTO role (id, name, description) VALUES (?,?,?)')
                ->execute([uuid_v4(), $name, $_POST['description'] ?: null]);
            web_flash_set('ok', 'Rolle oprettet.');
        } catch (Throwable $e) {
            web_flash_set('bad', 'Rollenavn findes allerede.');
        }
    } else {
        $pdo->prepare('UPDATE role SET name=?, description=? WHERE id=?')
            ->execute([$name, $_POST['description'] ?: null, $id]);
        web_flash_set('ok', 'Rolle opdateret.');
    }
    header('Location: /roles.php');
    exit;
}

// ---- Delete ----
// (Moved into the POST handler above; destructive actions are POST-only.)

$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM role WHERE id=?');
    $st->execute([$_GET['edit']]);
    $edit = $st->fetch();
}

$rows = $pdo->query('SELECT id, name, description FROM role ORDER BY name')->fetchAll();
$listRows = '';
foreach ($rows as $r) {
    $listRows .= '<tr><td>' . e($r['name']) . '</td><td>' . e($r['description'] ?? '') . '</td><td class="actions">'
        . '<a class="btn small" href="/roles.php?edit=' . e($r['id']) . '">Redigér</a> '
        . web_delete_form('/roles.php', $r['id'], 'Slet', 'Slet rollen?') . '</td></tr>';
}

$v = fn($k, $d = '') => $edit ? e($edit[$k] ?? $d) : e($d);
$form = '<form class="form-card" method="post" action="/roles.php">'
    . web_csrf_field()
    . '<input type="hidden" name="id" value="' . ($edit ? e($edit['id']) : '') . '">'
    . '<fieldset><legend>' . ($edit ? 'Redigér rolle' : 'Ny rolle') . '</legend>'
    . '<div class="form-row"><label>Navn *</label><input type="text" name="name" value="' . $v('name') . '" required></div>'
    . '<div class="form-row"><label>Beskrivelse</label><input type="text" name="description" value="' . $v('description') . '"></div>'
    . '<div class="form-actions"><button class="btn primary" type="submit">Gem</button><a class="btn" href="/roles.php">Annullér</a></div>'
    . '</fieldset></form>';

$body = '<div class="split">'
    . '<div><div class="toolbar"><span class="muted">' . count($rows) . ' roller</span></div>'
    . '<table class="list"><thead><tr><th>Navn</th><th>Beskrivelse</th><th></th></tr></thead><tbody>' . $listRows . '</tbody></table></div>'
    . '<div>' . $form . '</div></div>';

render_page($user, 'Roller & adgange', 'roles', $body);
