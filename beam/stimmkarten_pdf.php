<?php
declare(strict_types=1);

/**
 * stimmkarten_pdf.php - Lieferung einer erzeugten Stimmkarten-Sammeldatei,
 * siehe neu/abstimmung_pdf.php (dasselbe Prinzip). Nur Personen mit
 * Steuerungsrecht zugänglich - die Datei enthält die Klartext-QR-Codes
 * aller Stimmkarten.
 */

require_once __DIR__ . '/../session_config.php';
session_start();
require_once __DIR__ . '/beam_functions.php';

$pdo = get_pdo();
$rechte = beam_aktuelle_rechte($pdo);

if (!$rechte['eingeloggt'] || !$rechte['darf_steuern']) {
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
