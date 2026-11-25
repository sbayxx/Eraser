<?php
/**
 * Public set-password page for invitation acceptance and password reset.
 *  /setpassword.php?t=<raw_token>&type=invite|reset
 */
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/validate.php';

function e(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Generate or return the session CSRF token. */
function pw_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function pw_csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(pw_csrf_token()) . '">';
}

function pw_csrf_validate(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $sent)) {
        http_response_code(403);
        echo '<h1>403 Forbudt</h1><p>Ugyldig eller manglende sikkerhedstoken. Prøv igen.</p>';
        exit;
    }
}

$token = $_GET['t'] ?? '';
$type  = $_GET['type'] ?? 'invite';
if ($token === '' || !in_array($type, ['invite', 'reset'], true)) {
    http_response_code(400);
    echo '<h1>Ugyldigt link</h1><p>Linket er ugyldigt eller mangler parametre.</p>';
    exit;
}

$error = '';
$ok    = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    pw_csrf_validate();
    $pass  = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';
    if (strlen($pass) < 8) {
        $error = 'Adgangskode skal være mindst 8 tegn.';
    } elseif ($pass !== $pass2) {
        $error = 'Adgangskoderne er ikke ens.';
    } else {
        $pdo = db_connect();
        $st  = $pdo->prepare(
            'SELECT id, user_id, type, expires_at, used_at
             FROM invitation_token WHERE token_hash = ? AND type = ?'
        );
        $st->execute([hash('sha256', $token), $type]);
        $tok = $st->fetch();
        if ($tok === false || $tok['expires_at'] < now_iso() || $tok['used_at'] !== null) {
            $error = 'Linket er ugyldigt eller er udløbet.';
        } else {
            $pdo->prepare(
                'UPDATE invitation_token SET used_at = ? WHERE user_id = ? AND used_at IS NULL'
            )->execute([now_iso(), $tok['user_id']]);
            $pw = password_hash($pass, PASSWORD_BCRYPT);
            $act = $type === 'invite' ? ', active = 1' : '';
            $pdo->prepare(
                "UPDATE web_user SET password_hash = ?, needs_password_change = 0{$act}, updated_at = ? WHERE id = ?"
            )->execute([$pw, now_iso(), $tok['user_id']]);
            $ok = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sæt adgangskode · GovernIT Data Eraser</title>
<link rel="stylesheet" href="/assets/style.css">
<style>
.auth-wrap{max-width:400px;margin:10vh auto;padding:32px;border:1px solid #ddd;border-radius:8px;background:#fff}
.auth-wrap h1{font-size:1.3em;margin:0 0 16px}
.auth-wrap label{display:block;margin:12px 0 4px;font-weight:600}
.auth-wrap input[type=password]{width:100%;padding:8px;box-sizing:border-box}
.auth-wrap .actions{margin-top:18px}
.btn{padding:8px 16px;border:none;border-radius:4px;cursor:pointer;font-size:.9em}
.btn.primary{background:#1a6fb5;color:#fff}
.bad{color:#b00;margin:8px 0}
.ok{color:#080;margin:8px 0}
.hint{color:#666;font-size:.85em;margin-top:12px}
</style>
</head>
<body>
<div class="auth-wrap">
<?php if ($ok): ?>
  <h1>Kodeord er sat</h1>
  <p>Du kan nu logge ind med dit nye kodeord.</p>
  <p><a class="btn primary" href="/login.php">Log ind</a></p>
<?php else: ?>
  <h1><?= $type === 'invite' ? 'Sæt din adgangskode' : 'Nulstil din adgangskode' ?></h1>
  <?php if ($error): ?><div class="bad"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="/setpassword.php?t=<?= e($token) ?>&type=<?= e($type) ?>">
    <?= pw_csrf_field() ?>
    <label>Adgangskode (min. 8 tegn)</label>
    <input type="password" name="password" required minlength="8" autofocus>
    <label>Gentag adgangskode</label>
    <input type="password" name="password2" required minlength="8">
    <div class="actions"><button class="btn primary" type="submit">Gem kodeord</button></div>
  </form>
<?php endif; ?>
</div>
</body>
</html>
