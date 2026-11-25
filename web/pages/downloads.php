<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$pdo  = db_connect();
$user = web_require_login($pdo);

$macVersion = '0.0.11';
$linuxVersion = '0.0.11';

$items = [
    ['Windows', 'GovernIT_Data_Eraser_Management_0.0.11_x64-setup.exe', 'Installer (.exe) – x64. Klargøring af fysiske boot-USB-stik er deaktiveret.', '108 MB'],
    ['macOS', 'GovernIT_Data_Eraser_Management_' . $macVersion . '_arm64.pkg', 'Installer (.pkg) – Apple Silicon', '110 MB'],
    ['macOS', 'GovernIT_Data_Eraser_Management_' . $macVersion . '_arm64.zip', 'Bærbar udgave (.zip) – Apple Silicon', '110 MB'],
    ['Linux', 'GovernIT_Data_Eraser_Management_' . $linuxVersion . '_amd64.deb', 'Debian/Ubuntu (.deb) – x86_64', '111 MB'],
    ['Linux', 'GovernIT_Data_Eraser_Management_' . $linuxVersion . '_amd64.AppImage', 'AppImage (kørbar) – x86_64', '111 MB'],
];

$rows = '';
$lastOs = '';
foreach ($items as [$os, $file, $desc, $size]) {
    $osCell = $os !== $lastOs ? '<b>' . e($os) . '</b>' : '';
    $lastOs = $os;
    if ($file === null) {
        $rows .= '<tr><td>' . $osCell . '</td><td colspan="3" class="muted">' . e($desc)
            . ' <span class="badge muted">Kommer snart</span></td></tr>';
        continue;
    }
    $rows .= '<tr><td>' . $osCell . '</td>'
        . '<td>' . e($desc) . '<div class="muted" style="font-size:11px">' . e($file) . '</div></td>'
        . '<td>' . e($size) . '</td>'
        . '<td class="actions"><a class="btn small primary" href="/downloads/' . rawurlencode($file) . '">Download</a></td></tr>';
}

$body = '<div class="toolbar"><span class="muted">GovernIT Data Eraser Management – klientprogrammet til arbejdsstationen</span></div>'
    . '<table class="list"><thead><tr><th>Platform</th><th>Fil</th><th>Størrelse</th><th></th></tr></thead><tbody>'
    . $rows . '</tbody></table>'
    . '<p class="muted" style="font-size:12px;margin-top:12px">macOS: kræver Apple Silicon (arm64). Linux AppImage: '
    . 'giv filen eksekveringsrettighed (chmod +x) og kør den direkte.</p>';

render_page($user, 'Klientprogram', 'downloads', $body);
