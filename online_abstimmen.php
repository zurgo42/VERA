<?php
declare(strict_types=1);

/**
 * online_abstimmen.php - Warteraum/Token-Ausgabe für online teilnehmende
 * Mitglieder (siehe Admin-Installationsanleitung.md, Abschnitt 10e, Phase
 * 5). Setzt eine bestehende Anmeldung voraus und dass sich das Mitglied in
 * teilnahmeform.php auf "online" festgelegt hat (Phase 4) - sonst könnte
 * sich jemand sowohl vor Ort als auch online eine Stimme verschaffen.
 *
 * Solange keine Abstimmungsrunde offen ist, zeigt die Seite nur einen
 * Wartehinweis mit automatischem Neuladen (Meta-Refresh, kein JavaScript
 * nötig - passend zum Rest dieses Projekts, siehe uebung.php/beamerbild.php).
 * Sobald die Versammlungsleitung eine Runde eröffnet, erzeugt der nächste
 * Aufruf dieser Seite (durch das Neuladen) serverseitig einen frischen, an
 * GENAU diese Runde gebundenen Token (siehe online_token_ausgeben() in
 * abstimmung_functions.php) und leitet direkt zur normalen Abstimmungsseite
 * weiter (abstimmen.php) - identischer, vollständig anonymer Stimmzettel
 * wie für Mitglieder vor Ort (der Token trägt keine Mitgliedsnummer).
 *
 * Der ausgegebene Token wird NUR in der eigenen Sitzung dieses Mitglieds
 * gemerkt, nie dauerhaft mit der MNr verknüpft gespeichert. Geht diese
 * Sitzung während einer laufenden Runde verloren (Browser geschlossen,
 * Gerät gewechselt), bleibt diese eine Runde für dieses Mitglied
 * ungültig - ein zweiter Zugang wird bewusst NICHT automatisch
 * ausgegeben (das wäre sonst ein Schlupfloch für doppelte Stimmen), die
 * Person wendet sich an die Versammlungsleitung. Betrifft also im
 * schlimmsten Fall nur eine einzelne Abstimmung, nicht die ganze
 * Versammlung.
 */

require_once __DIR__ . '/functions_neu.php';
require_once __DIR__ . '/protokoll_functions.php'; // fuer mv_log()
require_once __DIR__ . '/abstimmung_functions.php';

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
$teilnahmeform = $mitglied['Teilnahmeform'] ?? null;

if ($teilnahmeform !== 'online') {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Online-Abstimmung — <?= h($config['vereinsname']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f6f8; color: #222; min-height: 100vh; }
        .karte { max-width: 400px; margin: 60px auto; background: white; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.12); padding: 24px; text-align: center; }
        a.btn { display: inline-block; margin-top: 16px; padding: 12px 20px; background: #005596; color: white; border-radius: 8px; text-decoration: none; }
    </style>
    </head>
    <body>
    <div class="karte">
        <p>Die Online-Abstimmung steht nur für Mitglieder zur Verfügung, die sich als „Online-Teilnahme“ festgelegt haben.</p>
        <a class="btn" href="teilnahmeform.php">Teilnahmeform wählen</a>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$round = vote_round_aktuell($pdo, $jahr);

if (!$round) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta http-equiv="refresh" content="5">
    <title>Online-Abstimmung — <?= h($config['vereinsname']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f6f8; color: #222; min-height: 100vh; }
        .kopf { background: #005596; color: white; padding: 14px 18px; }
        .kopf h1 { font-size: 16px; margin: 0; }
        .warten { min-height: 70vh; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 24px; gap: 18px; }
        .spinner { width: 46px; height: 46px; border: 5px solid #e0e0e0; border-top-color: #005596; border-radius: 50%; animation: drehen 0.9s linear infinite; }
        .erklaerung { max-width: 340px; color: #555; font-size: 14px; line-height: 1.5; }
        @keyframes drehen { to { transform: rotate(360deg); } }
    </style>
    </head>
    <body>
    <div class="kopf"><h1><?= h($config['vereinsname']) ?> – Online-Abstimmung</h1></div>
    <div class="warten">
        <div class="spinner"></div>
        <div><strong>Warte auf die nächste Abstimmung …</strong></div>
        <div class="erklaerung">Diese Seite lädt sich automatisch neu. Sobald die Versammlungsleitung eine Abstimmung eröffnet, erscheint hier direkt der Stimmzettel - bitte geöffnet lassen.</div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// Runde ist offen - eigenen Token aus der Sitzung holen oder neu ausgeben.
$sessionSchluessel = 'online_token_round_' . $round['round_id'];
$token = $_SESSION[$sessionSchluessel] ?? null;

if ($token === null) {
    if (online_bereits_ausgegeben($pdo, (int)$round['round_id'], $mnr)) {
        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!DOCTYPE html>
        <html lang="de">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex,nofollow">
        <title>Online-Abstimmung — <?= h($config['vereinsname']) ?></title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f6f8; color: #222; min-height: 100vh; }
            .karte { max-width: 400px; margin: 60px auto; background: white; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.12); padding: 24px; text-align: center; color: #7a1414; }
        </style>
        </head>
        <body>
        <div class="karte">Für diese Abstimmungsrunde wurde bereits ein Zugang ausgegeben, dieser ist in dieser Sitzung aber nicht mehr bekannt (z. B. Browser gewechselt oder Sitzung verloren). Bitte wenden Sie sich an die Versammlungsleitung.</div>
        </body>
        </html>
        <?php
        exit;
    }
    $token = online_token_ausgeben($pdo, $jahr, (int)$round['round_id'], $mnr);
    if ($token === null) {
        // Race: ein paralleler Request (z. B. zweiter Tab) hat zwischen der
        // Pruefung oben und hier bereits ausgegeben - einfach neu laden,
        // dann greift der Sitzungs-Zweig oben (falls derselbe Tab) oder die
        // "bereits ausgegeben"-Meldung (falls ein anderer Tab).
        header('Location: online_abstimmen.php');
        exit;
    }
    $_SESSION[$sessionSchluessel] = $token;
    mv_log($pdo, $jahr, $mitglied, 'Online-Abstimmungstoken ausgegeben', 'MNr ' . $mnr . ' - Runde ' . $round['round_id']);
}

header('Location: abstimmen.php?token=' . rawurlencode($token));
