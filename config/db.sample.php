<?php
/**
 * GovernIT Data Eraser - Web configuration.
 *
 * COPY this file to db.php (which is git-ignored) and fill in the real
 * credentials from Config_diskeraser.odt. NEVER commit db.php.
 *
 * This file (db.sample.php) is safe to commit - it only contains placeholders.
 */

return [
    'db' => [
        'host'     => 'ckozkl4kj.mysql.service.one.com',
        'port'     => 3306,
        'name'     => 'sbay_dataeraser',
        'user'     => 'sbay',
        'password' => 'REPLACE_WITH_DB_PASS_FROM_CONFIG_ODT', // do not commit
        'charset'  => 'utf8mb4',
    ],
    'app' => [
        'name'        => 'GovernIT Data Eraser Portal',
        'base_url'    => 'https://eraser.governit.dk',
        'timezone'    => 'Europe/Copenhagen',
        'base_path'   => '', // e.g. '/api' if not in webroot; '' = webroot
        'sync_token'  => 'SHARED_SYNC_SECRET_REPLACE_ME', // HMAC secret for local->web sync
    ],
    'mail' => [
        // 'mail' uses PHP mail(); 'log' writes messages to mail.log_dir (dev).
        'transport' => 'mail',
        'from'      => 'no-reply@eraser.governit.dk',
        'from_name' => 'GovernIT Data Eraser',
        'log_dir'   => '', // optional; defaults to sys temp dir when transport=log
    ],
];
