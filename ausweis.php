<?php
declare(strict_types=1);

/**
 * ausweis.php - Elektronischer Mitgliedsausweis: Name, Foto (falls über die
 * Vorgabe foto_quelle_url konfiguriert) und QR-Code zum Vorzeigen bei der
 * Einlasskontrolle (siehe Admin-Installationsanleitung.md, Abschnitt 10e).
 * Der QR-Code selbst kommt aus ausweis_qr.php (eigener Request, erzeugt den
 * Token frisch anhand der aktuellen Anmeldung) - diese Seite zeigt ihn nur
 * als <img> ein.
 *
 * Nur sichtbar/nutzbar, wenn die Vorgabe mitgliedschaftspruefung_aktiv
 * gesetzt ist - ohne Einlasskontrolle (z. B. Vereine mit externer
 * Mitgliederverwaltung/Adapter-Betrieb statt MISS) ergibt die Seite keinen
 * Sinn: kein Foto (kommt nur von MISS), kein Status (ebenfalls nur MISS),
 * kein Einlass-Scan, der sie je lesen würde.
 *
 * Braucht eine bestehende Anmeldung (SSO, Login-Modul oder Demo/Family-
 * Session, siehe get_aktuelle_mnr()) - ohne MNr kein eigener Ausweis.
 */

require_once __DIR__ . '/functions_neu.php';
require_once __DIR__ . '/ausweis_functions.php';

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
if (($config['mitgliedschaftspruefung_aktiv'] ?? '0') !== '1') {
    header('Location: index.php');
    exit;
}

$jahr = get_aktuelles_jahr($pdo);
$mitglied = get_aktuelles_mitglied($pdo, $jahr);
$vorname = (string)($mitglied['Vorname'] ?? '');
$name = (string)($mitglied['Name'] ?? '');
$fotoUrl = ausweis_foto_url($config, $mnr);
$basisUrlFehlt = trim((string)($config['abstimmung_basis_url'] ?? '')) === '';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Mein Mitgliedsausweis — <?= h($config['vereinsname']) ?></title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f6f8; color: #222; min-height: 100vh; }
    .kopf { background: #005596; color: white; padding: 14px 18px; }
    .kopf h1 { font-size: 17px; margin: 0; }
    .karte { max-width: 360px; margin: 24px auto; background: white; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.12); overflow: hidden; }
    .karte-kopf { background: #005596; color: white; padding: 16px 20px; text-align: center; font-weight: 600; font-size: 15px; }
    .karte-inhalt { padding: 20px; display: flex; flex-direction: column; align-items: center; gap: 14px; }
    .foto { width: 110px; height: 110px; border-radius: 50%; object-fit: cover; border: 3px solid #005596; background: #e0e0e0; }
    .foto-platzhalter { width: 110px; height: 110px; border-radius: 50%; background: #e0e0e0; display: flex; align-items: center; justify-content: center; font-size: 42px; color: #999; }
    .ausweis-name { font-size: 19px; font-weight: 700; text-align: center; }
    .ausweis-mnr { font-size: 13px; color: #777; }
    .qr-box { padding: 10px; border: 1px solid #ddd; border-radius: 8px; }
    .qr-box img { display: block; width: 220px; height: 220px; }
    .hinweis { padding: 0 20px 20px; font-size: 13px; color: #555; line-height: 1.5; text-align: center; }
    .zurueck { display: block; text-align: center; margin: 18px; color: #005596; }
</style>
</head>
<body>
<div class="kopf"><h1><?= h($config['vereinsname']) ?> – Mein Mitgliedsausweis</h1></div>
<div class="karte">
    <div class="karte-kopf"><?= h($config['mv_bezeichnung']) ?> <?= h((string)$jahr) ?></div>
    <div class="karte-inhalt">
        <?php if ($fotoUrl !== ''): ?>
            <img class="foto" src="<?= h($fotoUrl) ?>" alt="Foto" onerror="this.outerHTML='<div class=&quot;foto-platzhalter&quot;>👤</div>';">
        <?php else: ?>
            <div class="foto-platzhalter">👤</div>
        <?php endif; ?>
        <div>
            <div class="ausweis-name"><?= h(trim($vorname . ' ' . $name)) ?></div>
            <div class="ausweis-mnr" style="text-align:center;">Mitglieds-Nr. <?= h($mnr) ?></div>
        </div>
        <?php if ($basisUrlFehlt): ?>
            <div class="qr-box" style="width:220px; height:220px; display:flex; align-items:center; justify-content:center; text-align:center; color:#999; font-size:13px; padding:16px;">QR-Code aktuell nicht verfügbar (Admin muss noch die Basis-URL einrichten)</div>
        <?php else: ?>
            <div class="qr-box">
                <img src="ausweis_qr.php" alt="QR-Code Mitgliedsausweis">
            </div>
        <?php endif; ?>
    </div>
    <div class="hinweis">Diesen QR-Code am Einlass der Versammlung zeigen (sofern eine Einlasskontrolle stattfindet) - er dient gleichzeitig als elektronische Visitenkarte und kann mit jeder Kamera-App gescannt werden.</div>
</div>
<a class="zurueck" href="index.php">← Zurück zur Übersicht</a>
</body>
</html>
