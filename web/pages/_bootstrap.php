<?php
/**
 * Shared bootstrap for the server-rendered web pages.
 *
 * Starts a PHP session, connects to the DB, and provides:
 *  - auth guard (redirect to login unless logged in)
 *  - the current user + roles
 *  - `render_page()` layout helper with nav + flash messages
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validate.php';

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

// Render a generic message page on unhandled errors; details go to the log only.
set_exception_handler(function (Throwable $e): void {
    error_log('[governit] page error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<h1>500</h1><p>Der opstod en intern fejl. Prøv igen senere.</p>';
    exit;
});

/** Current logged-in user row or null (PHP-session based web login). */
function web_current_user(PDO $pdo): ?array
{
    $uid = $_SESSION['web_user_id'] ?? null;
    if (!$uid) {
        return null;
    }
    $u = find_user_by_id($pdo, $uid);
    if ($u === null || (int)$u['active'] !== 1) {
        unset($_SESSION['web_user_id']);
        return null;
    }
    $u['roles'] = roles_for_user($pdo, $u['id']);
    return $u;
}

function web_require_login(PDO $pdo): array
{
    $user = web_current_user($pdo);
    if ($user === null) {
        header('Location: /login.php');
        exit;
    }
    return $user;
}

function web_has_role(array $user, string ...$roles): bool
{
    foreach ($roles as $r) {
        if (in_array($r, $user['roles'] ?? [], true)) {
            return true;
        }
    }
    return false;
}

function web_require_role(array $user, string ...$roles): void
{
    if (!web_has_role($user, ...$roles)) {
        http_response_code(403);
        echo '<h1>403 Forbudt</h1><p>Du har ikke adgang til denne funktion.</p>';
        exit;
    }
}

function web_flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function web_flash_take(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** Escape HTML output. */
function e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function web_bool(int|bool|string|null $v): bool
{
    return in_array($v, [1, true, '1', 'on'], true);
}

/** Generate or return the session CSRF token. */
function web_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input carrying the CSRF token (for <form method="post">). */
function web_csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(web_csrf_token()) . '">';
}

/** Validate the CSRF token on state-changing requests; exits 403 on mismatch. */
function web_csrf_validate(): void
{
    $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $sent)) {
        http_response_code(403);
        echo '<h1>403 Forbudt</h1><p>Ugyldig eller manglende sikkerhedstoken. Prøv igen.</p>';
        exit;
    }
}

/** Inline POST form used for destructive actions (delete/archive). */
function web_delete_form(string $actionUrl, string $id, string $label, string $confirmMsg): string
{
    return '<form method="post" action="' . e($actionUrl) . '" style="display:inline" '
        . 'onsubmit="return confirm(\'' . htmlspecialchars($confirmMsg, ENT_QUOTES) . '\')">'
        . web_csrf_field()
        . '<input type="hidden" name="_action" value="delete">'
        . '<input type="hidden" name="id" value="' . e($id) . '">'
        . '<button class="btn small danger" type="submit">' . e($label) . '</button></form>';
}

/** Render the shared page chrome (header, nav, flash, footer) around $body. */
function render_page(array $user, string $title, string $active, string $body): void
{
    $navGroups = [
        'Overblik' => [
            'dashboard'    => ['/dashboard.php', 'Dashboard'],
            'sessions'     => ['/sessions.php', 'Sessioner'],
            'certificates' => ['/certificates.php', 'Certifikater'],
        ],
        'Administration' => [
            'customers'    => ['/customers.php', 'Kunder'],
            'projects'     => ['/projects.php', 'Projekter'],
            'technicians'  => ['/technicians.php', 'Teknikere'],
            'profiles'     => ['/profiles.php', 'Sletteprofiler'],
            'organisation' => ['/organisation.php', 'Organisation'],
            'users'        => ['/users.php', 'Brugere'],
            'roles'        => ['/roles.php', 'Roller & adgange'],
        ],
        'Rapporter' => [
            'reports'      => ['/reports.php', 'Rapporter'],
        ],
        'Support' => [
            'downloads'    => ['/downloads.php', 'Klientprogram'],
        ],
    ];
    $navHtml = '';
    foreach ($navGroups as $group => $items) {
        $navHtml .= '<div class="nav-group"><span class="nav-label">' . e($group) . '</span>';
        foreach ($items as $key => [$href, $label]) {
            $cls = $key === $active ? ' class="active"' : '';
            $navHtml .= "<a href=\"$href\"$cls>" . e($label) . '</a>';
        }
        $navHtml .= '</div>';
    }
    $flash = '';
    foreach (web_flash_take() as $f) {
        $tone = $f['type'] === 'ok' ? 'ok' : 'bad';
        $flash .= "<div class=\"flash $tone\">" . e($f['message']) . '</div>';
    }
    ?><!DOCTYPE html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · GovernIT Data Eraser</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<aside class="sidebar">
  <a class="brand" href="/dashboard.php"><img class="brand-logo" src="/assets/governit-logo.png" alt="GovernIT"></a>
  <nav class="nav"><?= $navHtml ?></nav>
  <div class="signed-as">
    <div><?= e($user['display_name'] ?? $user['username']) ?></div>
    <div class="muted"><?= e(implode(', ', $user['roles'] ?? [])) ?></div>
    <form method="post" action="/logout.php">
      <?= web_csrf_field() ?>
      <button class="signed-out" type="submit">Log ud</button>
    </form>
  </div>
</aside>
<main class="content">
  <h1><?= e($title) ?></h1>
  <?= $flash ?>
  <?= $body ?>
</main>
</body>
</html><?php
}
