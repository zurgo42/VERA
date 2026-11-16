<?php
declare(strict_types=1);

/**
 * teilnahmeform.php - Mitglied legt sich vor der Versammlung auf "vor Ort"
 * oder "online" fest (siehe Admin-Installationsanleitung.md, Abschnitt
 * 10e, Phase 4) - Grundlage dafür, dass die Einlasskontrolle
 * (ausweis_pruefen.php: einlass_einschaetzung()) niemandem sowohl eine
 * Stimmkarte vor Ort als auch eine Online-Teilnahme erlaubt.
 *
 * Nur sichtbar/nutzbar, wenn die Vorgabe hybride_versammlung_aktiv gesetzt
 * ist (sonst irrelevant für reine Präsenzversammlungen). Die Wahl lässt
 * sich ändern, bis die Einlasskontrolle das Mitglied schon bearbeitet hat
 * (AnwesendSeit gesetzt) - danach bewusst gesperrt, damit niemand nach
 * Erhalt einer Stimmkarte nachträglich auf "online" wechseln und so
 * zusätzlich ein Online-Token bekommen könnte.
 */

require_once __DIR__ . '/functions_neu.php';
require_once __DIR__ . '/protokoll_functions.php'; // fuer mv_log()

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php';
    session_start();
}

$pdo = get_pdo();
$config = get_config($pdo);
$mnr = get_aktuelle_mnr($pdo);

if ($mnr === '') {
    header('Location: login.php');
    exit;
}
if (($config['hybride_versammlung_aktiv'] ?? '0') !== '1') {
    header('Location: index.php');
    exit;
}

$jahr = get_aktuelles_jahr($pdo);
$mitglied = get_aktuelles_mitglied($pdo, $jahr);
$gesperrt = !empty($mitglied['AnwesendSeit'] ?? null);
$fehler = '';
$gespeichert = false;

if (!$gesperrt && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $wahl = (string)($_POST['teilnahmeform'] ?? '');
    if (in_array($wahl, ['vor_ort', 'online'], true)) {
        $tabelle = 'mvmitglieder_' . $jahr;
        $stmt = $pdo->prepare("UPDATE `$tabelle` SET Teilnahmeform = ? WHERE MNr = ?");
        $stmt->execute([$wahl, $mnr]);
        mv_log($pdo, $jahr, $mitglied, 'Teilnahmeform gewählt', 'MNr ' . $mnr . ' - ' . $wahl);
        $mitglied['Teilnahmeform'] = $wahl;
        $gespeichert = true;
    } else {
        $fehler = 'Bitte eine Teilnahmeform auswählen.';
    }
}

$aktuelleWahl = $mitglied['Teilnahmeform'] ?? null;

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Teilnahmeform — <?= h($config['vereinsname']) ?></title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f6f8; color: #222; min-height: 100vh; }
    .kopf { background: #005596; color: white; padding: 14px 18px; }
    .kopf h1 { font-size: 17px; margin: 0; }
    .karte { max-width: 400px; margin: 24px auto; background: white; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.12); padding: 22px; }
    .erklaerung { font-size: 14px; color: #444; line-height: 1.5; margin-bottom: 18px; }
    .aktuell { padding: 12px; border-radius: 8px; background: #e8f5e9; color: #2e7d32; font-weight: 600; text-align: center; margin-bottom: 16px; }
    .gesperrt-hinweis { padding: 12px; border-radius: 8px; background: #fff3cd; color: #664d03; font-size: 13px; margin-top: 16px; line-height: 1.5; }
    .fehler { padding: 10px; border-radius: 8px; background: #fde2e2; color: #7a1414; font-size: 13px; margin-bottom: 14px; }
    .optionen { display: flex; flex-direction: column; gap: 12px; }
    .optionen button { font-size: 16px; padding: 16px; border: none; border-radius: 8px; color: white; cursor: pointer; }
    .btn-vorort { background: #005596; }
    .btn-online { background: #2e7d32; }
    .btn-aktiv { outline: 3px solid #333; }
    .zurueck { display: block; text-align: center; margin: 18px; color: #005596; }
</style>
</head>
<body>
<div class="kopf"><h1><?= h($config['vereinsname']) ?> – Teilnahmeform</h1></div>
<div class="karte">
    <div class="erklaerung">Nehmen Sie an der <?= h($config['mv_bezeichnung']) ?> <?= h((string)$jahr) ?> vor Ort im Saal teil, oder online per Videokonferenz? Diese Angabe entscheidet, ob Sie vor Ort eine Stimmkarte bekommen oder online abstimmen - beides gleichzeitig ist nicht möglich.</div>

    <?php if ($fehler !== ''): ?><div class="fehler"><?= h($fehler) ?></div><?php endif; ?>
    <?php if ($gespeichert): ?><div class="aktuell">✓ Gespeichert: <?= $aktuelleWahl === 'online' ? 'Online-Teilnahme' : 'Vor Ort' ?></div><?php endif; ?>

    <?php if ($gesperrt): ?>
        <div class="aktuell"><?= $aktuelleWahl === 'online' ? '💻 Online-Teilnahme' : ($aktuelleWahl === 'vor_ort' ? '🪑 Vor Ort' : 'Keine Angabe') ?></div>
        <div class="gesperrt-hinweis">Sie wurden bereits am Einlass erfasst - die Teilnahmeform lässt sich jetzt nicht mehr ändern. Bei einem Irrtum wenden Sie sich bitte an die Versammlungsleitung.</div>
    <?php else: ?>
        <form method="post" class="optionen">
            <?= csrf_field() ?>
            <button type="submit" name="teilnahmeform" value="vor_ort" class="btn-vorort<?= $aktuelleWahl === 'vor_ort' ? ' btn-aktiv' : '' ?>">🪑 Vor Ort im Saal</button>
            <button type="submit" name="teilnahmeform" value="online" class="btn-online<?= $aktuelleWahl === 'online' ? ' btn-aktiv' : '' ?>">💻 Online per Videokonferenz</button>
        </form>
    <?php endif; ?>
</div>
<a class="zurueck" href="index.php">← Zurück zur Übersicht</a>
</body>
</html>
