<?php
declare(strict_types=1);

/**
 * uebung.php - Öffentliche Übungsseite, auf der Mitglieder VOR der echten
 * Versammlung den Ablauf einer Abstimmung (Antrag/Diskussion → Abstimmung
 * → Ergebnis) mit ihrem eigenen Smartphone ausprobieren können - unabhängig
 * davon, ob gerade eine echte Versammlung läuft. KEINE Anmeldung nötig,
 * genau wie abstimmen.php.
 *
 * Nutzt dieselbe Abstimmungsmaschine-Engine (vote_round_starten()/
 * vote_tokens_erzeugen()/vote_abstimmen()) wie eine echte Abstimmung - die
 * Stimmabgabe fühlt sich also wirklich genauso an (gleicher Ablauf, gleiche
 * Serverantwortzeit) -, aber in einem eigenen, negativen "Jahr"-Kontext
 * (siehe $uebungsKontext unten), der niemals mit einem echten VERA-Jahr
 * (immer positiv) oder einem echten BEAM-Projekt (siehe
 * BEAM_PROJEKT_ID_START, immer positiv) kollidieren kann. Jede Person
 * bekommt beim ersten Aufruf ihren eigenen, zufälligen Kontext in die
 * eigene Sitzung gelegt - mehrere Leute können die Seite also gleichzeitig
 * unabhängig voneinander nutzen, ohne sich gegenseitig zu stören, und ohne
 * die echte Abstimmungsrunde zu beeinflussen.
 *
 * Das am Ende gezeigte Ergebnis ist bewusst FEST VORGEGEBEN (nicht die
 * echte 1-Personen-"Auszählung" der eigenen Testabstimmung) - siehe
 * uebung_beispielergebnis(), deutlich als Beispiel gekennzeichnet.
 *
 * Nach der Stimmabgabe wird die Übungsrunde sofort wieder aufgeräumt
 * (Runde/Token/Stimme gelöscht) - es bleiben keine Daten liegen.
 */

require_once __DIR__ . '/functions_neu.php';
require_once __DIR__ . '/abstimmung_functions.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php';
    session_start();
}

$pdo = get_pdo();
$config = get_config($pdo);

const UEBUNG_ANTRAG_TITEL = 'TOP 6: Beispielantrag „Erhöhung des Mitgliedsbeitrags um 5 €“';
// Kürzere Fassung für Stimmzettel/Ergebnis - der vollständige Antragstext
// steht dort ohnehin direkt darunter, der Hinweis "Beispiel"/Betrag wäre
// doppelt.
const UEBUNG_ANTRAG_TITEL_KURZ = 'TOP 6: „Erhöhung des Mitgliedsbeitrags“';
// Noch kürzere Fassung fürs Startbild (Antrag/Diskussion) - ohne TOP-Nummer.
const UEBUNG_ANTRAG_TITEL_START = 'Beispielantrag „Erhöhung des Mitgliedsbeitrags um 5 €“';
// Kurze, erklärte Pausen rund um die Stimmabgabe (siehe schritt=warten/
// warten2 unten) - bilden nach, dass sowohl die Bestätigung als auch die
// Ergebnisermittlung bei vielen hundert gleichzeitigen Abstimmenden spürbar
// länger brauchen als bei dieser einen Testabstimmung.
const UEBUNG_WARTE_SEKUNDEN = 3;
const UEBUNG_ANTRAG_TEXT = 'Ab dem folgenden Jahr wird der Beitrag um 5 Euro von 37 Euro auf 42 Euro '
    . 'angehoben; die Beiträge für Kinder und Jugendliche bleiben gleich.';

/** Fest vorgegebenes Beispielergebnis - keine echte Auszählung (siehe Dateikopf). Passend zum gewählten Votum erzählt, nicht zufällig. */
function uebung_beispielergebnis(string $votum): array {
    return match ($votum) {
        'nein' => ['ja' => 38, 'nein' => 61, 'enthaltung' => 7, 'text' => 'ABGELEHNT'],
        'enthaltung' => ['ja' => 49, 'nein' => 46, 'enthaltung' => 11, 'text' => 'KNAPP ANGENOMMEN'],
        default => ['ja' => 71, 'nein' => 20, 'enthaltung' => 9, 'text' => 'ANGENOMMEN'],
    };
}

$schritt = (string)($_GET['schritt'] ?? '1');

// Neustart: eigene Übungsrunde (falls noch vorhanden) aufräumen und von
// vorne beginnen - z. B. um eine andere Stimme auszuprobieren.
if (isset($_GET['neu'])) {
    if (!empty($_SESSION['uebung_round_id'])) {
        vote_round_beenden($pdo, (int)$_SESSION['uebung_round_id']);
        $pdo->prepare('DELETE FROM mv_vote_ballots WHERE round_id = ?')->execute([(int)$_SESSION['uebung_round_id']]);
        $pdo->prepare('DELETE FROM mv_vote_rounds WHERE round_id = ?')->execute([(int)$_SESSION['uebung_round_id']]);
    }
    if (!empty($_SESSION['uebung_kontext'])) {
        vote_tokens_loeschen($pdo, (int)$_SESSION['uebung_kontext']);
    }
    unset($_SESSION['uebung_kontext'], $_SESSION['uebung_round_id'], $_SESSION['uebung_token'], $_SESSION['uebung_votum']);
    header('Location: uebung.php');
    exit;
}

$fehler = '';

if ($schritt === '2') {
    // Eigenen, nie mit einem echten Jahr/BEAM-Projekt kollidierenden
    // Kontext anlegen - nur beim ersten Aufruf, ein Neuladen dieses
    // Schritts (z. B. nach Verbindungsabbruch) nutzt denselben Kontext
    // weiter statt eine zweite Runde zu eröffnen.
    if (empty($_SESSION['uebung_kontext'])) {
        $_SESSION['uebung_kontext'] = -random_int(1, 2000000000);
    }
    $kontext = (int)$_SESSION['uebung_kontext'];

    if (empty($_SESSION['uebung_round_id'])) {
        [$roundId, $fehler] = vote_round_starten($pdo, $kontext, UEBUNG_ANTRAG_TITEL);
        if ($roundId !== null) {
            $tokens = vote_tokens_erzeugen($pdo, $kontext, 1);
            $_SESSION['uebung_round_id'] = $roundId;
            $_SESSION['uebung_token'] = $tokens[0];
        }
    }

    // Schon abgestimmt (z. B. Reload nach dem Klick) - direkt zum Ergebnis.
    if (!empty($_SESSION['uebung_token']) && !empty($_SESSION['uebung_round_id'])
        && vote_hat_schon_abgestimmt($pdo, (int)$_SESSION['uebung_round_id'], (string)$_SESSION['uebung_token'])
        && !empty($_SESSION['uebung_votum'])) {
        header('Location: uebung.php?schritt=gruen');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['uebung_token']) && !empty($_SESSION['uebung_round_id'])) {
        csrf_check();
        $votum = (string)($_POST['votum'] ?? '');
        [, $fehler] = vote_abstimmen($pdo, (int)$_SESSION['uebung_round_id'], (string)$_SESSION['uebung_token'], $votum, microtime(true));
        if ($fehler === '') {
            $_SESSION['uebung_votum'] = $votum;
            // Nicht direkt zur Bestätigung - bei der echten Versammlung
            // stimmen oft mehrere hundert Personen in kurzer Zeit ab, die
            // Bestätigung braucht dort serverseitig spürbar länger als bei
            // dieser einen Testabstimmung gerade eben (siehe Lasttest-
            // Ergebnisse in der Risikoabschätzung: je nach Gleichzeitigkeit
            // wenige hundert Millisekunden bis einige Sekunden). Ablauf:
            // Pause -> grüne Bestätigung -> zweite Pause (Ergebnisermittlung)
            // -> Ergebnis, statt den unrealistischen Eindruck "alles sofort"
            // zu erwecken.
            header('Location: uebung.php?schritt=warten');
            exit;
        }
    }
}

if (in_array($schritt, ['warten', 'gruen', 'warten2'], true) && empty($_SESSION['uebung_votum'])) {
    header('Location: uebung.php');
    exit;
}

if ($schritt === '3') {
    if (empty($_SESSION['uebung_votum'])) {
        header('Location: uebung.php');
        exit;
    }
    // Aufräumen - das Beispielergebnis unten braucht die echten Zeilen
    // nicht mehr, es ist ohnehin fest vorgegeben (siehe Dateikopf).
    if (!empty($_SESSION['uebung_round_id'])) {
        vote_round_beenden($pdo, (int)$_SESSION['uebung_round_id']);
        $pdo->prepare('DELETE FROM mv_vote_ballots WHERE round_id = ?')->execute([(int)$_SESSION['uebung_round_id']]);
        $pdo->prepare('DELETE FROM mv_vote_rounds WHERE round_id = ?')->execute([(int)$_SESSION['uebung_round_id']]);
    }
    if (!empty($_SESSION['uebung_kontext'])) {
        vote_tokens_loeschen($pdo, (int)$_SESSION['uebung_kontext']);
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<?php
$naechsteSeite = ['warten' => 'gruen', 'gruen' => 'warten2', 'warten2' => '3'][$schritt] ?? null;
if ($naechsteSeite !== null):
?>
<meta http-equiv="refresh" content="<?= UEBUNG_WARTE_SEKUNDEN ?>;url=uebung.php?schritt=<?= $naechsteSeite ?>">
<?php endif; ?>
<title>Übung: Abstimmung ausprobieren — <?= h($config['vereinsname']) ?></title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: white; color: #222; min-height: 100vh; }
    .kopf { background: #005596; color: white; padding: 14px 18px; }
    .kopf h1 { font-size: 17px; margin: 0; }
    .uebung-banner { background: #fff3cd; color: #664d03; padding: 10px 18px; font-size: 13px; border-bottom: 2px solid #ffecb5; }
    .inhalt { padding: 18px; }
    .frage { padding: 18px; font-size: 16px; font-weight: bold; }
    .frage-gross { font-size: 20px; }
    .antragstext { padding: 0 18px 18px; color: #444; font-size: 14.5px; line-height: 1.5; }
    .antragstext-echt { padding: 0 18px 18px; color: #222; font-size: 18px; font-weight: 700; line-height: 1.5; }
    .diskussion { padding: 0 18px 18px; color: #555; font-style: italic; }
    .weiter-btn { display: block; width: calc(100% - 36px); margin: 0 18px; font-size: 18px; padding: 16px; border: none; border-radius: 8px; background: #005596; color: white; cursor: pointer; text-align: center; text-decoration: none; }
    .stimmzettel { display: flex; flex-direction: column; gap: 14px; padding: 18px; }
    .stimmzettel button { font-size: 22px; padding: 22px; border: none; border-radius: 8px; color: white; width: 100%; cursor: pointer; }
    .btn-ja { background: #2e7d32; }
    .btn-nein { background: #c62828; }
    .btn-enthaltung { background: #757575; }
    .gruen { background: #2e7d32; color: white; min-height: 40vh; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 24px; font-size: 24px; }
    .fehler { padding: 12px 18px; background: #fde2e2; color: #7a1414; margin: 18px; border-radius: 6px; }
    .ergebnis { padding: 24px 18px; }
    .ergebnis .titel { font-size: 22px; font-weight: 700; color: mediumblue; margin-bottom: 14px; }
    .ergebnis .zeile { font-size: 19px; line-height: 1.8; }
    .beispiel-hinweis { padding: 10px 18px; color: #856404; background: #fff3cd; font-size: 13px; margin: 0 18px 18px; border-radius: 6px; }
    .nochmal { display: block; text-align: center; margin: 18px; color: #005596; }
    .warten { min-height: 50vh; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 24px; gap: 18px; }
    .warten .spinner { width: 46px; height: 46px; border: 5px solid #e0e0e0; border-top-color: #005596; border-radius: 50%; animation: drehen 0.9s linear infinite; }
    .warten .erklaerung { max-width: 340px; color: #555; font-size: 14px; line-height: 1.5; }
    @keyframes drehen { to { transform: rotate(360deg); } }
</style>
</head>
<body>
<div class="kopf"><h1><?= h($config['vereinsname']) ?> – Übung: Abstimmung ausprobieren</h1></div>
<div class="uebung-banner">🎓 Dies ist eine Übung - es wird nichts Echtes gezählt. So fühlt sich eine Abstimmung während der echten Versammlung an.</div>

<?php if ($schritt === 'warten'): ?>
    <div class="warten">
        <div class="spinner"></div>
        <div><strong>Deine Stimme wird verarbeitet …</strong></div>
        <div class="erklaerung">Bei der echten Versammlung stimmen oft mehrere hundert Personen fast gleichzeitig ab - die Bestätigung braucht dann einen kurzen Moment länger als hier gerade bei dir allein. Das haben wir vorher mit einem echten Lasttest überprüft, damit es nicht zu lange dauert.</div>
    </div>
<?php elseif ($schritt === 'gruen'): ?>
    <div class="gruen">✓ Stimme gezählt.<br>Danke für die Teilnahme.</div>
<?php elseif ($schritt === 'warten2'): ?>
    <div class="warten">
        <div class="spinner"></div>
        <div><strong>Das Ergebnis wird ermittelt …</strong></div>
        <div class="erklaerung">Erst wenn alle Teilnehmenden abgestimmt haben und die Abstimmung beendet wurde, steht das Gesamtergebnis fest - auch das dauert bei der echten Versammlung einen kurzen Moment.</div>
    </div>
<?php elseif ($schritt === '3'): ?>
    <?php $ergebnis = uebung_beispielergebnis((string)$_SESSION['uebung_votum']); ?>
    <div class="frage frage-gross"><?= h(UEBUNG_ANTRAG_TITEL_KURZ) ?></div>
    <div class="antragstext-echt"><?= h(UEBUNG_ANTRAG_TEXT) ?></div>
    <div class="ergebnis">
        <div class="titel"><?= h($ergebnis['text']) ?></div>
        <div class="zeile">Ja: <?= $ergebnis['ja'] ?>&nbsp;%</div>
        <div class="zeile">Nein: <?= $ergebnis['nein'] ?>&nbsp;%</div>
        <div class="zeile">Enthaltung: <?= $ergebnis['enthaltung'] ?>&nbsp;%</div>
    </div>
    <div class="beispiel-hinweis">Hinweis: Dieses Ergebnis ist frei erfunden, zur Veranschaulichung - bei einer echten Abstimmung zeigt das Ergebnis die tatsächlich abgegebenen Stimmen aller Teilnehmenden.</div>
    <a class="nochmal" href="uebung.php?neu=1">↻ Noch einmal von vorne üben</a>
<?php elseif ($schritt === '2' && !empty($_SESSION['uebung_token']) && empty($fehler)): ?>
    <?php if ($fehler !== ''): ?><div class="fehler"><?= h($fehler) ?></div><?php endif; ?>
    <div class="frage frage-gross"><?= h(UEBUNG_ANTRAG_TITEL_KURZ) ?></div>
    <div class="antragstext-echt"><?= h(UEBUNG_ANTRAG_TEXT) ?></div>
    <form method="post" class="stimmzettel">
        <?= csrf_field() ?>
        <button type="submit" name="votum" value="ja" class="btn-ja">Ja</button>
        <button type="submit" name="votum" value="nein" class="btn-nein">Nein</button>
        <button type="submit" name="votum" value="enthaltung" class="btn-enthaltung">Enthaltung</button>
    </form>
    <div class="antragstext">So sieht später auf deinem eigenen Smartphone der echte Stimmzettel aus - einfach antippen.</div>
<?php elseif ($schritt === '2' && $fehler !== ''): ?>
    <div class="fehler"><?= h($fehler) ?> <a href="uebung.php?neu=1">Neu starten</a></div>
<?php else: ?>
    <div class="frage"><?= h(UEBUNG_ANTRAG_TITEL_START) ?></div>
    <div class="diskussion">💬 Diskussion läuft … (in der echten Versammlung diskutieren die Mitglieder hier den Antrag, bevor abgestimmt wird)</div>
    <a class="weiter-btn" href="uebung.php?schritt=2">Abstimmung nun starten →</a>
    <div class="antragstext" style="padding-top:18px;">Diese Übung zeigt dir genau den Ablauf, den du bei der echten Versammlung auf deinem eigenen Smartphone durchläufst - Antrag/Diskussion, dann die Abstimmung per Fingertipp, dann das Ergebnis.</div>
<?php endif; ?>

</body>
</html>
