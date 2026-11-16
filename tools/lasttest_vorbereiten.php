<?php
declare(strict_types=1);

/**
 * lasttest_vorbereiten.php - Legt eine EINMALIGE Test-Abstimmungsrunde samt
 * einer frei wählbaren Anzahl echter Stimmkarten-Token an und gibt die
 * Token im Klartext als Liste aus (eine pro Zeile) - fürs normale
 * Produktivgeschäft (QR-Code-PDFs) macht das niemand, dort bleibt
 * ausdrücklich nur der Hash gespeichert (siehe vote_tokens_erzeugen()).
 * Für einen Lasttest braucht man die Klartext-Token aber maschinenlesbar,
 * um sie an ein externes Lastskript zu übergeben (siehe deploy/lasttest.py).
 *
 * NUR FÜR TESTZWECKE - niemals während einer echten Versammlung mit echten
 * Mitgliedern aufrufen: legt eine echte, offene Abstimmungsrunde an (blockiert
 * so lange jede andere Abstimmung) und erzeugt echte Zeilen in den
 * Produktivtabellen. Admin-Passwort-geschützt wie wartung_umschalten.php.
 * Nach dem Test unbedingt "Testrunde aufräumen" weiter unten nutzen (oder
 * vorher ein Backup ziehen, siehe tab_backup.php, und danach zurückspielen).
 */

require_once __DIR__ . '/../functions_neu.php';
require_once __DIR__ . '/../protokoll_functions.php';
require_once __DIR__ . '/../abstimmung_functions.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../session_config.php';
    session_start();
}

$pdo = get_pdo();
$jahr = get_aktuelles_jahr($pdo);

$fehler = '';
$erfolg = '';
$tokenListe = null;
$roundId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $authentifiziert = !empty($_SESSION['system_admin_authenticated']) || system_admin_passwort_pruefen((string)($_POST['passwort'] ?? ''));
    if (!$authentifiziert) {
        $fehler = 'Falsches Passwort.';
    } elseif (($_POST['aktion'] ?? '') === 'anlegen') {
        $anzahl = max(1, min(5000, (int)($_POST['anzahl'] ?? 0)));
        if ($bestehende = vote_round_aktuell($pdo, $jahr)) {
            $fehler = 'Es läuft bereits eine Abstimmungsrunde (ID ' . $bestehende['round_id'] . ') - bitte zuerst beenden/aufräumen.';
        } else {
            [$roundId, $fehler] = vote_round_starten($pdo, $jahr, 'LASTTEST - BITTE IGNORIEREN (' . date('Y-m-d H:i:s') . ')');
            if ($roundId !== null) {
                $tokenListe = vote_tokens_erzeugen($pdo, $jahr, $anzahl);
                $erfolg = $anzahl . ' Test-Stimmkarten erzeugt, Runde ' . $roundId . ' ist offen.';
            }
        }
    } elseif (($_POST['aktion'] ?? '') === 'aufraeumen') {
        $round = vote_round_aktuell($pdo, $jahr) ?? vote_round_wartet_auf_auswertung($pdo, $jahr);
        if ($round) {
            if ($round['status'] === 'offen') {
                vote_round_beenden($pdo, (int)$round['round_id']);
            }
            $pdo->prepare('DELETE FROM mv_vote_ballots WHERE round_id = ?')->execute([$round['round_id']]);
            $pdo->prepare('DELETE FROM mv_vote_rounds WHERE round_id = ?')->execute([$round['round_id']]);
        }
        $tokenFehler = vote_tokens_loeschen($pdo, $jahr);
        $erfolg = 'Aufgeräumt: Testrunde entfernt, Stimmkarten-Pool geleert.' . ($tokenFehler !== '' ? ' Hinweis: ' . $tokenFehler : '');
    }
}

$laufendeRunde = vote_round_aktuell($pdo, $jahr) ?? vote_round_wartet_auf_auswertung($pdo, $jahr);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Lasttest vorbereiten</title>
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; max-width: 640px; margin: 40px auto; padding: 0 20px; color: #333; line-height: 1.5; }
    .warnung { background: #fff3cd; color: #664d03; padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #ffc107; }
    .meldung { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
    .meldung.fehler { background: #f8d7da; color: #721c24; }
    .meldung.erfolg { background: #d4edda; color: #155724; }
    .status { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; background: #e7f1fb; }
    input[type=password], input[type=number] { padding: 8px; font-size: 15px; }
    button { padding: 10px 18px; font-size: 15px; cursor: pointer; border: none; border-radius: 6px; color: white; margin-top: 8px; }
    .btn-an { background: #005596; }
    .btn-aus { background: #6c757d; }
    textarea { width: 100%; height: 300px; font-family: monospace; font-size: 13px; margin-top: 10px; }
    label { display: block; margin-top: 10px; font-weight: bold; }
</style>
</head>
<body>
<h2>Lasttest vorbereiten</h2>
<div class="warnung">
    ⚠️ Nur für Testzwecke. Legt echte Zeilen in den Produktivtabellen an und blockiert währenddessen jede
    echte Abstimmung. Niemals während einer laufenden Versammlung mit echten Mitgliedern verwenden.
    Vorher am besten ein Backup ziehen (<a href="../tab_admin.php?bereich=backup">Datensicherung</a>),
    danach "Testrunde aufräumen" nutzen.
</div>

<?php if ($fehler): ?><div class="meldung fehler"><?= h($fehler) ?></div><?php endif; ?>
<?php if ($erfolg): ?><div class="meldung erfolg"><?= h($erfolg) ?></div><?php endif; ?>

<?php if ($laufendeRunde): ?>
    <div class="status">Aktuell offene/noch nicht aufgeräumte Testrunde: ID <?= h((string)$laufendeRunde['round_id']) ?>
        (<?= h((string)$laufendeRunde['status']) ?>)</div>
<?php endif; ?>

<form method="post">
    <?php if (empty($_SESSION['system_admin_authenticated'])): ?>
        <label>Admin-Passwort</label>
        <input type="password" name="passwort">
    <?php endif; ?>
    <label>Anzahl Test-Stimmkarten</label>
    <input type="number" name="anzahl" value="500" min="1" max="5000">
    <div>
        <button type="submit" name="aktion" value="anlegen" class="btn-an">Testrunde + Token anlegen</button>
        <button type="submit" name="aktion" value="aufraeumen" class="btn-aus">Testrunde aufräumen</button>
    </div>
</form>

<?php if ($tokenListe): ?>
    <h3>Für das Lastskript (deploy/lasttest.py)</h3>
    <p>Basis-URL: <code><?= h((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '')) ?>/abstimmen.php</code>,
       Runde: <code><?= h((string)$roundId) ?></code></p>
    <textarea readonly onclick="this.select()"><?= h(implode("\n", $tokenListe)) ?></textarea>
<?php endif; ?>

</body>
</html>
