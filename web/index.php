<?php
/**
 * GovernIT Data Eraser - Web front controller / API router.
 *
 * All routes return JSON. This is the contract the local Management app calls
 * to sync master data down and push session/certificate results up.
 */
declare(strict_types=1);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/validate.php';

require __DIR__ . '/api/auth.php';
require __DIR__ . '/api/users.php';
require __DIR__ . '/api/invitations.php';
require __DIR__ . '/api/roles.php';
require __DIR__ . '/api/customers.php';
require __DIR__ . '/api/projects.php';
require __DIR__ . '/api/technicians.php';
require __DIR__ . '/api/profiles.php';
require __DIR__ . '/api/organisation.php';
require __DIR__ . '/api/sync.php';
require __DIR__ . '/api/reports.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = '/' . ltrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
// If deployed under a subdirectory, set 'base_path' in config/db.php (default '').
$base = rtrim(app_config()['app']['base_path'] ?? '', '/');
if ($base !== '' && str_starts_with($path, $base . '/')) {
    $path = '/' . ltrim(substr($path, strlen($base)), '/');
}

// Health endpoint (no auth, informs bootstrap/installer tooling).
if ($path === '/health' && $method === 'GET') {
    try {
        $pdo = db_connect();
        $tables = $pdo->query(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE()"
        )->fetchAll(PDO::FETCH_COLUMN);
        json_respond([
            'ok'      => true,
            'service' => app_config()['app']['name'],
            'time'    => gmdate('c'),
            'tables'  => count($tables),
            'schema'  => in_array('erasure_session', $tables, true)
                        && in_array('certificate', $tables, true)
                        ? 'migrated' : 'pending-migration',
        ]);
    } catch (Throwable $e) {
        error_log('[governit] health db error: ' . $e->getMessage());
        json_error('database_error', 500);
    }
}

// Root: redirect to the login page (server-rendered portal).
if ($path === '/' && $method === 'GET') {
    header('Location: /login.php');
    exit;
}

// Server-rendered administration pages (multi-page portal).
$pages = [
    '/dashboard.php'    => __DIR__ . '/pages/dashboard.php',
    '/customers.php'    => __DIR__ . '/pages/customers.php',
    '/projects.php'     => __DIR__ . '/pages/projects.php',
    '/technicians.php'  => __DIR__ . '/pages/technicians.php',
    '/users.php'        => __DIR__ . '/pages/users.php',
    '/roles.php'        => __DIR__ . '/pages/roles.php',
    '/profiles.php'     => __DIR__ . '/pages/profiles.php',
    '/organisation.php' => __DIR__ . '/pages/organisation.php',
    '/sessions.php'     => __DIR__ . '/pages/sessions.php',
    '/certificates.php' => __DIR__ . '/pages/certificates.php',
    '/reports.php'      => __DIR__ . '/pages/reports.php',
    '/downloads.php'    => __DIR__ . '/pages/downloads.php',
    '/login.php'        => __DIR__ . '/login.php',
    '/logout.php'       => __DIR__ . '/logout.php',
];
if (isset($pages[$path])) {
    require $pages[$path];
    exit;
}

if ($path === '/api/v1/meta' && $method === 'GET') {
    json_respond([
        'version'  => '0.2.0',
        'name'     => 'GovernIT Data Eraser API',
        'auth'     => 'Bearer (web user) or X-Sync-Token',
        'endpoints'=> [
            'GET  /health',
            'GET  /api/v1/meta',
            'POST /api/v1/auth/login',
            'POST /api/v1/auth/logout',
            'GET  /api/v1/auth/me',
            'POST /api/v1/users/{id}/invitations',
            'POST /api/v1/auth/invitations/accept',
            'POST /api/v1/auth/password/reset-request',
            'POST /api/v1/auth/password/reset',
            'CRUD /api/v1/users',
            'CRUD /api/v1/roles',
            'CRUD /api/v1/customers',
            'CRUD /api/v1/projects',
            'CRUD /api/v1/technicians',
            'CRUD /api/v1/profiles',
            'GET/PUT /api/v1/organisation',
            'GET  /api/v1/query/master-data',
            'GET  /api/v1/query/sessions',
            'GET  /api/v1/sync/pull-config',
            'POST /api/v1/sync/push-results',
            'POST /api/v1/reports/generate',
            'GET  /api/v1/reports',
            'GET  /api/v1/reports/{id}/pdf',
        ],
    ]);
}

try {
    $pdo = db_connect();

    if (route_invitations($method, $path, $pdo)) json_respond(['routed' => 'invitations']);
    if (route_auth($method, $path, $pdo))      json_respond(['routed' => 'auth']);
    if (route_users($method, $path, $pdo))     json_respond(['routed' => 'users']);
    if (route_roles($method, $path, $pdo))     json_respond(['routed' => 'roles']);
    if (route_customers($method, $path, $pdo)) json_respond(['routed' => 'customers']);
    if (route_projects($method, $path, $pdo))  json_respond(['routed' => 'projects']);
    if (route_technicians($method, $path, $pdo)) json_respond(['routed' => 'technicians']);
    if (route_profiles($method, $path, $pdo))  json_respond(['routed' => 'profiles']);
    if (route_organisation($method, $path, $pdo)) json_respond(['routed' => 'organisation']);
    if (route_sync($method, $path, $pdo))      json_respond(['routed' => 'sync']);
    if (route_reports($method, $path, $pdo))   json_respond(['routed' => 'reports']);
} catch (Throwable $e) {
    // Log details server-side only; the client gets a generic error so no
    // schema/credentials/host details leak through the API.
    error_log('[governit] api error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_error('internal_error', 500);
}

json_error('not_found: ' . $method . ' ' . $path, 404);
