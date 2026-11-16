<?php
declare(strict_types=1);

/**
 * abstimmung_csv.php - Lieferung eines CSV-Protokolls einer ausgewerteten
 * Abstimmungsrunde (Phase 5, siehe abstimmung_functions.php:
 * vote_round_csv_erzeugen()). Zugänglich wie die übrige Live-Steuerung auf
 * der Beamersteuerung (siehe ist_beamersteuerung_berechtigt()) - die Datei
 * enthält nur Token-Hashes, Zeitpunkte und Stimmen, keinen Mitgliedsbezug.
 */

require_once __DIR__ . '/functions_neu.php';
require_once __DIR__ . '/abstimmung_functions.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php';
    session_start();
}

$pdo = get_pdo();
$jahr = get_aktuelles_jahr($pdo);
$mitglied = get_aktuelle_mnr($pdo) !== '' ? get_aktuelles_mitglied($pdo, $jahr) : null;

if (!ist_beamersteuerung_berechtigt($mitglied)) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

$datei = basename((string)($_GET['datei'] ?? ''));
$pfad = vote_storage_dir() . '/' . $datei;
if ($datei === '' || !preg_match('/^abstimmung-\d+-\d{8}-\d{6}\.csv$/', $datei) || !is_file($pfad)) {
    http_response_code(404);
    die('Datei nicht gefunden.');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $datei . '"');
header('Content-Length: ' . filesize($pfad));
readfile($pfad);
