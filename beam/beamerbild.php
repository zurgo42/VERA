<?php
/**
 * beamerbild.php - Das öffentliche Beamerbild eines BEAM-Projekts, siehe
 * neu/beamerbild.php (dasselbe Prinzip). Anders als dort: KEIN Login nötig
 * - BEAM hat keine projektgebundene Mitgliederliste, gegen die sich prüfen
 * ließe, und das Bild ist ohnehin nur eine passive Anzeige (siehe
 * dokumentation/BEAM.md).
 */

require_once __DIR__ . '/beam_functions.php';

$pdo = get_pdo();
$projektId = (int)($_GET['projekt'] ?? 0);
$projekt = beam_projekt_get($pdo, $projektId);

if (!$projekt) {
    http_response_code(404);
    die('Dieses Projekt existiert nicht (mehr).');
}

$daten = beamer_vorschau_daten($pdo, $projektId);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta http-equiv="refresh" content="2">
<meta name="robots" content="noindex,nofollow">
<title>Beamerbild - <?= h($projekt['titel']) ?></title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    html, body { height: 100%; }
<?= beamer_vorschau_css() ?>
</style>
</head>
<body>
<?= beamer_vorschau_render($daten, $projektId, true, true, $projekt['titel'], '../wlan_qr.php') ?>
</body>
</html>
