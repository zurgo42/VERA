<?php
declare(strict_types=1);

/**
 * beamer_vorschau_status.php - Schlanker JSON-Endpunkt für die kleine
 * Live-Vorschau auf steuerung.php, siehe neu/beamer_vorschau_status.php
 * (dasselbe Prinzip, hier für ein BEAM-Projekt statt ein VERA-Jahr).
 */

require_once __DIR__ . '/../session_config.php';
session_start();
require_once __DIR__ . '/beam_functions.php';

$pdo = get_pdo();
$rechte = beam_aktuelle_rechte($pdo);
$projektId = (int)($_GET['projekt'] ?? 0);
$projekt = beam_projekt_get($pdo, $projektId);

header('Content-Type: application/json; charset=utf-8');

if (!$rechte['eingeloggt'] || !$rechte['darf_steuern'] || !$projekt || !beam_projekt_berechtigt($projekt, $rechte)) {
    http_response_code(403);
    echo json_encode(['fehler' => 'Keine Berechtigung.']);
    exit;
}

$daten = beamer_vorschau_daten($pdo, $projektId);
echo json_encode(['html' => beamer_vorschau_render($daten, $projektId, true, true, $projekt['titel'], '../wlan_qr.php')]);
