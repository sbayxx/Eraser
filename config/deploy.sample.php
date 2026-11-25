<?php
/**
 * GovernIT Data Eraser - Deployment credentials (FTP + DB for migration).
 *
 * COPY this file to deploy.php (which is git-ignored) and fill in the real
 * values. NEVER commit deploy.php.
 *
 * This file is only used by the local deploy tooling (scripts/deploy.sh), not
 * by the web application itself.
 */
return [
    'ftp' => [
        'host'     => 'sftp.governit.dk',
        'user'     => 'sbay',
        'password' => 'REPLACE_WITH_FTP_PASS',
        'remote_dir' => 'www/eraser.governit.dk/',
    ],
    'db' => [
        'host'     => 'ckozkl4kj.mysql.service.one.com',
        'port'     => 3306,
        'name'     => 'sbay_dataeraser',
        'user'     => 'sbay',
        'password' => 'REPLACE_WITH_DB_PASS',
    ],
];
