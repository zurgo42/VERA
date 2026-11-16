<?php
declare(strict_types=1);

/**
 * ausweis_pruefen.php - Ziel des Links, den der QR-Code auf dem
 * elektronischen Mitgliedsausweis kodiert (siehe ausweis_functions.php:
 * ausweis_pruefen_url()). Öffentlich erreichbar wie abstimmen.php, zeigt
 * aber je nachdem, WER den Link öffnet, etwas anderes - genau das Muster
 * der schon bestehenden Austritt-Funktion in abstimmen.php:
 *
 * - Normale Betrachter:innen (niemand mit Einlasskontrolle-Rolle
 *   angemeldet - z. B. ein anderes Mitglied, das die elektronische
 *   Visitenkarte möchte): sehen Name/Telefon/Mail des Ausweis-Inhabers als
 *   Text plus einen Download-Link für dieselben Daten als vCard
 *   (ausweis_vcf.php) - bewusst nur das, was auf der Seite selbst schon zu
 *   lesen war (Transparenz, siehe Dateikopf von ausweis_functions.php).
 * - Einlasskontrolle-Personal (gleiche Rolle wie die Austritt-Funktion,
 *   siehe ist_ein_ausgang_berechtigt()): sieht stattdessen die
 *   Mitgliedschaftsprüfung und kann Einlass (mit/ohne Stimmkarte) oder
 *   Abweisung bestätigen.
 */

require_once __DIR__ . '/functions_neu.php';
require_once __DIR__ . '/ausweis_functions.php';
require_once __DIR__ . '/mitglieder_functions.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php';
    session_start();
}

$pdo = get_pdo();
$config = get_config($pdo);
$token = (string)($_GET['t'] ?? '');
$ausweisMnr = $token !== '' ? ausweis_token_mnr($pdo, $token) : null;

header('Content-Type: text/html; charset=utf-8');

$kopfStil = <<<CSS
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f6f8; color: #222; min-height: 100vh; }
    .kopf { background: #005596; color: white; padding: 14px 18px; }
    .kopf h1 { font-size: 16px; margin: 0; }
    .karte { max-width: 420px; margin: 24px auto; background: white; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.12); padding: 22px; }
    .zeile { font-size: 16px; padding: 4px 0; }
    .zeile b { display: inline-block; min-width: 90px; }
    .hinweis { margin-top: 16px; padding: 12px; background: #eef4fa; border-radius: 8px; font-size: 13px; color: #444; line-height: 1.5; }
    .download-btn { display: block; text-align: center; margin-top: 18px; padding: 14px; background: #005596; color: white; border-radius: 8px; text-decoration: none; font-weight: 600; }
    .fehler-karte { max-width: 420px; margin: 60px auto; text-align: center; color: #7a1414; }
CSS;

if ($ausweisMnr === null) {
    http_response_code(410);
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Mitgliedsausweis prüfen</title>
    <style><?= $kopfStil ?></style>
    </head>
    <body>
    <div class="fehler-karte">
        <div style="font-size:40px;">⚠</div>
        <p>Dieser QR-Code ist ungültig oder abgelaufen.</p>
        <p style="font-size:13px; color:#999; margin-top:8px;">Bitte den Mitgliedsausweis erneut öffnen - er wird bei jedem Aufruf neu erzeugt.</p>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$jahr = get_aktuelles_jahr($pdo);
$staffMnr = get_aktuelle_mnr($pdo);
$staffMitglied = $staffMnr !== '' ? get_aktuelles_mitglied($pdo, $jahr) : null;
$istEinlassPersonal = ist_ein_ausgang_berechtigt($staffMitglied);

if (!$istEinlassPersonal) {
    // --- Normale Betrachter-Ansicht: elektronische Visitenkarte ---
    $kontakt = mitgliederquelle_abfragen($pdo, $ausweisMnr, $config);
    $telefon = svmembers_telefon_von_mnr($pdo, $ausweisMnr);
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Kontaktdaten — <?= h($config['vereinsname']) ?></title>
    <style><?= $kopfStil ?></style>
    </head>
    <body>
    <div class="kopf"><h1><?= h($config['vereinsname']) ?> – Elektronische Visitenkarte</h1></div>
    <div class="karte">
        <div class="zeile"><b>Name:</b> <?= h(trim($kontakt['vorname'] . ' ' . $kontakt['name'])) ?></div>
        <?php if ($telefon !== ''): ?><div class="zeile"><b>Telefon:</b> <?= h($telefon) ?></div><?php endif; ?>
        <?php if ($kontakt['mail'] !== ''): ?><div class="zeile"><b>E-Mail:</b> <?= h($kontakt['mail']) ?></div><?php endif; ?>
        <div class="hinweis">Das sind genau die Daten, die beim Scannen dieses QR-Codes übertragen werden - nicht mehr und nicht weniger. Sie können sie als Kontakt speichern:</div>
        <a class="download-btn" href="ausweis_vcf.php?t=<?= h(rawurlencode($token)) ?>">📇 Als Kontakt speichern</a>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// --- Einlasskontrolle-Ansicht ---
[$zielMitglied] = mitglied_finden_oder_anlegen($pdo, $jahr, $ausweisMnr, $staffMitglied);
$kontakt = mitgliederquelle_abfragen($pdo, $ausweisMnr, $config);

$aktionErgebnis = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aktion = (string)($_POST['aktion'] ?? '');
    $tabelle = 'mvmitglieder_' . $jahr;
    if ($aktion === 'mit_stimmkarte' || $aktion === 'ohne_stimmkarte') {
        $stmt = $pdo->prepare(
            "UPDATE `$tabelle` SET AnwesendSeit = NOW(), AnwesendArt = 'einlasskontrolle'"
            . ($aktion === 'mit_stimmkarte' ? ', StimmkarteAusgegeben = 1' : '')
            . ' WHERE MNr = ?'
        );
        $stmt->execute([$ausweisMnr]);
        mv_log(
            $pdo, $jahr, $staffMitglied,
            'Einlasskontrolle: Einlass bestätigt',
            'MNr ' . $ausweisMnr . ' - ' . ($aktion === 'mit_stimmkarte' ? 'mit Stimmkarte' : 'ohne Stimmkarte')
        );
        $aktionErgebnis = $aktion;
        [$zielMitglied] = mitglied_finden_oder_anlegen($pdo, $jahr, $ausweisMnr, $staffMitglied);
    } elseif ($aktion === 'abweisen') {
        mv_log($pdo, $jahr, $staffMitglied, 'Einlasskontrolle: Einlass verweigert', 'MNr ' . $ausweisMnr);
        $aktionErgebnis = 'abweisen';
    }
}

$statusWert = svmembers_status_von_mnr($pdo, $ausweisMnr);
$teilnahmeform = $zielMitglied['Teilnahmeform'] ?? null;
$bereitsAnwesend = !empty($zielMitglied['AnwesendSeit']);
$einschaetzung = einlass_einschaetzung($statusWert, $teilnahmeform);

$farben = [
    'gruen' => ['bg' => '#e8f5e9', 'fg' => '#2e7d32', 'text' => '✓ Stimmberechtigt'],
    'blau' => ['bg' => '#e3f2fd', 'fg' => '#1565c0', 'text' => 'ⓘ Teilnahmeberechtigt (ohne Stimmrecht)'],
    'rot' => ['bg' => '#fde2e2', 'fg' => '#c62828', 'text' => '✗ Nicht zugelassen'],
    'unbekannt' => ['bg' => '#fff3cd', 'fg' => '#856404', 'text' => '? Status unbekannt'],
];
$f = $farben[$einschaetzung['stufe']];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Einlasskontrolle — <?= h($config['vereinsname']) ?></title>
<style>
<?= $kopfStil ?>
    .empfehlung { background: <?= $f['bg'] ?>; color: <?= $f['fg'] ?>; padding: 14px; border-radius: 8px; font-weight: 700; font-size: 16px; text-align: center; margin-bottom: 10px; }
    .begruendung { font-size: 13px; color: #555; text-align: center; margin-bottom: 16px; }
    .schon-da { background: #fff3cd; color: #664d03; padding: 10px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; text-align: center; }
    .aktionen { display: flex; flex-direction: column; gap: 10px; margin-top: 10px; }
    .aktionen button { font-size: 15px; padding: 14px; border: none; border-radius: 8px; color: white; cursor: pointer; }
    .btn-mit { background: #2e7d32; }
    .btn-ohne { background: #1565c0; }
    .btn-ab { background: #c62828; }
    .erfolg { background: #2e7d32; color: white; padding: 20px; border-radius: 8px; text-align: center; font-size: 17px; }
</style>
</head>
<body>
<div class="kopf"><h1>🚪 Einlasskontrolle – <?= h($config['vereinsname']) ?></h1></div>
<div class="karte">
    <div class="zeile"><b>Name:</b> <?= h(trim($kontakt['vorname'] . ' ' . $kontakt['name'])) ?></div>
    <div class="zeile"><b>Mitgl.-Nr.:</b> <?= h($ausweisMnr) ?></div>

    <?php if ($aktionErgebnis !== ''): ?>
        <div class="erfolg" style="margin-top:16px;">
            <?php if ($aktionErgebnis === 'mit_stimmkarte'): ?>✓ Einlass bestätigt - Stimmkarte aushändigen.
            <?php elseif ($aktionErgebnis === 'ohne_stimmkarte'): ?>✓ Einlass bestätigt - keine Stimmkarte.
            <?php else: ?>✗ Einlass verweigert.<?php endif; ?>
        </div>
    <?php else: ?>
        <div style="margin-top:16px;">
            <?php if ($bereitsAnwesend): ?>
                <div class="schon-da">⚠ Dieses Mitglied wurde bereits um <?= h(date('H:i', strtotime((string)$zielMitglied['AnwesendSeit']))) ?> Uhr eingelassen (<?= h((string)($zielMitglied['AnwesendArt'] ?? '')) ?>).</div>
            <?php endif; ?>
            <div class="empfehlung"><?= h($f['text']) ?></div>
            <?php if ($einschaetzung['begruendung'] !== ''): ?><div class="begruendung"><?= h($einschaetzung['begruendung']) ?></div><?php endif; ?>
            <form method="post" class="aktionen">
                <?= csrf_field() ?>
                <button type="submit" name="aktion" value="mit_stimmkarte" class="btn-mit">✓ Einlass + Stimmkarte</button>
                <button type="submit" name="aktion" value="ohne_stimmkarte" class="btn-ohne">✓ Nur Einlass (keine Stimmkarte)</button>
                <button type="submit" name="aktion" value="abweisen" class="btn-ab">✗ Abweisen</button>
            </form>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
