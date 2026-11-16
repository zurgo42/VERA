<?php
declare(strict_types=1);

/**
 * datenbank_backup.php - Liefert ein vollstaendiges SQL-Backup aller
 * VERA/BEAM-Tabellen als Download (siehe backup_functions.php). Nur dem
 * Redaktionsteam zugaenglich, wie rohprotokoll.php/abstimmung_pdf.php.
 */

require_once __DIR__ . '/functions_neu.php';
require_once __DIR__ . '/protokoll_functions.php';
require_once __DIR__ . '/backup_functions.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php';
    session_start();
}

$pdo = get_pdo();
$jahr = get_aktuelles_jahr($pdo);
$mitglied = get_aktuelle_mnr($pdo) !== '' ? get_aktuelles_mitglied($pdo, $jahr) : null;

if (!ist_redaktion($mitglied)) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

$sql = datenbank_backup_erzeugen($pdo);
mv_log($pdo, $jahr, $mitglied, 'Datenbank-Backup erzeugt', strlen($sql) . ' Byte');

$dateiname = 'vera-backup-' . date('Y-m-d_His') . '.sql';
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $dateiname . '"');
header('Content-Length: ' . strlen($sql));
echo $sql;
