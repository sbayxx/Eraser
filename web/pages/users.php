<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/mail.php';

$pdo  = db_connect();
$user = web_require_login($pdo);
web_require_role($user, 'SystemAdministrator', 'Administrator', 'Koordinator', 'KundeAdministrator');

$roles    = $pdo->query('SELECT id, name FROM role ORDER BY name')->fetchAll();
$customers = $pdo->query('SELECT id, name, customer_number FROM customer WHERE deleted = 0 AND active = 1 ORDER BY name')->fetchAll();

// ---- Actions: POST (create/edit/delete) ----------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    web_csrf_validate();
    $act = $_POST['_action'] ?? 'save';

    // Deactivate (soft-delete) a user
    if ($act === 'delete' && isset($_POST['id'])) {
        $delId = $_POST['id'];
        if ($delId === $user['id']) {
            web_flash_set('bad', 'Du kan ikke deaktivere dig selv.');
        } else {
            $pdo->prepare('UPDATE web_user SET active=0, updated_at=? WHERE id=?')->execute([now_iso(), $delId]);
            web_flash_set('ok', 'Bruger deaktiveret.');
        }
        header('Location: /users.php');
        exit;
    }

    // Invite: send invitation email to existing user
    if ($act === 'invite' && isset($_POST['invite_id'])) {
        $targetId = $_POST['invite_id'];
        $st = $pdo->prepare('SELECT id, username, display_name, email FROM web_user WHERE id = ?');
        $st->execute([$targetId]);
        $target = $st->fetch();
        if ($target === false) {
            web_flash_set('bad', 'Bruger ikke fundet.');
        } elseif (empty($target['email'])) {
            web_flash_set('bad', 'Brugeren har ingen e-mailadresse og kan ikke inviteres.');
        } else {
            $raw = bin2hex(random_bytes(32));
            $ttl = 60 * 60 * 24 * 7;
            $pdo->prepare(
                'INSERT INTO invitation_token (id, user_id, email, token_hash, type, created_at, expires_at)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([
                uuid_v4(), $target['id'], strtolower($target['email']),
                hash('sha256', $raw), 'invite', now_iso(),
                gmdate('Y-m-d\TH:i:s\Z', time() + $ttl),
            ]);
            $url = portal_base_url() . '/setpassword.php?t=' . $raw . '&type=invite';
            $html = '<p>Hej ' . e($target['display_name'] ?: $target['username'])
                . ', du er oprettet som bruger i GovernIT Data Eraser Portal.</p>'
                . '<p>Sæt din adgangskode via linket:</p>'
                . '<p><a href="' . e($url) . '">Aktivér adgangskode</a></p>'
                . '<p>Linket er gyldigt i 7 dage.</p>';
            send_mail($target['email'], 'Du er inviteret til GovernIT Data Eraser', $html);
            web_flash_set('ok', 'Invitation sendt til ' . e($target['email']) . '.');
        }
        header('Location: /users.php');
        exit;
    }

    // Create / edit
    if ($act === 'save') {
        $id        = $_POST['id'] ?? '';
        $username  = trim($_POST['username'] ?? '');
        $roleNames = $_POST['roles'] ?? [];
        $pass      = $_POST['password'] ?? '';
        $custIds   = $_POST['customer_ids'] ?? [];

        if ($username === '') {
            web_flash_set('bad', 'Brugernavn er påkrævet.');
        } elseif ($id === '') {
            // New user
            $invite = isset($_POST['invite_mode']) && $_POST['invite_mode'] === '1';
            if ($invite) {
                // Create inactive user, send invite
                if (empty($_POST['email'])) {
                    web_flash_set('bad', 'E-mail er påkrævet for invitation.');
                } else {
                    $uid = uuid_v4();
                    $pdo->prepare(
                        'INSERT INTO web_user (id, username, email, display_name, password_hash, active, needs_password_change, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)'
                    )->execute([$uid, $username, $_POST['email'], $_POST['display_name'] ?: $username, null, 0, 1, now_iso(), now_iso()]);
                    sync_user_roles($pdo, $uid, $roleNames);
                    sync_user_customer_access($pdo, $uid, $custIds);
                    // Send invitation email
                    $raw  = bin2hex(random_bytes(32));
                    $ttl  = 60 * 60 * 24 * 7;
                    $pdo->prepare(
                        'INSERT INTO invitation_token (id, user_id, email, token_hash, type, created_at, expires_at) VALUES (?,?,?,?,?,?,?)'
                    )->execute([uuid_v4(), $uid, strtolower($_POST['email']), hash('sha256', $raw), 'invite', now_iso(), gmdate('Y-m-d\TH:i:s\Z', time() + $ttl)]);
                    $url  = portal_base_url() . '/setpassword.php?t=' . $raw . '&type=invite';
                    $html = '<p>Hej ' . e($_POST['display_name'] ?: $username)
                        . ', du er oprettet som bruger i GovernIT Data Eraser Portal.</p>'
                        . '<p>Sæt din adgangskode via linket:</p>'
                        . '<p><a href="' . e($url) . '">Aktivér adgangskode</a></p>'
                        . '<p>Linket er gyldigt i 7 dage.</p>';
                    send_mail($_POST['email'], 'Du er inviteret til GovernIT Data Eraser', $html);
                    web_flash_set('ok', 'Bruger oprettet og invitation sendt til ' . e($_POST['email']) . '.');
                }
            } else {
                // Create active user with password
                if (strlen($pass) < 8) {
                    web_flash_set('bad', 'Adgangskode skal være mindst 8 tegn.');
                } else {
                    $uid = uuid_v4();
                    $pdo->prepare('INSERT INTO web_user (id, username, email, display_name, password_hash, active, needs_password_change, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
                        ->execute([$uid, $username, $_POST['email'] ?: null, $_POST['display_name'] ?: $username, password_hash($pass, PASSWORD_BCRYPT), web_bool($_POST['active'] ?? '1') ? 1 : 0, 0, now_iso(), now_iso()]);
                    sync_user_roles($pdo, $uid, $roleNames);
                    sync_user_customer_access($pdo, $uid, $custIds);
                    web_flash_set('ok', 'Bruger oprettet.');
                }
            }
        } else {
            // Edit existing
            $pdo->prepare('UPDATE web_user SET username=?, email=?, display_name=?, active=?, updated_at=? WHERE id=?')
                ->execute([$username, $_POST['email'] ?: null, $_POST['display_name'] ?: $username, web_bool($_POST['active'] ?? '1') ? 1 : 0, now_iso(), $id]);
            if ($pass !== '') {
                if (strlen($pass) >= 8) {
                    $pdo->prepare('UPDATE web_user SET password_hash=?, needs_password_change=0 WHERE id=?')->execute([password_hash($pass, PASSWORD_BCRYPT), $id]);
                } else {
                    web_flash_set('bad', 'Adgangskode skal være mindst 8 tegn (ikke ændret).');
                }
            }
            sync_user_roles($pdo, $id, $roleNames);
            sync_user_customer_access($pdo, $id, $custIds);
            web_flash_set('ok', 'Bruger opdateret.');
        }
        header('Location: /users.php');
        exit;
    }
}

// ---- Actions: deactivate --------------------------------------------------
// (Moved into the POST handler above; destructive actions are POST-only.)

// ---- Helpers --------------------------------------------------------------
function sync_user_roles(PDO $pdo, string $uid, array $roleNames): void
{
    $del = $pdo->prepare('DELETE FROM web_user_role WHERE user_id=?');
    $del->execute([$uid]);
    if ($roleNames === []) return;
    $ph = implode(',', array_fill(0, count($roleNames), '?'));
    $st = $pdo->prepare("SELECT id FROM role WHERE name IN ($ph)");
    $st->execute($roleNames);
    $ins = $pdo->prepare('INSERT IGNORE INTO web_user_role (user_id, role_id) VALUES (?,?)');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        $ins->execute([$uid, $rid]);
    }
}

function sync_user_customer_access(PDO $pdo, string $uid, array $customerIds): void
{
    $del = $pdo->prepare('DELETE FROM user_customer_access WHERE user_id = ?');
    $del->execute([$uid]);
    if ($customerIds === []) return;
    $ins = $pdo->prepare('INSERT IGNORE INTO user_customer_access (user_id, customer_id, created_at, updated_at) VALUES (?,?,?,?)');
    $now = now_iso();
    foreach ($customerIds as $cid) {
        $ins->execute([$uid, $cid, $now, $now]);
    }
}

// ---- Load data for form ---------------------------------------------------
$edit = null;
$editRoleNames  = [];
$editCustIds    = [];
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM web_user WHERE id=?');
    $st->execute([$_GET['edit']]);
    $edit = $st->fetch();
    if ($edit) {
        $editRoleNames = roles_for_user($pdo, $edit['id']);
        $st2 = $pdo->prepare('SELECT customer_id FROM user_customer_access WHERE user_id = ?');
        $st2->execute([$edit['id']]);
        $editCustIds = array_column($st2->fetchAll(), 'customer_id');
    }
}

// ---- Build table ----------------------------------------------------------
$rows = $pdo->query('SELECT id, username, email, display_name, active, last_login_at FROM web_user ORDER BY username')->fetchAll();
$listRows = '';
foreach ($rows as $r) {
    $badge = web_bool($r['active']) ? '<span class="badge ok">aktiv</span>' : '<span class="badge muted">inaktiv</span>';
    $roleStr = implode(', ', roles_for_user($pdo, $r['id']));
    $st3 = $pdo->prepare('SELECT c.name FROM user_customer_access uca JOIN customer c ON c.id = uca.customer_id WHERE uca.user_id = ? ORDER BY c.name');
    $st3->execute([$r['id']]);
    $custs = implode(', ', array_column($st3->fetchAll(), 'name'));
    $listRows .= '<tr><td>' . e($r['username']) . '</td><td>' . e($r['display_name']) . '</td><td>' . e($r['email'] ?? '') . '</td><td>' . e($roleStr) . '</td><td>' . ($custs ? '<span class="badge">' . e($custs) . '</span>' : '<span class="muted">-</span>') . '</td><td>' . $badge . '</td><td class="actions">'
        . '<a class="btn small" href="/users.php?edit=' . e($r['id']) . '">Redigér</a> '
        . web_delete_form('/users.php', $r['id'], 'Slet', 'Deaktivér?') . '</td></tr>';
}

// ---- Build role checkboxes ------------------------------------------------
$roleOpts = '';
foreach ($roles as $role) {
    $sel = ($edit && in_array($role['name'], $editRoleNames, true)) ? ' checked' : '';
    $roleOpts .= '<label class="check-line"><input type="checkbox" name="roles[]" value="' . e($role['name']) . '"' . $sel . '> ' . e($role['name']) . '</label>';
}

// ---- Build customer checkboxes --------------------------------------------
$custOpts = '';
foreach ($customers as $c) {
    $sel = ($edit && in_array($c['id'], $editCustIds, true)) ? ' checked' : '';
    $custOpts .= '<label class="check-line"><input type="checkbox" name="customer_ids[]" value="' . e($c['id']) . '"' . $sel . '> ' . e($c['name']) . ' <span class="muted">(' . e($c['customer_number']) . ')</span></label>';
}

$v = fn($k, $d = '') => $edit ? e($edit[$k] ?? $d) : e($d);
$isInvite = $edit && !$edit['active'] && $edit['password_hash'] === null;

$form = '<form class="form-card" method="post" action="/users.php">'
    . web_csrf_field()
    . '<input type="hidden" name="id" value="' . ($edit ? e($edit['id']) : '') . '">'
    . ($edit ? '<input type="hidden" name="invite_mode" value="' . ($isInvite ? '1' : '0') . '">' : '')
    . '<fieldset><legend>' . ($edit ? 'Redigér bruger' : 'Ny bruger') . '</legend>'
    . '<div class="form-row"><label>Brugernavn *</label><input type="text" name="username" value="' . $v('username') . '" required></div>'
    . '<div class="form-row"><label>Visningsnavn</label><input type="text" name="display_name" value="' . $v('display_name') . '"></div>'
    . '<div class="form-row"><label>E-mail</label><input type="email" name="email" value="' . $v('email') . '"></div>';

if ($edit) {
    $form .= '<div class="form-row"><label>Adgangskode</label><input type="password" name="password">'
        . '<div class="hint">Lad være tom for at beholde nuværende adgangskode.</div></div>';
} else {
    $form .= '<div class="form-row check-line"><input type="checkbox" id="invite_mode_toggle" name="invite_mode" value="1" onchange="var p=document.getElementById(\'pw_row\');p.style.display=this.checked?\'none\':\'block\';document.getElementsByName(\'password\')[0].required=!this.checked;">'
        . ' <label for="invite_mode_toggle">Send invitation i stedet for at sætte adgangskode</label></div>'
        . '<div class="form-row" id="pw_row"><label>Adgangskode</label><input type="password" name="password" required></div>';
}

$form .= '<div class="form-row"><label>Roller</label>' . ($roleOpts ?: '<span class="muted">Ingen roller</span>') . '</div>';

if ($custOpts) {
    $form .= '<div class="form-row"><label>Kunder (adgangsområde)</label>' . $custOpts
        . '<div class="hint">Kundeadministrator og KundeRapporter roller er begrænset til de valgte kunder.</div></div>';
}

$form .= '<div class="form-row check-line"><input type="checkbox" name="active" value="1" ' . ((!$edit || web_bool($edit['active'])) ? 'checked' : '') . '> Aktiv</div>'
    . '<div class="form-actions"><button class="btn primary" type="submit">Gem</button><a class="btn" href="/users.php">Annullér</a></div>'
    . '</fieldset></form>';

$body = '<div class="split">'
    . '<div><div class="toolbar"><span class="muted">' . count($rows) . ' brugere</span></div>'
    . '<table class="list"><colgroup><col style="width:13%"><col style="width:15%"><col style="width:17%"><col style="width:14%"><col style="width:10%"><col style="width:12%"><col style="width:19%"></colgroup><thead><tr><th>Brugernavn</th><th>Navn</th><th>E-mail</th><th>Roller</th><th>Kunder</th><th>Status</th><th></th></tr></thead><tbody>' . $listRows . '</tbody></table></div>'
    . '<div>' . $form . '</div></div>';

render_page($user, 'Brugere & adgang', 'users', $body);
