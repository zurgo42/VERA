<?php
declare(strict_types=1);

/**
 * abstimmen.php - Öffentlicher Einstiegspunkt der Abstimmungsmaschine
 * (Ersatz für autarke Abstimmungsgeräte, siehe
 * dokumentation/Abstimmungsmaschine.md). Wird über den QR-Code auf der
 * individuellen Stimmkarte aufgerufen (?token=…) - KEINE Anmeldung im
 * MV-Tool nötig, die Stimmkarte selbst ist der Berechtigungsnachweis.
 *
 * Bewusst OHNE Auto-Refresh (anders als beamerbild.php): bei mehreren
 * hundert Teilnehmergeräten gleichzeitig wäre das unnötige Serverlast,
 * siehe Lastabschätzung in dokumentation/Abstimmungsmaschine.md. Wer das
 * aktuelle Bild/die laufende Abstimmung sehen will, ruft die Seite erneut
 * per QR-Code auf.
 *
 * Drei Zustände (serverseitig entschieden, nicht am Browserzustand - ein
 * Neuladen zeigt deshalb immer zuverlässig dasselbe):
 *  - keine offene Runde: exakt dasselbe Bild wie die Leinwand, inkl. eines
 *    ggf. noch sichtbaren Ergebnisses (beamer_vorschau_render(), dieselbe
 *    Funktion wie beamerbild.php - damit nach Abstimmungsende ein einfaches
 *    Neuladen wieder das Beamerbild samt Ergebnis zeigt).
 *  - offene Runde, noch nicht abgestimmt: Stimmzettel - bei einer einfachen
 *    Abstimmung Ja/Nein/Enthaltung, bei einer Wahl (Phase 6, siehe
 *    wahl_functions.php) je nach Modus der Kandidat:innenliste.
 *  - offene Runde, schon abgestimmt (oder gerade erfolgreich abgestimmt):
 *    grüne Bestätigung - kein erneutes Abstimmen mehr möglich.
 */

require_once __DIR__ . '/functions_neu.php';
require_once __DIR__ . '/protokoll_functions.php';

$pdo = get_pdo();
require_once __DIR__ . '/abstimmung_functions.php';
require_once __DIR__ . '/wahl_functions.php';
require_once __DIR__ . '/beamer_functions.php';

// Oeffentlich ohne Login - $jahr kommt hier (anders als sonst im MV-Tool)
// nicht aus einer Session, sondern ist bei VERA schlicht "das aktuelle
// MV-Jahr", vorab gebraucht, um die Stimmkarte/Runde korrekt zu scopen
// (siehe BEAM-Vereinheitlichung in abstimmung_functions.php).
$jahr = get_aktuelles_jahr($pdo);
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$gueltig = $token !== '' && vote_token_gueltig($pdo, $token, $jahr);

// Phase 5 (Online-Abstimmung, siehe online_abstimmen.php): ein fuer
// Online-Teilnehmende ausgegebener Token ist nur fuer GENAU die Runde
// gueltig, fuer die er ausgegeben wurde - anders als eine physische
// Stimmkarte (die bewusst den ganzen Tag ueber alle Runden gueltig
// bleibt). Betrifft nur Online-Token (siehe
// online_token_fuer_runde_gueltig()) - physische Stimmkarten-Token haben
// nie einen Bindungseintrag und sind davon unberuehrt.
if ($gueltig) {
    $round_fuer_bindung = vote_round_aktuell($pdo, $jahr);
    if ($round_fuer_bindung && !online_token_fuer_runde_gueltig($pdo, $token, (int)$round_fuer_bindung['round_id'])) {
        $gueltig = false;
    }
}

// Nur die letzten 6 Zeichen des Tokens werden unten als ".kennung" angezeigt
// - zum Unterscheiden mehrerer offener Tabs (z.B. auf dem Smartphone der
// Sitznachbarschaft, je ein Tab pro Person/Token). Kein neues Sicherheits-
// risiko: der volle Token steht ohnehin als Ausweichtext auf jeder
// gedruckten Stimmkarte (siehe vote_pdf_karte()).

// "Austritt"-Funktion (siehe vote_token_ungueltig_machen()): nur erkennbar,
// wenn der aufrufende Browser zusaetzlich zum Stimmkarten-Token auch eine
// VERA-Session mit Ein-/Ausgang-Rechten mitbringt (eigene Rolle, Red = 6,
// siehe ist_ein_ausgang_berechtigt() - bewusst getrennt von der
// Beamersteuerung, da am Einlass/Ausgang in der Praxis ein anderes Team
// steht; scannt mit dem eigenen, angemeldeten Smartphone denselben QR-Code
// wie die Stimmkarte selbst). Fuer gewoehnliche Teilnehmer:innen ohne eine
// solche Session bleibt die Seite unveraendert.
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php';
    session_start();
}
$staff_mnr = get_aktuelle_mnr($pdo);
$staff_mitglied = $staff_mnr !== '' ? get_aktuelles_mitglied($pdo, $jahr) : null;
$ist_staff_austritt = $staff_mitglied !== null && ist_ein_ausgang_berechtigt($staff_mitglied);

$austritt_erfolg = false;
if ($gueltig && $ist_staff_austritt && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'token_ungueltig_machen') {
    csrf_check();
    vote_token_ungueltig_machen($pdo, $token, $jahr);
    mv_log($pdo, $jahr, $staff_mitglied, 'Stimmkarte ungültig gemacht (Austritt)', 'Token-Hash ' . substr(hash('sha256', $token), 0, 12) . '…');
    $gueltig = false;
    $austritt_erfolg = true;
}

$round = $gueltig ? vote_round_aktuell($pdo, $jahr) : null;
$wahlgang = ($round && !empty($round['wahlgang_id'])) ? get_wahlgang($pdo, (int)$round['wahlgang_id']) : null;

$fehler = '';
if ($gueltig && $round && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedRound = (int)($_POST['round_id'] ?? 0);
    if ($postedRound !== (int)$round['round_id']) {
        $fehler = 'Diese Abstimmung ist nicht mehr aktiv - bitte die Seite über die Stimmkarte neu aufrufen.';
    } elseif ($wahlgang) {
        switch ($wahlgang['modus']) {
            case 'A':
                $eingabe = [];
                foreach ($_POST as $key => $val) {
                    if (str_starts_with((string)$key, 'kandidat_')) {
                        $eingabe[(int)substr((string)$key, 9)] = (string)$val;
                    }
                }
                break;
            case 'C':
            case 'P':
                $eingabe = array_map('intval', (array)($_POST['kandidaten'] ?? []));
                break;
            default: // B
                $eingabe = (int)($_POST['kandidat'] ?? 0);
                break;
        }
        [, $fehler] = wahl_abstimmen($pdo, $round, $token, $eingabe, (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)));
    } else {
        $votum = (string)($_POST['votum'] ?? '');
        [, $fehler] = vote_abstimmen($pdo, $postedRound, $token, $votum, (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)));
    }
}

$schonAbgestimmt = $gueltig && $round
    && ($wahlgang ? wahl_hat_schon_abgestimmt($pdo, (int)$round['round_id'], $token) : vote_hat_schon_abgestimmt($pdo, (int)$round['round_id'], $token));

$config = get_config($pdo);
// Ausserhalb einer laufenden Abstimmung zeigt diese Seite exakt dasselbe
// Bild wie die Leinwand (inkl. eines ggf. noch sichtbaren Ergebnisses) -
// dieselbe Funktion wie beamerbild.php, siehe beamer_vorschau_daten()/
// beamer_vorschau_render() in beamer_functions.php, damit beide niemals
// voneinander abweichen.
$vorschau_daten = (!$round && $gueltig) ? beamer_vorschau_daten($pdo, $jahr) : null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Abstimmung — <?= h($config['mv_bezeichnung']) ?></title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: white; color: #222; min-height: 100vh; }
    .kopf { background: #005596; color: white; padding: 14px 18px; }
    .kopf h1 { font-size: 17px; margin: 0; }
    .inhalt { padding: 18px; }
    .hinweis { padding: 18px; color: #555; }
    .fehler { padding: 12px 18px; background: #fde2e2; color: #7a1414; }
    .frage { padding: 18px; font-size: 16px; }
    .stimmzettel { display: flex; flex-direction: column; gap: 14px; padding: 18px; }
    .stimmzettel button { font-size: 22px; padding: 22px; border: none; border-radius: 8px; color: white; }
    .btn-ja { background: #2e7d32; }
    .btn-nein { background: #c62828; }
    .btn-enthaltung { background: #757575; }
    .gruen { background: #2e7d32; color: white; min-height: 90vh; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 24px; font-size: 24px; }
    .kennung { font-size: 13px; opacity: 0.85; margin-top: 10px; letter-spacing: 0.5px; }
    .kopf .kennung { padding: 2px 0 0; margin-top: 0; color: #cfe3f7; }
    .austritt-leiste { background: #fff3cd; color: #664d03; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; font-size: 13px; border-bottom: 2px solid #ffecb5; }
    .austritt-leiste form { margin: 0; }
    .austritt-btn-klein { font-size: 13px; padding: 9px 14px; border: none; border-radius: 6px; background: #c62828; color: white; cursor: pointer; white-space: nowrap; }
    .austritt-erfolg { background: #2e7d32; color: white; min-height: 90vh; display: flex; align-items: center; justify-content: center; text-align: center; padding: 24px; font-size: 22px; }
    .wahl-hinweis { padding: 0 18px 10px; color: #555; font-size: 13.5px; }
    .wahl-liste { padding: 0 18px 18px; display: flex; flex-direction: column; gap: 10px; }
    .wahl-b button { width: 100%; font-size: 18px; padding: 18px; border: none; border-radius: 8px; background: #005596; color: white; text-align: left; }
    .wahl-a-zeile { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 0; border-bottom: 1px solid #eee; }
    .wahl-a-zeile .name { font-size: 15px; flex: 1 1 auto; }
    .wahl-a-zeile .radios { display: flex; gap: 12px; font-size: 12.5px; white-space: nowrap; }
    .wahl-a-zeile .radios label { display: flex; align-items: center; gap: 3px; }
    .wahl-c-zeile { display: flex; align-items: center; gap: 10px; font-size: 16px; padding: 12px 0; border-bottom: 1px solid #eee; }
    .wahl-c-zeile input { width: 20px; height: 20px; }
    .wahl-c-zaehler { font-size: 13px; color: #555; padding: 0 18px 8px; }
    .wahl-absenden { width: 100%; font-size: 20px; padding: 18px; border: none; border-radius: 8px; background: #2e7d32; color: white; }
<?= beamer_vorschau_css() ?>
</style>
</head>
<body>
<?php if ($austritt_erfolg): ?>
    <div class="kopf"><h1>Austritt - Stimmkarte prüfen</h1></div>
    <div class="austritt-erfolg">✓ Stimmkarte wurde soeben ungültig gemacht.</div>
<?php else: ?>
    <?php if ($ist_staff_austritt && $gueltig): ?>
        <!-- "Austritt"-Funktion, siehe vote_token_ungueltig_machen(): nur
             sichtbar, wenn die scannende Person selbst mit Ein-/Ausgang-
             Rechten (Red = 6) bei VERA angemeldet ist. Bewusst ZUSAETZLICH
             zur normalen Seite (nicht statt ihr) - sonst koennten
             Rechteinhaber:innen nicht mehr mit ihrer eigenen Stimmkarte
             abstimmen, siehe Ruecksprache: die allermeisten scannen hier
             ihre eigene Karte, nur am Ausgang gelegentlich eine fremde. Der
             Sicherheitshinweis im confirm() unten erinnert daran, die
             Funktion nicht versehentlich auf der eigenen Karte zu nutzen. -->
        <div class="austritt-leiste">
            <span>🚪 Ein-/Ausgang-Team: diese Stimmkarte am Ausgang entwerten?</span>
            <form method="post" onsubmit="return confirm('Diese Stimmkarte jetzt endgültig ungültig machen?\n\nNur verwenden, wenn dies NICHT deine eigene Stimmkarte ist - das lässt sich nicht rückgängig machen.');">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <input type="hidden" name="aktion" value="token_ungueltig_machen">
                <button type="submit" class="austritt-btn-klein">Jetzt ungültig machen (Austritt)</button>
            </form>
        </div>
    <?php endif; ?>
    <?php if ($gueltig && $round && $schonAbgestimmt): ?>
    <div class="gruen">✓ Stimme gezählt.<br>Danke für die Teilnahme.<div class="kennung">Stimmkarte …<?= h(substr($token, -6)) ?></div></div>
    <?php else: ?>
    <div class="kopf"><h1><?= h($config['vereinsname']) ?> – <?= h($config['mv_bezeichnung']) ?> <?= h((string)$jahr) ?></h1>
        <?php if ($gueltig): ?><div class="kennung">Stimmkarte …<?= h(substr($token, -6)) ?></div><?php endif; ?>
    </div>
    <?php if (!$gueltig): ?>
        <div class="hinweis">Diese Stimmkarte ist nicht gültig. Bitte am Einlass nachfragen.</div>
    <?php elseif ($round && $wahlgang): ?>
        <?php if ($fehler !== ''): ?><div class="fehler"><?= h($fehler) ?></div><?php endif; ?>
        <?php
        // Die Bezeichnung/Frage genuegt hier - die Kandidat:innen/Varianten
        // werden gleich ohnehin ueber die Buttons/Checkboxen angezeigt;
        // $round['frage_text'] (die Beamer-Momentaufnahme inkl. Liste) wuerde
        // das doppeln.
        ?>
        <div class="frage"><?= h($wahlgang['bezeichnung']) ?></div>
        <?php if ($wahlgang['modus'] === 'B'):
            $ist_abstimmung_art = ($wahlgang['art'] ?? 'wahl') === 'abstimmung';
        ?>
            <?php if (!$ist_abstimmung_art): ?>
                <div class="wahl-hinweis">Bitte die zu wählende Person antippen.</div>
            <?php endif; ?>
            <div class="wahl-liste">
                <?php foreach ($wahlgang['kandidaten'] as $k): ?>
                    <form method="post" class="wahl-b">
                        <input type="hidden" name="token" value="<?= h($token) ?>">
                        <input type="hidden" name="round_id" value="<?= (int)$round['round_id'] ?>">
                        <input type="hidden" name="kandidat" value="<?= (int)$k['id'] ?>">
                        <button type="submit"><?= h($k['name']) ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php elseif ($wahlgang['modus'] === 'P'): ?>
            <div class="wahl-hinweis">Bitte alle zutreffenden Varianten auswählen.</div>
            <form method="post">
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <input type="hidden" name="round_id" value="<?= (int)$round['round_id'] ?>">
                <div class="wahl-liste">
                    <?php foreach ($wahlgang['kandidaten'] as $k): ?>
                        <label class="wahl-c-zeile">
                            <input type="checkbox" name="kandidaten[]" value="<?= (int)$k['id'] ?>">
                            <?= h($k['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div style="padding:0 18px 18px;"><button type="submit" class="wahl-absenden">Stimmen abgeben</button></div>
            </form>
        <?php elseif ($wahlgang['modus'] === 'C'):
            $aemter = (int)($wahlgang['anzahl_aemter'] ?? count($wahlgang['kandidaten']));
        ?>
            <div class="wahl-hinweis">Bitte bis zu <strong><?= $aemter ?></strong> Kandidat:in(nen) auswählen.</div>
            <form method="post">
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <input type="hidden" name="round_id" value="<?= (int)$round['round_id'] ?>">
                <div class="wahl-liste">
                    <?php foreach ($wahlgang['kandidaten'] as $k): ?>
                        <label class="wahl-c-zeile">
                            <input type="checkbox" name="kandidaten[]" value="<?= (int)$k['id'] ?>" class="wahl-c-box">
                            <?= h($k['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="wahl-c-zaehler"><span id="wahl-c-anzahl">0</span> von <?= $aemter ?> ausgewählt</div>
                <div style="padding:0 18px 18px;"><button type="submit" class="wahl-absenden">Stimmen abgeben</button></div>
            </form>
            <script>
            (function () {
                var max = <?= $aemter ?>;
                var boxen = document.querySelectorAll('.wahl-c-box');
                var anzeige = document.getElementById('wahl-c-anzahl');
                function aktualisieren() {
                    var gewaehlt = 0;
                    boxen.forEach(function (b) { if (b.checked) gewaehlt++; });
                    anzeige.textContent = gewaehlt;
                    boxen.forEach(function (b) { b.disabled = !b.checked && gewaehlt >= max; });
                }
                boxen.forEach(function (b) { b.addEventListener('change', aktualisieren); });
            })();
            </script>
        <?php else: // Modus A ?>
            <div class="wahl-hinweis">Bitte zu jeder Kandidatin/jedem Kandidaten Ja, Nein oder Enthaltung wählen.</div>
            <form method="post">
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <input type="hidden" name="round_id" value="<?= (int)$round['round_id'] ?>">
                <div class="wahl-liste">
                    <?php foreach ($wahlgang['kandidaten'] as $k): ?>
                        <div class="wahl-a-zeile">
                            <div class="name"><?= h($k['name']) ?></div>
                            <div class="radios">
                                <label><input type="radio" name="kandidat_<?= (int)$k['id'] ?>" value="ja" required> Ja</label>
                                <label><input type="radio" name="kandidat_<?= (int)$k['id'] ?>" value="nein"> Nein</label>
                                <label><input type="radio" name="kandidat_<?= (int)$k['id'] ?>" value="enthaltung"> Enth.</label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="padding:0 18px 18px;"><button type="submit" class="wahl-absenden">Stimmen abgeben</button></div>
            </form>
        <?php endif; ?>
        <div class="hinweis">Falls die Bestätigung nicht sofort kommt: bitte einfach noch einmal auf „Stimmen abgeben“ bzw. die gewünschte Kandidatin/den gewünschten Kandidaten tippen.</div>
    <?php elseif ($round): ?>
        <?php if ($fehler !== ''): ?><div class="fehler"><?= h($fehler) ?></div><?php endif; ?>
        <div class="frage"><?= $round['frage_text'] ?></div>
        <form method="post" class="stimmzettel">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <input type="hidden" name="round_id" value="<?= (int)$round['round_id'] ?>">
            <button type="submit" name="votum" value="ja" class="btn-ja">Ja</button>
            <button type="submit" name="votum" value="nein" class="btn-nein">Nein</button>
            <button type="submit" name="votum" value="enthaltung" class="btn-enthaltung">Enthaltung</button>
        </form>
        <div class="hinweis">Falls die Bestätigung nicht sofort kommt: bitte einfach noch einmal auf die gewünschte Stimme tippen.</div>
    <?php else: ?>
        <?= beamer_vorschau_render($vorschau_daten, $jahr, false, false) ?>
        <div class="hinweis">Diese Seite zeigt außerhalb einer Abstimmung dasselbe Bild wie die Leinwand. Ist eine Abstimmung vorbei, reicht meist ein erneutes Laden dieser Seite, damit es wieder erscheint. Falls nicht: die Seite über den QR-Code auf der Stimmkarte neu aufrufen.</div>
    <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>
