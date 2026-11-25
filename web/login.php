<?php
/**
 * Web login page (PHP-session based). Verifies against web_user table.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

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

/** Generate or return existing CSRF token for this session. */
function web_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function web_csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(web_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function web_csrf_validate(): void
{
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'csrf_invalid']) ;
        exit;
    }
}

$pdo = db_connect();
$error = null;

// --- Rate-limit login attempts (file-based, 5 per minute per IP) ---
$rl_dir = sys_get_temp_dir() . '/diskeraser_ratelimit';
if (!is_dir($rl_dir)) { @mkdir($rl_dir, 0700); }
$rl_key = preg_replace('/[^a-f0-9]/', '', md5($_SERVER['REMOTE_ADDR'] ?? '0'));
$rl_file = $rl_dir . '/login_' . $rl_key;
$rl_count = 0;
$rl_blocked = false;
if (is_file($rl_file)) {
    $data = @json_decode(file_get_contents($rl_file), true);
    if ($data && time() - ($data['ts'] ?? 0) < 60) {
        $rl_count = $data['count'] ?? 0;
        if ($rl_count >= 5) {
            $rl_blocked = true;
        }
    } else {
        $rl_count = 0;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($rl_blocked) {
        $error = 'For mange forsøg. Vent et øjeblik og prøv igen.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $u = $username !== '' ? find_user_by_username($pdo, $username) : null;
        if ($u === null || (int)$u['active'] !== 1 || !password_verify($password, $u['password_hash'])) {
            $rl_count++;
            @file_put_contents($rl_file, json_encode(['count' => $rl_count, 'ts' => time()]));
            $error = 'Forkert brugernavn eller adgangskode.';
        } else {
            @unlink($rl_file);
            session_regenerate_id(true);
            $_SESSION['web_user_id'] = $u['id'];
            record_login($pdo, $u['id']);
            header('Location: /dashboard.php');
            exit;
        }
    }
}
?><!DOCTYPE html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log ind · GovernIT Data Eraser</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<div class="login-wrap">
  <form class="login-card" method="post" action="/login.php">
    <?= web_csrf_field() ?>
    <img class="login-logo" src="/assets/governit-logo.png" alt="GovernIT">
    <h1>Data Eraser</h1>
    <div class="sub">Admin- og rapportportal</div>
    <?php if ($error): ?><div class="flash bad"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <div class="form-row">
      <label>Brugernavn</label>
      <input type="text" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autofocus required>
    </div>
    <div class="form-row">
      <label>Adgangskode</label>
      <input type="password" name="password" required>
    </div>
    <button class="btn primary" type="submit">Log ind</button>
  </form>
</div>
</body>
</html>
