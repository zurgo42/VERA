<?php
declare(strict_types=1);

/**
 * rohprotokoll.php - Lieferung des Rohprotokolls eines Jahres (siehe
 * beamer_functions.php: beamer_protokoll_schreiben()) als Textdatei. Nur dem
 * Redaktionsteam zugänglich, wie die übrige Protokollseite (tab_protokoll.php).
 */

require_once __DIR__ . '/functions_neu.php';
require_once __DIR__ . '/beamer_functions.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php';
    session_start();
}

$pdo = get_pdo();
$jahr = isset($_GET['jahr']) ? (int)$_GET['jahr'] : get_aktuelles_jahr($pdo);
$mitglied = get_aktuelle_mnr($pdo) !== '' ? get_aktuelles_mitglied($pdo, $jahr) : null;

if (!ist_redaktion($mitglied)) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

$pfad = beamer_protokoll_pfad($jahr);
if (!is_file($pfad)) {
    http_response_code(404);
    die('Für dieses Jahr liegt noch kein Rohprotokoll vor.');
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="rohprotokoll-' . $jahr . '.txt"');
header('Content-Length: ' . filesize($pfad));
readfile($pfad);
