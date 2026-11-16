<?php
/**
 * login.php - Eigenstaendiger Einstiegspunkt fuer das optionale
 * Magic-Link-Login (siehe login_functions.php) und/oder die Demo-
 * Startseite (siehe mitgliederquelle_functions.php: demo_mitgliederliste_laden()),
 * optisch identisch zur Demo-Startseite der Schwesterprojekte
 * Sitzungsverwaltung (KONA) und Mitgliederverwaltung (MISS): kurze
 * Feature-Uebersicht plus Direkt-Login-Auswahl, statt nur eines nackten
 * Anmeldeformulars. Nur erreichbar/wirksam, wenn mindestens eine der
 * beiden Vereinsvorgaben login_modul_aktiv/demo_modus_aktiv gesetzt ist -
 * fuer Installationen mit vorgelagertem SSO (Mensa) bleibt diese Seite
 * ungenutzt und ohne Wirkung auf den bestehenden Anmeldeweg (REMOTE_USER).
 *
 * Bei aktivem Demo-Modus zeigt ?echt=1 stattdessen das normale,
 * schlichte Anmeldeformular - zum Ausprobieren des echten Anmeldewegs
 * (Magic-Link), ohne den Demo-Modus dafuer extra abschalten zu muessen.
 *
 * Nicht angemeldete Gaeste werden bei aktivem Demo-Modus von index.php
 * automatisch hierher geleitet (siehe dort) - ein Demo-Deployment zeigt
 * also nie eine leere/generische Gast-Ansicht, sondern sofort diese
 * Startseite.
 */

require_once __DIR__ . '/functions_neu.php';
require_once __DIR__ . '/protokoll_functions.php';
require_once __DIR__ . '/mitglieder_functions.php';
require_once __DIR__ . '/login_functions.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php';
    session_start();
}

$pdo = get_pdo();
$config = get_config($pdo);

$login_modul_aktiv = ($config['login_modul_aktiv'] ?? '0') === '1';
$demo_aktiv = ($config['demo_modus_aktiv'] ?? '0') === '1';
if (!$login_modul_aktiv && !$demo_aktiv) {
    http_response_code(404);
    echo 'Diese Seite ist nicht verfügbar.';
    exit;
}

// Schon ueber die familienweite Sitzung angemeldet (MISS/KONA/KAND, siehe
// get_aktuelle_mnr()) - direkt weiter, keine Auswahlseite noetig. Greift
// z. B. bei einem alten Lesezeichen auf login.php, waehrend man ueber den
// Portal-Link einer Schwesteranwendung bereits angemeldet ist. Ausnahme
// ?wechseln=1 (siehe "Demo-Nutzer wechseln"-Link in index.php): zeigt die
// Auswahl trotzdem, mit der aktuell aktiven Person markiert, statt
// kommentarlos weiterzuleiten - sonst gaebe es keine Moeglichkeit, eine
// anderswo (auch in einer Schwesteranwendung) bereits gewaehlte
// Demo-Person wieder zu wechseln, ausser ueber vollstaendiges Abmelden.
if (!isset($_GET['token']) && !isset($_GET['demo_mnr']) && !isset($_GET['echt']) && !isset($_GET['wechseln']) && $_SERVER['REQUEST_METHOD'] !== 'POST' && get_aktuelle_mnr($pdo) !== '') {
    header('Location: index.php');
    exit;
}

// Feste Admin-Demo-Figur (wie bei KONA/MISS): wird in der Anzeige immer als
// letzte Karte gezeigt und bekommt bei jedem Demo-Login automatisch
// Redaktionsrechte (Red = 9) - loest nebenbei das Henne-Ei-Problem der
// allerersten Rechtevergabe (siehe Admin-Installationsanleitung, Abschnitt 7):
// ganz ohne SQL von Hand ist darüber sofort ein Zugang mit vollen Rechten
// verfügbar.
const DEMO_ADMIN_MNR = 'DEMO0001';

$jahr = get_aktuelles_jahr($pdo);
$hinweis = null;
$fehler = null;
// Vollstaendige Liste fuer die Gueltigkeitspruefung eines demo_mnr-Klicks;
// die Anzeige zeigt davon nur eine zufaellige Auswahl (siehe unten) - waere
// die Pruefung auf dieselbe zufaellige Teilmenge beschraenkt, koennte ein
// Klick auf einen gerade erst angezeigten, echten Demo-Button fehlschlagen,
// sobald der naechste Seitenaufruf eine andere Zufallsauswahl zieht.
$demo_liste_komplett = $demo_aktiv ? demo_mitgliederliste_laden($pdo, $config) : [];

// Demo-Login: Mitglied direkt ohne Mail-Versand anmelden - nur wenn
// demo_modus_aktiv gesetzt ist und die MNr tatsaechlich in der aktuellen
// Mitgliederquelle existiert (keine frei erfundenen MNr zulassen).
if ($demo_aktiv && isset($_GET['demo_mnr'])) {
    $demo_mnr = (string)$_GET['demo_mnr'];
    if (in_array($demo_mnr, array_column($demo_liste_komplett, 'mnr'), true)) {
        mitglied_finden_oder_anlegen($pdo, $jahr, $demo_mnr);
        if (strcasecmp($demo_mnr, DEMO_ADMIN_MNR) === 0) {
            // Bei jedem Login erneut setzen (nicht nur bei Neuanlage), damit
            // die Admin-Demo-Figur auch nach einem versehentlichen
            // Rechte-Entzug zuverlaessig admin-faehig bleibt.
            $pdo->prepare('UPDATE `mvmitglieder_' . (int)$jahr . '` SET Red = 9 WHERE MNr = ?')->execute([$demo_mnr]);
        }
        $_SESSION['login_mnr'] = $demo_mnr;
        familiensession_mnr_spiegeln($pdo, $config, $demo_mnr); // MISS/KONA/KAND sollen dieselbe Demo-Person sehen
        header('Location: index.php');
        exit;
    }
}

// Link aus der Mail angeklickt: Token einloesen und anmelden.
if (isset($_GET['token'])) {
    $mnr = login_token_einloesen($pdo, (string)$_GET['token']);
    if ($mnr !== null) {
        mitglied_finden_oder_anlegen($pdo, $jahr, $mnr); // legt bei Bedarf den Jahresdatensatz an, wie beim SSO-Erstzugriff
        $_SESSION['login_mnr'] = $mnr;
        familiensession_mnr_spiegeln($pdo, $config, $mnr); // dito fuer den echten Magic-Link-Login
        header('Location: index.php');
        exit;
    }
    $fehler = 'Dieser Anmeldelink ist ungültig oder abgelaufen. Bitte fordere unten einen neuen an.';
}

// Formular abgeschickt: Mail mit neuem Link verschicken.
if ($login_modul_aktiv && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $mnr = trim((string)($_POST['mnr'] ?? ''));
    if ($mnr !== '') {
        [$mitglied, ] = mitglied_finden_oder_anlegen($pdo, $jahr, $mnr);
        $mail_adresse = trim((string)($mitglied['Mail'] ?? ''));
        if ($mail_adresse !== '' && login_anfragen_zuletzt($pdo, $mnr) < 3) {
            $token = login_token_erzeugen($pdo, $mnr);
            $basis_url = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
            login_mail_versenden($mail_adresse, $basis_url . '/login.php?token=' . $token);
        }
    }
    // Bewusst immer dieselbe Rueckmeldung - unabhaengig davon, ob die MNr
    // bekannt ist oder eine Mailadresse hinterlegt ist, damit sich darueber
    // nicht erraten laesst, wer im System angelegt ist.
    $hinweis = 'Falls diese Mitgliedsnummer bekannt ist und eine Mailadresse hinterlegt ist, wurde gerade ein Anmeldelink verschickt. Bitte E-Mails (auch Spam-Ordner) prüfen.';
}

// Demo-Startseite (Feature-Uebersicht + Direkt-Login-Auswahl) statt des
// nackten Anmeldeformulars - ausser bei ?echt=1 (siehe Klassenkommentar).
$demo_startseite_zeigen = $demo_aktiv && !isset($_GET['echt']);
$logo_pfad = trim($config['logo_pfad'] ?? '');

// Nur bei ?wechseln=1 ueberhaupt relevant (siehe Redirect oben) - zeigt an,
// wer gerade aktiv ist (egal ob lokal in VERA oder ueber die familienweite
// Sitzung einer Schwesteranwendung gewaehlt), damit die Auswahl beim
// bewussten Wechseln nachvollziehbar bleibt statt einer anonymen Kartenliste.
$aktuell_aktive_mnr = $demo_aktiv ? get_aktuelle_mnr($pdo) : '';

// Anzeige: hoechstens 12 Demo-Mitglieder, der Rest davon zufaellig
// ausgewaehlt (nicht die komplette, ggf. lange Liste) - genug fuer einen
// Eindruck, ohne die Seite bei vielen Demo-Mitgliedern unuebersichtlich zu
// machen. Die feste Admin-Demo-Figur (DEMO_ADMIN_MNR) wird dabei - falls
// vorhanden - aus der Zufallsauswahl herausgenommen und immer als letzte
// Karte angehaengt, statt zufaellig irgendwo zu erscheinen oder ganz zu
// fehlen.
$demo_admin_eintrag = null;
$demo_liste_anzeige = [];
foreach ($demo_liste_komplett as $m) {
    if ($demo_admin_eintrag === null && strcasecmp((string)$m['mnr'], DEMO_ADMIN_MNR) === 0) {
        $demo_admin_eintrag = $m;
        continue;
    }
    $demo_liste_anzeige[] = $m;
}
$platz_fuer_zufall = $demo_admin_eintrag !== null ? 11 : 12;
if (count($demo_liste_anzeige) > $platz_fuer_zufall) {
    shuffle($demo_liste_anzeige);
    $demo_liste_anzeige = array_slice($demo_liste_anzeige, 0, $platz_fuer_zufall);
}
if ($demo_admin_eintrag !== null) {
    $demo_liste_anzeige[] = $demo_admin_eintrag;
}

/** Kurzbeschreibung der Kernfunktionen fuer die Demo-Startseite (siehe README.md: Funktionsumfang). */
const LOGIN_FEATURES = [
    ['📝', 'Anträge stellen', 'Haupt-, Änderungs- und Verfahrensanträge stellen, bearbeiten, zurückziehen – mit automatischer Nummerierung je Kategorie.'],
    ['🤝', 'Mitantragstellerschaft & Unterstützung', 'Mit konfigurierbarem Schwellenwert und Frist, Unterstützung wahlweise anonym.'],
    ['📊', 'Stimmungsbild', 'Wichtigkeits- und Zustimmungs-Einschätzung vor der eigentlichen Abstimmung.'],
    ['📅', 'Tagesordnung', 'Mit zugeordneten Dokumenten je Tagesordnungspunkt.'],
    ['📁', 'Unterlagen', 'Jahresspezifische und jahresübergreifende Dokumente, übersichtlich gruppierbar.'],
    ['📚', 'MV-Beschluss-Sammlung', 'Durchsuchbares Archiv vergangener Beschlüsse.'],
    ['📽️', 'Beamersteuerung', 'Steuerung der Versammlung über weitgehend automatisiert vorbereitete Beamer-Bilder zu den Tagesordnungspunkten.'],
    ['🗳️', 'Abstimmungsmaschine', 'Sichere und anonyme Abstimmung per Smartphone mit automatischer Auszählung.'],
    ['⚙️', 'Admin-Bereich', 'Rechteverwaltung, vereinsspezifische Vorgaben, Termine, Protokoll aller schreibenden Zugriffe.'],
];

/** Initialen aus Vorname/Name fuer den Platzhalter-Avatar (wie md_initials() bei der Mitgliederverwaltung). */
function login_initialen(string $vorname, string $name): string {
    $kuerzel = mb_substr($vorname, 0, 1) . mb_substr($name, 0, 1);
    return mb_strtoupper($kuerzel !== '' ? $kuerzel : '?');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anmelden – <?= h($config['vereinsname']) ?> · <?= APP_NAME ?></title>
    <?php if (trim($config['favicon_pfad'] ?? '') !== ''): ?>
        <link rel="icon" href="<?= h($config['favicon_pfad']) ?>">
    <?php endif; ?>
    <?php
    // Cache-Buster wie in index.php - sonst kann ein Browser, der style.css
    // schon vor einem Deployment zwischengespeichert hat, hier dauerhaft
    // eine veraltete Fassung ohne die Demo-Startseiten-Regeln verwenden.
    $style_version = @filemtime(__DIR__ . '/style.css') ?: time();
    ?>
    <link rel="stylesheet" href="style.css?v=<?= $style_version ?>">
</head>
<body>
<?php if ($demo_startseite_zeigen): ?>
<div class="header">
    <div class="header-inner header-dreiteilig">
        <a href="login.php" class="header-brand" title="<?= h($config['vereinsname']) ?>">
            <?php if ($logo_pfad !== ''): ?>
                <img src="<?= h($logo_pfad) ?>" alt="Logo" style="height:40px; max-width:120px; object-fit:contain;">
            <?php endif; ?>
            <span class="header-titeltext">
                <span class="header-verein"><?= $logo_pfad === '' ? '🏛️ ' : '' ?><?= h($config['vereinsname']) ?></span>
                <span class="subtitle"><b><?= APP_NAME ?></b> · <?= APP_TAG ?></span>
            </span>
        </a>
    </div>
</div>
<div class="container">
    <div class="welcome-content">
        <div class="features-box">
            <h2><?= APP_NAME ?> · <?= APP_TAG ?></h2>
            <p class="app-lead">VERA ist das System für die rechtssichere Abwicklung von Anträgen vor und während der Mitgliederversammlung.</p>
            <div class="features-grid">
                <?php foreach (LOGIN_FEATURES as [$icon, $titel, $text]): ?>
                    <div class="feature-item">
                        <span class="icon"><?= $icon ?></span>
                        <span class="text"><strong><?= h($titel) ?>:</strong> <?= h($text) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($fehler): ?>
            <div class="info-box" style="border-left-color:var(--danger); color:var(--danger); margin-bottom:16px;"><?= h($fehler) ?></div>
        <?php endif; ?>

        <div class="demo-box">
            <h2>🎭 Demo-Zugang – Testmitglied auswählen</h2>
            <?php if ($aktuell_aktive_mnr !== ''): ?>
                <div class="info-box" style="margin-bottom:14px;">
                    Aktuell angemeldet als <strong><?= h($aktuell_aktive_mnr) ?></strong> - diese Auswahl gilt auch in
                    MISS, KONA und KAND. Weiter unten anklicken, um zu einer anderen Person zu wechseln
                    (gilt dann ebenfalls überall).
                    <a href="index.php" style="margin-left:8px;">Weiter als <?= h($aktuell_aktive_mnr) ?> →</a>
                </div>
            <?php endif; ?>
            <?php if ($demo_liste_anzeige): ?>
                <div class="demo-grid">
                    <?php foreach ($demo_liste_anzeige as $m): ?>
                        <?php
                        $name = trim($m['vorname'] . ' ' . $m['name']);
                        $ist_admin_figur = strcasecmp((string)$m['mnr'], DEMO_ADMIN_MNR) === 0;
                        $ist_aktiv = $aktuell_aktive_mnr !== '' && strcasecmp((string)$m['mnr'], $aktuell_aktive_mnr) === 0;
                        ?>
                        <a class="demo-btn<?= $ist_aktiv ? ' demo-btn-aktiv' : '' ?>" href="login.php?demo_mnr=<?= h((string)$m['mnr']) ?>">
                            <span class="avatar"><?= h(login_initialen((string)$m['vorname'], (string)$m['name'])) ?></span>
                            <span><strong><?= $name !== '' ? h($name) : h((string)$m['mnr']) ?></strong><small><?= h((string)$m['mnr']) ?><?= $ist_admin_figur ? ' · Redaktion/Admin' : '' ?><?= $ist_aktiv ? ' · ✓ aktuell aktiv' : '' ?></small></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <p class="demo-hint">Ein Klick meldet direkt an, ganz ohne Mail-Versand oder Passwort.<?= count($demo_liste_komplett) > count($demo_liste_anzeige) ? ' Zufällige Auswahl aus insgesamt ' . count($demo_liste_komplett) . ' Demo-Mitgliedern.' : '' ?></p>
            <?php else: ?>
                <p class="demo-hint">Aktuell sind keine Demo-Mitglieder hinterlegt.</p>
            <?php endif; ?>
            <?php if ($login_modul_aktiv): ?>
                <div class="doc-links"><a href="login.php?echt=1">Anmeldung mit echten Daten ausprobieren (Mitgliedsnummer + Mail) →</a></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php else: ?>
<div class="container" style="max-width:420px; margin:60px auto;">
    <div class="content">
        <h2>Anmelden</h2>
        <p style="color:var(--text-secondary); font-size:12px; margin-top:-6px;"><b><?= APP_NAME ?></b> · <?= APP_TAG ?> – <?= h($config['vereinsname']) ?></p>
        <?php if ($login_modul_aktiv): ?>
            <p style="color:var(--text-secondary); font-size:13.5px;">
                Gib deine Mitgliedsnummer ein - du bekommst dann einen Anmeldelink per Mail.
            </p>
        <?php endif; ?>

        <?php if ($fehler): ?>
            <div class="info-box" style="border-left-color:var(--danger); color:var(--danger);"><?= h($fehler) ?></div>
        <?php endif; ?>
        <?php if ($hinweis): ?>
            <div class="info-box"><?= h($hinweis) ?></div>
        <?php endif; ?>

        <?php if ($login_modul_aktiv): ?>
            <form method="post">
                <?= csrf_field() ?>
                <div class="form-group" style="margin-bottom:14px;">
                    <label>Mitgliedsnummer</label>
                    <input type="text" name="mnr" required style="width:100%; padding:7px 10px;">
                </div>
                <button type="submit" class="btn">Anmeldelink anfordern</button>
            </form>
        <?php endif; ?>
        <?php if ($demo_aktiv): ?>
            <p style="text-align:center; margin-top:14px; font-size:13px;"><a href="login.php">← Zurück zur Demo-Startseite</a></p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<footer class="page-footer">
    <?= defined('FOOTER_COPYRIGHT') && trim((string)FOOTER_COPYRIGHT) !== '' ? FOOTER_COPYRIGHT : '&copy; ' . date('Y') . ' ' . h($config['vereinsname']) ?>
    <?php if (defined('FOOTER_IMPRESSUM_URL') && trim((string)FOOTER_IMPRESSUM_URL) !== ''): ?>
        | <a href="<?= h(FOOTER_IMPRESSUM_URL) ?>" target="_blank" rel="noopener">Impressum</a>
    <?php endif; ?>
    <?php if (defined('FOOTER_DATENSCHUTZ_URL') && trim((string)FOOTER_DATENSCHUTZ_URL) !== ''): ?>
        | <a href="<?= h(FOOTER_DATENSCHUTZ_URL) ?>" target="_blank" rel="noopener">Datenschutz</a>
    <?php endif; ?>
</footer>
</body>
</html>
