<?php
/**
 * Mockup: certificate PDF generated with the real PdfReport class and sample
 * data, so the visual output matches production exactly.
 *
 * Run:  php mockups/certificate_mockup.php        (writes certificate_mockup.pdf)
 */
declare(strict_types=1);

require __DIR__ . '/../web/includes/pdfreport.php';

$pdf = new PdfReport();
$pdf->header('Certifikat for sletning', 'GovernIT Data Eraser - Nordbjerg & Hansen A/S');

// --- Oversigt ---
$pdf->section('Oversigt');
$pdf->field('Kunde', 'Nordbjerg & Hansen A/S (K-1042)');
$pdf->field('Projekt', 'Udskiftning af IT-infrastruktur');
$pdf->field('Projektnr.', 'P-2026-0187');
$pdf->field('Status', 'Gennemført');
$pdf->field('Generering', '2026-09-25 14:32:08');
$pdf->field('Tekniker', 'Mikkel Sørensen');
$pdf->field('Certifikat-id', '8f2c1a7e-9d4b-4c2e-9b81-3f7c6a1e9d42');
$pdf->spacer(6);

// --- Drev / Computer ---
$pdf->section('Drev / Computer');
$pdf->field('Drev', 'Samsung SSD 870 EVO 1TB');
$pdf->field('Serienr. (drev)', 'S3Z9NX0M123456');
$pdf->field('Serienr. (computer)', 'CNX9347G2H');
$pdf->field('Asset tag', 'NBH-2026-0147');
$pdf->field('Drevet størrelse (bytes)', '1.000.204.886.016');
$pdf->spacer(6);

// --- Sletning ---
$pdf->section('Sletning');
$pdf->field('Profil', 'NIST 800-88 Purge (SSD) v1');
$pdf->field('Metode', 'cryptographic_erase');
$pdf->field('Start', '2026-09-25 09:12:44');
$pdf->field('Slut', '2026-09-25 09:18:21');
$pdf->field('Verifikation', 'readback');
$pdf->field('Resultat', 'PASS');
$pdf->spacer(4);

// --- Integritet ---
$pdf->section('Integritet');
$pdf->paragraph('Dokumentet er genereret af GovernIT Data Eraser Management Portal. '
    . 'Certifikatet er verifikationsbaseret og klassificeret i henhold til den anvendte sletningsprofil.');

file_put_contents(__DIR__ . '/certificate_mockup.pdf', $pdf->output());
echo "Wrote mockups/certificate_mockup.pdf\n";