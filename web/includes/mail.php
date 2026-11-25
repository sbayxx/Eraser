<?php
/**
 * GovernIT Data Eraser - transactional email helper.
 *
 * Uses PHP's built-in mail() transport (the default on shared hosting, e.g.
 * Gigahost). Sender + site name/URL come from config/db.php -> ['mail'] and
 * ['app']. When mail transport is 'log' the message is written to a file
 * instead of sent, which is handy in local dev.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function mail_transport(): string
{
    return (app_config()['mail'] ?? [])['transport'] ?? 'mail';
}

/** Send a transactional email. Returns true on success, false otherwise. */
function send_mail(string $to, string $subject, string $htmlBody): bool
{
    $c  = app_config();
    $m  = $c['mail'] ?? [];
    $from    = $m['from'] ?? 'no-reply@eraser.governit.dk';
    $fromName = $m['from_name'] ?? $c['app']['name'] ?? 'Data Eraser';
    $dir = $m['log_dir'] ?? sys_get_temp_dir();

    $transport = mail_transport();
    if ($transport === 'log') {
        $file = rtrim($dir, '/') . '/mail-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.eml';
        $emoji = "To: $to\nSubject: $subject\nMIME-Version: 1.0\n"
            . "Content-Type: text/html; charset=UTF-8\n\n" . $htmlBody;
        @file_put_contents($file, $emoji);
        return true;
    }

    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . encode_header($fromName) . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'X-Mailer: GovernIT-Data-Eraser',
    ]);
    return @mail($to, encode_header($subject), $htmlBody, $headers);
}

/** RFC-2047 encode a fully UTF-8 subject/name line. */
function encode_header(string $s): string
{
    if (preg_match('/[^\x20-\x7E]/', $s)) {
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }
    return $s;
}

/** Base URL for building links inside emails (taken from app config). */
function portal_base_url(): string
{
    $c = app_config();
    return rtrim($c['app']['base_url'] ?? '', '/') . ($c['app']['base_path'] ?? '');
}
