<?php
declare(strict_types=1);

/**
 * beamer_vorschau_status.php - Schlanker JSON-Endpunkt für die kleine
 * Live-Vorschau des Beamerbilds auf der Beamersteuerung
 * (tab_beamersteuerung.php) - liefert dasselbe HTML-Fragment wie
 * beamerbild.php (siehe beamer_vorschau_daten()/beamer_vorschau_render()
 * in beamer_functions.php), nur eben als JSON statt als eigene Seite. Wird
 * von dort per JavaScript (fetch, alle paar Sekunden) per innerHTML in die
 * Vorschau eingesetzt - bewusst KEIN iframe mit eigenem Meta-Refresh wie in
 * einer früheren Fassung, das verursachte bei jedem Neuladen ein
 * sichtbares Zucken. Ein reines innerHTML-Ersetzen hat das nicht.
 */

require_once __DIR__ . '/functions_neu.php';
require_once __DIR__ . '/beamer_functions.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php';
    session_start();
}

$pdo = get_pdo();
$jahr = get_aktuelles_jahr($pdo);
$mitglied = get_aktuelle_mnr($pdo) !== '' ? get_aktuelles_mitglied($pdo, $jahr) : null;

header('Content-Type: application/json; charset=utf-8');

if (!ist_beamersteuerung_berechtigt($mitglied)) {
    http_response_code(403);
    echo json_encode(['fehler' => 'Keine Berechtigung.']);
    exit;
}

$daten = beamer_vorschau_daten($pdo, $jahr);
echo json_encode(['html' => beamer_vorschau_render($daten, $jahr)]);
