<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/pages/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Location: /login.php');
    exit;
}

web_csrf_validate();

$pdo = db_connect();
$uid = $_SESSION['web_user_id'] ?? null;
if ($uid) {
    // Revoke every bearer token issued for this user so API sessions die too.
    $st = $pdo->prepare('DELETE FROM web_session WHERE user_id = ?');
    $st->execute([$uid]);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: /login.php');
exit;