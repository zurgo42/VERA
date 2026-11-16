<?php
declare(strict_types=1);

/**
 * abstimmung_csv.php - Lieferung eines CSV-Protokolls einer ausgewerteten
 * Abstimmungsrunde, siehe neu/abstimmung_csv.php (dasselbe Prinzip). Die
 * Datei enthält nur Token-Hashes, Zeitpunkte und Stimmen, keinen
 * Mitgliedsbezug.
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
if ($datei === '' || !preg_match('/^abstimmung-\d+-\d{8}-\d{6}\.csv$/', $datei) || !is_file($pfad)) {
    http_response_code(404);
    die('Datei nicht gefunden.');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $datei . '"');
header('Content-Length: ' . filesize($pfad));
readfile($pfad);
