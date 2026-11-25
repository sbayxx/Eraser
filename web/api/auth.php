<?php
/**
 * AUTH resource: /api/v1/auth/*  (public login, protected logout/me).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

/** File-based login throttle: max $max per 60s window per IP. Returns attempts remaining, 0 when blocked. */
function login_throttle(int $max = 5, int $window = 60): int
{
    $dir = sys_get_temp_dir() . '/diskeraser_ratelimit';
    if (!is_dir($dir)) { @mkdir($dir, 0700); }
    $key = preg_replace('/[^a-f0-9]/', '', md5($_SERVER['REMOTE_ADDR'] ?? '0'));
    $file = $dir . '/api_' . $key;
    if (is_file($file)) {
        $data = @json_decode(file_get_contents($file), true);
        if ($data && time() - ($data['ts'] ?? 0) < $window) {
            $left = $max - (int)($data['count'] ?? 0);
            return max(0, $left);
        }
    }
    return $max;
}

function login_throttle_hit(int $max = 5, int $window = 60): void
{
    $dir = sys_get_temp_dir() . '/diskeraser_ratelimit';
    if (!is_dir($dir)) { @mkdir($dir, 0700); }
    $key = preg_replace('/[^a-f0-9]/', '', md5($_SERVER['REMOTE_ADDR'] ?? '0'));
    $file = $dir . '/api_' . $key;
    $count = 1;
    if (is_file($file)) {
        $data = @json_decode(file_get_contents($file), true);
        if ($data && time() - ($data['ts'] ?? 0) < $window) {
            $count = (int)($data['count'] ?? 0) + 1;
        }
    }
    @file_put_contents($file, json_encode(['count' => $count, 'ts' => time()]));
}

function login_throttle_clear(): void
{
    $key = preg_replace('/[^a-f0-9]/', '', md5($_SERVER['REMOTE_ADDR'] ?? '0'));
    $file = sys_get_temp_dir() . '/diskeraser_ratelimit/api_' . $key;
    if (is_file($file)) { @unlink($file); }
}

function route_auth(string $method, string $path, PDO $pdo): bool
{
    if (str_starts_with($path, '/api/v1/auth/')) {
        header('Cache-Control: no-store');
    }

    // POST /api/v1/auth/login
    if ($method === 'POST' && $path === '/api/v1/auth/login') {
        if (isset($_SERVER['HTTP_X_ERASER_PORTAL']) && !is_portal_request()) {
            json_error('forbidden', 403);
        }
        $left = login_throttle(5, 60);
        if ($left <= 0) {
            json_error('rate_limited', 429);
        }
        $in = request_json();
        $username = req('username', $in);
        $password = req('password', $in);

        $user = find_user_by_username($pdo, $username);
        if ($user === null || (int)$user['active'] !== 1
            || !password_verify($password, $user['password_hash'])) {
            login_throttle_hit(5, 60);
            json_error('invalid_credentials', 401);
        }
        login_throttle_clear();
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
            $nh = $pdo->prepare('UPDATE web_user SET password_hash = ? WHERE id = ?');
            $nh->execute([password_hash($password, PASSWORD_BCRYPT), $user['id']]);
        }
        record_login($pdo, $user['id']);
        $token = issue_token($pdo, $user['id']);
        if (is_portal_request()) {
            $previous = portal_session_token();
            if ($previous !== null) {
                revoke_token($pdo, $previous);
            }
            set_portal_session_cookie($token['token'], (new DateTimeImmutable($token['expires_at']))->getTimestamp());
            unset($token['token'], $token['token_type']);
        }
        json_respond(array_merge([
            'user' => [
                'id'           => $user['id'],
                'username'     => $user['username'],
                'email'        => $user['email'],
                'display_name' => $user['display_name'],
                'technician_id'=> $user['technician_id'],
                'roles'        => roles_for_user($pdo, $user['id']),
            ],
        ], $token), 200);
    }

    if (str_starts_with($path, '/api/v1/auth')) {
        $user = require_auth($pdo);

        if ($method === 'POST' && $path === '/api/v1/auth/logout') {
            $token = request_auth_token();
            if ($token !== null) {
                revoke_token($pdo, $token);
            }
            if (is_portal_request()) {
                set_portal_session_cookie('', time() - 3600);
            }
            json_respond(['ok' => true]);
        }

        if ($method === 'GET' && $path === '/api/v1/auth/me') {
            json_respond([
                'user' => [
                    'id'           => $user['id'],
                    'username'     => $user['username'],
                    'email'        => $user['email'],
                    'display_name' => $user['display_name'],
                    'technician_id'=> $user['technician_id'],
                    'active'       => (int)$user['active'] === 1,
                    'roles'        => $user['roles'],
                ],
            ]);
        }
    }

    return false;
}
