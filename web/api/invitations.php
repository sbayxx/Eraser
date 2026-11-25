<?php
/**
 * INVITATIONS / PASSWORD-RESET resource.
 *
 *  POST /api/v1/users/{id}/invitations          (Admin) issue + email an invite
 *  POST /api/v1/auth/invitations/accept         (public) consume invite, set password, activate
 *  POST /api/v1/auth/password/reset-request     (public) email a reset link
 *  POST /api/v1/auth/password/reset             (public) consume token, set a new password
 *
 * One-time tokens are stored hashed (sha256) in invitation_token, matching the
 * bearer-token storage convention. Invited users are created inactive with a
 * NULL password_hash and are activated on first accept.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/mail.php';

/** Create a new one-time token and email it. Returns the raw token. */
function create_and_send_token(PDO $pdo, string $userId, string $email, string $type, string $subject, string $bodyPrefix): string
{
    $raw = bin2hex(random_bytes(32));
    $ttl = $type === 'invite' ? (60 * 60 * 24 * 7) : (60 * 60 * 2); // 7d invite / 2h reset

    $st = $pdo->prepare(
        'INSERT INTO invitation_token (id, user_id, email, token_hash, type, created_at, expires_at)
         VALUES (?,?,?,?,?,?,?)'
    );
    $st->execute([
        uuid_v4(),
        $userId,
        strtolower($email),
        hash('sha256', $raw),
        $type,
        now_iso(),
        gmdate('Y-m-d\TH:i:s\Z', time() + $ttl),
    ]);

    $url = portal_base_url() . '/setpassword.php?t=' . $raw . '&type=' . $type;
    $html = "<p>$bodyPrefix</p><p><a href=\"" . e($url) . "\">Aktivér adgangskode</a></p>"
        . "<p>Eller kopiér linket: " . e($url) . "</p>"
        . "<p>Linket er gyldigt indtil " . gmdate('Y-m-d H:i', time() + $ttl) . " UTC.</p>"
        . "<p>Hvis du ikke har bedt om dette, kan du ignorere denne mail.</p>";
    send_mail($email, $subject, $html);
    return $raw;
}

function route_invitations(string $method, string $path, PDO $pdo): bool
{
    // ---- Public: accept an invitation -------------------------------------
    if ($method === 'POST' && $path === '/api/v1/auth/invitations/accept') {
        $in    = request_json();
        $token = req('token', $in);
        $pass  = req('password', $in);
        if (strlen($pass) < 8) {
            json_error('invalid_field: password (min 8 chars)', 422);
        }
        $st = $pdo->prepare(
            'SELECT id, user_id, email, type, expires_at, used_at
             FROM invitation_token WHERE token_hash = ? AND type = "invite"'
        );
        $st->execute([hash('sha256', $token)]);
        $tok = $st->fetch();
        if ($tok === false || $tok['expires_at'] < now_iso() || $tok['used_at'] !== null) {
            json_error('invalid_or_expired_token', 410);
        }
        // Consume the token (and any outstanding ones for the same user).
        $del = $pdo->prepare(
            'UPDATE invitation_token SET used_at = ? WHERE user_id = ? AND used_at IS NULL'
        );
        $del->execute([now_iso(), $tok['user_id']]);

        $pdo->prepare(
            'UPDATE web_user SET password_hash = ?, needs_password_change = 0, active = 1, updated_at = ? WHERE id = ?'
        )->execute([password_hash($pass, PASSWORD_BCRYPT), now_iso(), $tok['user_id']]);
        json_respond(['ok' => true, 'user_id' => $tok['user_id']]);
    }

    // ---- Public: request a password reset ---------------------------------
    if ($method === 'POST' && $path === '/api/v1/auth/password/reset-request') {
        $in    = request_json();
        $email = req('email', $in);
        $st = $pdo->prepare('SELECT id, email, active FROM web_user WHERE email = ?');
        $st->execute([strtolower($email)]);
        $u = $st->fetch();
        // Always respond ok to avoid user enumeration.
        if ($u !== false && (int)$u['active'] === 1) {
            create_and_send_token(
                $pdo, $u['id'], $u['email'], 'reset',
                'Nulstil din adgangskode',
                'Du har anmodet om at nulstille din adgangskode.'
            );
        }
        json_respond(['ok' => true]);
    }

    // ---- Public: reset with a token ---------------------------------------
    if ($method === 'POST' && $path === '/api/v1/auth/password/reset') {
        $in    = request_json();
        $token = req('token', $in);
        $pass  = req('password', $in);
        if (strlen($pass) < 8) {
            json_error('invalid_field: password (min 8 chars)', 422);
        }
        $st = $pdo->prepare(
            'SELECT id, user_id, type, expires_at, used_at
             FROM invitation_token WHERE token_hash = ? AND type = "reset"'
        );
        $st->execute([hash('sha256', $token)]);
        $tok = $st->fetch();
        if ($tok === false || $tok['expires_at'] < now_iso() || $tok['used_at'] !== null) {
            json_error('invalid_or_expired_token', 410);
        }
        $del = $pdo->prepare(
            'UPDATE invitation_token SET used_at = ? WHERE user_id = ? AND used_at IS NULL'
        );
        $del->execute([now_iso(), $tok['user_id']]);
        $pdo->prepare(
            'UPDATE web_user SET password_hash = ?, needs_password_change = 0, updated_at = ? WHERE id = ?'
        )->execute([password_hash($pass, PASSWORD_BCRYPT), now_iso(), $tok['user_id']]);
        json_respond(['ok' => true]);
    }

    // ---- Protected: admin issues an invite for a user ----------------------
    if (preg_match('#^/api/v1/users/([0-9a-fA-F-]{36})/invitations$#', $path, $m)
        && $method === 'POST') {
        $user = require_auth($pdo);
        require_role($pdo, $user, 'SystemAdministrator', 'Administrator', 'Koordinator', 'KundeAdministrator');

        $target = get_user_by_id($pdo, $m[1]);
        if ($target === null) {
            json_error('not_found', 404);
        }
        $email = $target['email'];
        if ($email === null || $email === '') {
            json_error('no_email_for_user', 422);
        }
        create_and_send_token(
            $pdo, $target['id'], $email, 'invite',
            'Du er inviteret til GovernIT Data Eraser',
            'Hej ' . ($target['display_name'] ?: $target['username']) . ', du er oprettet som bruger. Sæt din adgangskode via linket:'
        );
        json_respond(['ok' => true]);
    }

    return false;
}
