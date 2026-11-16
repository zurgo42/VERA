<?php
declare(strict_types=1);

/**
 * abstimmung_pdf.php - Lieferung einer erzeugten Stimmkarten-Sammeldatei
 * (Abstimmungsmaschine, Phase 1). Nur dem Redaktionsteam zugänglich - die
 * Datei enthält die Klartext-QR-Codes aller Stimmkarten, ist also kein
 * öffentlicher Download wie ein normales Dokument.
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

if (!ist_redaktion($mitglied)) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

$datei = basename((string)($_GET['datei'] ?? ''));
$pfad = vote_storage_dir() . '/' . $datei;
if ($datei === '' || !preg_match('/^stimmkarten-(\d+-)?\d{8}-\d{6}\.pdf$/', $datei) || !is_file($pfad)) {
    http_response_code(404);
    die('Datei nicht gefunden.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $datei . '"');
header('Content-Length: ' . filesize($pfad));
readfile($pfad);
