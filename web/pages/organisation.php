<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$pdo  = db_connect();
$user = web_require_login($pdo);
$canEdit = web_has_role($user, 'Administrator');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canEdit) {
    web_csrf_validate();
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        web_flash_set('bad', 'Navn er påkrævet.');
    } else {
        $cur = $pdo->query('SELECT id FROM organisation LIMIT 1')->fetch();
        if ($cur === false) {
            $pdo->prepare('INSERT INTO organisation (id, name, organisation_id, contact, updated_at, deleted) VALUES (?,?,?,?,?,0)')
                ->execute([uuid_v4(), $name, $_POST['organisation_id'] ?: '', $_POST['contact'] ?: null, now_iso()]);
        } else {
            $pdo->prepare('UPDATE organisation SET name=?, organisation_id=?, contact=?, updated_at=? WHERE id=?')
                ->execute([$name, $_POST['organisation_id'] ?: '', $_POST['contact'] ?: null, now_iso(), $cur['id']]);
        }
        web_flash_set('ok', 'Organisation opdateret.');
    }
    header('Location: /organisation.php');
    exit;
}

$org = $pdo->query('SELECT * FROM organisation LIMIT 1')->fetch();
$v = fn($k, $d = '') => $org ? e($org[$k] ?? $d) : e($d);

$form = '<form class="form-card" method="post" action="/organisation.php">'
    . web_csrf_field()
    . '<fieldset><legend>Organisation</legend>'
    . '<div class="form-row"><label>Navn</label><input type="text" name="name" value="' . $v('name') . '" required></div>'
    . '<div class="form-row"><label>Organisations-id</label><input type="text" name="organisation_id" value="' . $v('organisation_id') . '"></div>'
    . '<div class="form-row"><label>Kontakt</label><input type="text" name="contact" value="' . $v('contact') . '"></div>'
    . ($canEdit ? '<div class="form-actions"><button class="btn primary" type="submit">Gem</button></div>' : '<div class="muted">Du har kun læseadgang.</div>')
    . '</fieldset></form>';

render_page($user, 'Organisation', 'organisation', $form);
