<?php
/**
 * beamerbild.php - Das eigentliche Beamerbild (Ablösung von mvbeamer.php).
 * Bewusst eine eigenständige Seite ohne Menü/Kopfbereich der übrigen
 * Neukonzeption - sie füllt den Bildschirm/Beamer und aktualisiert sich
 * laufend per Meta-Refresh, genau wie im Altsystem. Die Steuerung
 * erfolgt von der Beamersteuerung aus (tab_beamersteuerung.php), die den
 * Inhalt in mvtexte.beamer schreibt - diese Seite liest ihn nur.
 */

require_once __DIR__ . '/functions_neu.php';
require_once __DIR__ . '/beamer_functions.php';
require_once __DIR__ . '/abstimmung_functions.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php';
    session_start();
}

$pdo = get_pdo();
$jahr = isset($_GET['jahr']) ? (int)$_GET['jahr'] : get_aktuelles_jahr($pdo);
$mitglied = get_aktuelle_mnr($pdo) !== '' ? get_aktuelles_mitglied($pdo, $jahr) : null;

if (!$mitglied) {
    http_response_code(403);
    die('Bitte zuerst im MV-Tool anmelden.');
}

// Alle Daten (inkl. Timer-Ablauf-Pruefung) kommen aus beamer_vorschau_daten() -
// siehe dort: dieselbe Funktion speist auch die kleine Live-Vorschau auf der
// Beamersteuerung, damit beide niemals voneinander abweichen koennen.
$daten = beamer_vorschau_daten($pdo, $jahr);
$config = $daten['config'];
$mvtexte = $daten['mvtexte'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta http-equiv="refresh" content="2">
<meta name="robots" content="noindex,nofollow">
<title>Beamerbild — <?= h($config['mv_bezeichnung']) ?> <?= h((string)$jahr) ?></title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    html, body { height: 100%; }
<?= beamer_vorschau_css() ?>
</style>
</head>
<body>
<?= beamer_vorschau_render($daten, $jahr) ?>
</body>
</html>
