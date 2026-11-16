<?php
/**
 * index.php - Startseite und Einstiegspunkt der MV-Neukonzeption.
 *
 * Enthaelt bewusst wenig Rechteverwaltung (anders als die Sitzungsverwaltung):
 * die meisten Inhalte sind fuer jedes Mitglied lesbar, nur einzelne
 * Redaktions-/Admin-Bereiche sind eingeschraenkt. Ziel ist ein sehr
 * leichtgewichtiger Seitenaufruf, da waehrend der Versammlung viele
 * Teilnehmende gleichzeitig - meist per Smartphone - zugreifen.
 */

require_once __DIR__ . '/functions_neu.php';
require_once __DIR__ . '/protokoll_functions.php';
require_once __DIR__ . '/mitglieder_functions.php';
require_once __DIR__ . '/antraege_functions.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php';
    session_start();
}

$pdo = get_pdo();

// Logout: SSO-Anmeldung (REMOTE_USER) laesst sich von PHP aus nicht direkt
// abmelden - ohne hinterlegte sso_logout_url (Admin > Vereinsspezifische
// Vorgaben) wird nur die lokale Session beendet.
if (isset($_GET['logout'])) {
    $logout_config = get_config($pdo);
    $sso_logout_url = trim($logout_config['sso_logout_url'] ?? '');
    $_SESSION = [];
    session_destroy();
    header('Location: ' . ($sso_logout_url !== '' ? $sso_logout_url : '?'));
    exit;
}

// Nicht privilegierte Mitglieder sollen ein frisch angelegtes, noch nicht
// freigegebenes Jahr gar nicht erst sehen/waehlen koennen (siehe
// get_sichtbares_jahr()) - der Check laeuft bewusst schon hier, vor der
// eigentlichen Jahresauswahl/Mitgliedsermittlung weiter unten, und ohne
// Seiteneffekt (siehe ist_redaktion_im_neuesten_jahr()).
$privilegiert_fuer_jahr = ist_redaktion_im_neuesten_jahr($pdo);

if (isset($_GET['jahr'])) {
    // Explizite Auswahl (z. B. ueber "Einstellungen") merkt sich die Session,
    // damit sie beim naechsten Klick auf einen Menuepunkt nicht verloren geht -
    // die Navigationslinks tragen den jahr-Parameter bewusst nicht mit.
    // Eigener, VERA-spezifischer Schluessel (nicht einfach "gewaehltes_jahr"):
    // MISS/KONA/KAND teilen sich denselben domainweiten Session-Cookie (siehe
    // session_config.php) - ein generischer Name koennte sonst mit einer
    // gleichnamigen Sitzungsvariable einer Schwesteranwendung kollidieren.
    $jahr = (int)$_GET['jahr'];
    $_SESSION['vera_gewaehltes_jahr'] = $jahr;
} elseif (isset($_SESSION['vera_gewaehltes_jahr'])) {
    $jahr = (int)$_SESSION['vera_gewaehltes_jahr'];
} else {
    $jahr = get_sichtbares_jahr($pdo, $privilegiert_fuer_jahr);
}
if (!$privilegiert_fuer_jahr && !jahr_ist_freigegeben($pdo, $jahr)) {
    // Direkter Versuch (Lesezeichen, geratene/alte URL, gemerkte Session aus
    // der Zeit vor einer Rollenaenderung) auf ein gesperrtes Jahr - auf das
    // sichtbare Jahr zuruecksetzen, statt die Inhalte des gesperrten Jahres
    // trotzdem auszuliefern.
    $jahr = get_sichtbares_jahr($pdo, false);
    unset($_SESSION['vera_gewaehltes_jahr']);
}
// Editierende Aktionen sind ausschliesslich im aktuellen (neuesten) Jahr
// erlaubt (siehe nur_aktuelles_jahr_bearbeitbar_oder_abbrechen() in den
// process_*.php-Dateien) - $ist_aktuelles_jahr steuert hier zusaetzlich
// den Hinweisbanner weiter unten, damit niemand erst beim Speichern von
// der Sperre ueberrascht wird.
$ist_aktuelles_jahr = ($jahr === get_aktuelles_jahr($pdo));
$mvtexte = get_mvtexte($pdo, $jahr);
antrag_spalten_sicherstellen($pdo, $jahr); // stellt bei einer komplett neuen Installation ueberhaupt erst die Tabelle mvantraege_<jahr> sicher
[$mitglied, $mitglied_neu_registriert] = mitglied_laden_oder_registrieren($pdo, $jahr);
$ist_redaktion = ist_redaktion($mitglied);
$ist_beamersteuerung_berechtigt = ist_beamersteuerung_berechtigt($mitglied);
$config = get_config($pdo);
if ($mitglied) {
    mitglied_anwesenheit_simulieren($pdo, $jahr, $config, $mitglied);
}

// Demo-Modus: nicht angemeldete Gaeste direkt auf die Demo-Startseite
// (login.php) leiten, statt die normale (im Demo-Fall meist noch leere)
// Gast-Ansicht zu zeigen - ein Demo-Deployment soll sofort die Demo-
// Login-Auswahl praesentieren, ohne dass jemand zuerst den kleinen
// "Anmelden"-Link im Kopfbereich entdecken muesste.
if (!$mitglied && $_SERVER['REQUEST_METHOD'] === 'GET' && ($config['demo_modus_aktiv'] ?? '0') === '1') {
    header('Location: login.php');
    exit;
}

$nav_items = get_nav_items();
$standard_tab = $mitglied_neu_registriert ? 'willkommen' : 'tagesordnung';
$active_tab = $_GET['tab'] ?? $standard_tab;
if (!isset($nav_items[$active_tab])) {
    $active_tab = $standard_tab;
}
if ($nav_items[$active_tab]['redaktion'] && !$ist_redaktion) {
    $active_tab = $standard_tab; // kein Zugriff -> zurueck auf die Startseite
}
if (($nav_items[$active_tab]['beamersteuerung'] ?? false) && !$ist_beamersteuerung_berechtigt) {
    $active_tab = $standard_tab; // kein Zugriff -> zurueck auf die Startseite
}

// Manche Vereine nutzen MV-Beschluss-Sammlung/Wahl-Information nicht - per
// Vereinsvorgabe ausschaltbar (siehe tab_vorgaben.php). Default '1' (an),
// damit bestehende Installationen ohne diese Einstellung unveraendert
// weiterlaufen.
$abgeschaltete_tabs = array_filter(['beschluesse', 'wahlinfo'], fn($t) => ($config[$t . '_aktiv'] ?? '1') !== '1');
if (in_array($active_tab, $abgeschaltete_tabs, true)) {
    $active_tab = $standard_tab;
}

// "Neuen Antrag stellen": ausgegraut (aber weiterhin aufrufbar, die Seite
// selbst erklaert dann den Grund), sobald das Kontingent ausgeschoepft ist.
// Fuer die Redaktion gilt kein Kontingent - sie soll auch Antraege anderer
// Mitglieder einstellen koennen.
$antrag_stellen_gesperrt = $mitglied && !$ist_redaktion
    && zaehle_hauptantraege_von($pdo, $jahr, $mitglied['MNr']) >= (int)($config['max_hauptantraege_pro_mitglied'] ?? 2);

$forum_aktiv = ($config['diskussionsforum_aktiv'] ?? '0') === '1' && trim($config['diskussionsforum_link'] ?? '') !== '';

// ============================================
// PROCESS-DATEIEN: verarbeiten POST-Requests VOR jeder HTML-Ausgabe,
// damit header()-Weiterleitungen (Redirect nach dem Speichern) moeglich sind.
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $active_tab === 'tagesordnung' && $ist_redaktion
    && is_file(__DIR__ . '/process_tagesordnung.php')) {
    require __DIR__ . '/process_tagesordnung.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($active_tab, ['antrag_stellen', 'antrag_liste', 'stimmungsbild'], true) && $mitglied
    && is_file(__DIR__ . '/process_antraege.php')) {
    require __DIR__ . '/process_antraege.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $active_tab === 'unterlagen' && $ist_redaktion) {
    if (isset($_POST['feld']) && is_file(__DIR__ . '/process_mvtext.php')) {
        require __DIR__ . '/process_mvtext.php';
    } elseif (is_file(__DIR__ . '/process_unterlagen.php')) {
        require __DIR__ . '/process_unterlagen.php';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($active_tab, ['willkommen', 'wahlinfo'], true) && $ist_redaktion
    && isset($_POST['feld']) && is_file(__DIR__ . '/process_mvtext.php')) {
    require __DIR__ . '/process_mvtext.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $active_tab === 'kompaktliste' && $ist_redaktion
    && is_file(__DIR__ . '/process_kompaktliste.php')) {
    require __DIR__ . '/process_kompaktliste.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $active_tab === 'beamersteuerung' && $ist_beamersteuerung_berechtigt
    && is_file(__DIR__ . '/process_beamersteuerung.php')) {
    require __DIR__ . '/process_beamersteuerung.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $active_tab === 'beschluesse' && $ist_redaktion
    && is_file(__DIR__ . '/process_beschluesse.php')) {
    require __DIR__ . '/process_beschluesse.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $active_tab === 'admin' && $ist_redaktion) {
    $bereich = $_POST['bereich'] ?? ($_GET['bereich'] ?? '');
    if ($bereich === 'rechte' && is_file(__DIR__ . '/process_rechte.php')) {
        require __DIR__ . '/process_rechte.php';
    }
    if ($bereich === 'vorgaben' && is_file(__DIR__ . '/process_vorgaben.php')) {
        require __DIR__ . '/process_vorgaben.php';
    }
    if ($bereich === 'termine' && is_file(__DIR__ . '/process_termine.php')) {
        require __DIR__ . '/process_termine.php';
    }
    if ($bereich === 'jahr' && is_file(__DIR__ . '/process_jahr.php')) {
        require __DIR__ . '/process_jahr.php';
    }
    if ($bereich === 'reihenfolge' && is_file(__DIR__ . '/process_reihenfolge.php')) {
        require __DIR__ . '/process_reihenfolge.php';
    }
    if ($bereich === 'mitgliederstamm' && is_file(__DIR__ . '/process_mitgliederstamm.php')) {
        require __DIR__ . '/process_mitgliederstamm.php';
    }
    if ($bereich === 'demo_daten' && is_file(__DIR__ . '/process_demo_daten.php')) {
        require __DIR__ . '/process_demo_daten.php';
    }
    if ($bereich === 'abstimmung' && is_file(__DIR__ . '/process_abstimmung.php')) {
        require __DIR__ . '/process_abstimmung.php';
    }
    if ($bereich === 'wahlgaenge' && is_file(__DIR__ . '/process_wahlgaenge.php')) {
        require __DIR__ . '/process_wahlgaenge.php';
    }
}

$mvort = $mvtexte['mvort'] ?? '';
$mvdatum = $mvtexte['mvdatum'] ?? '';
$logo_pfad = trim($config['logo_pfad'] ?? '');
$favicon_pfad = trim($config['favicon_pfad'] ?? '');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($config['mv_bezeichnung']) ?> <?= h((string)$jahr) ?> — <?= h($config['vereinsname']) ?> · <?= APP_NAME ?></title>
    <?php if ($favicon_pfad !== ''): ?>
        <link rel="icon" href="<?= h($favicon_pfad) ?>">
    <?php endif; ?>
    <?php
    // Cache-Buster ueber die Aenderungszeit der Datei: verhindert, dass
    // Handy-Browser nach einem Deployment noch lange ein veraltetes,
    // zwischengespeichertes style.css verwenden (z. B. mit fehlenden
    // mobilen Regeln fuer die Navigation) - ohne dass bei jedem Aufruf
    // eine neue, sich staendig aendernde Versionsnummer noetig waere.
    $style_version = @filemtime(__DIR__ . '/style.css') ?: time();
    ?>
    <link rel="stylesheet" href="style.css?v=<?= $style_version ?>">
</head>
<body>

<div class="header">
    <div class="header-inner header-dreiteilig">
        <a href="?" class="header-brand" title="Zur Startseite">
            <?php if ($logo_pfad !== ''): ?>
                <img src="<?= h($logo_pfad) ?>" alt="Logo" style="height:40px; max-width:120px; object-fit:contain;">
            <?php endif; ?>
            <span class="header-titeltext">
                <span class="header-verein"><?= $logo_pfad === '' ? '🏛️ ' : '' ?><?= h($config['vereinsname']) ?></span>
                <span class="subtitle"><b><?= APP_NAME ?></b> · <?= APP_TAG ?></span>
            </span>
        </a>
        <div class="header-mitte">
            <h1><?= h($config['mv_bezeichnung']) ?> <?= h((string)$jahr) ?></h1>
            <?php if ($mvdatum || $mvort): ?>
                <div class="subtitle"><?= h($mvdatum) ?><?= ($mvdatum && $mvort) ? ' · ' : '' ?><?= h($mvort) ?></div>
            <?php endif; ?>
        </div>
        <div class="user-info">
            <?php if ($mitglied): ?>
                <div class="user-name" style="display:flex; flex-direction:column; align-items:flex-end; line-height:1.35;">
                    <span><?= h($mitglied['Vorname'] . ' ' . $mitglied['Name']) ?></span>
                    <span>
                        <?php if (($config['mitgliedschaftspruefung_aktiv'] ?? '0') === '1'): ?>
                            <a href="ausweis.php" class="logout-btn">🪪 Mein Mitgliedsausweis</a> ·
                        <?php endif; ?>
                        <?php if (($config['hybride_versammlung_aktiv'] ?? '0') === '1'): ?>
                            <a href="teilnahmeform.php" class="logout-btn">🪑 Teilnahmeform wählen</a> ·
                            <?php if (($mitglied['Teilnahmeform'] ?? '') === 'online'): ?>
                                <a href="online_abstimmen.php" class="logout-btn">💻 Online abstimmen</a> ·
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (($config['demo_modus_aktiv'] ?? '0') === '1'): ?>
                            <a href="login.php?wechseln=1" class="logout-btn">Demo-Nutzer wechseln</a> ·
                        <?php endif; ?>
                        <a href="?logout=1" class="logout-btn">Abmelden</a>
                    </span>
                </div>
                <?php if ($ist_redaktion): ?><span class="role-badge">Redaktion</span><?php endif; ?>
            <?php else: ?>
                <div class="user-name" style="display:flex; align-items:center; gap:10px;">
                    <span>Gast</span>
                    <?php if (($config['login_modul_aktiv'] ?? '0') === '1' || ($config['demo_modus_aktiv'] ?? '0') === '1'): ?>
                        <a href="login.php" class="logout-btn">Anmelden</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <button class="dark-mode-toggle" id="darkModeToggle" title="Dunkelmodus">🌙</button>
            <div class="hamburger-menu" id="hamburgerMenu"><span></span><span></span><span></span></div>
        </div>
    </div>
</div>

<div class="navigation" id="mainNav">
    <?php foreach ($nav_items as $key => $item): ?>
        <?php if ($item['redaktion'] && !$ist_redaktion) continue; ?>
        <?php if (($item['beamersteuerung'] ?? false) && !$ist_beamersteuerung_berechtigt) continue; ?>
        <?php if (in_array($key, $abgeschaltete_tabs, true)) continue; ?>
        <?php if ($key === 'unterlagen'): ?>
            <a href="?tab=unterlagen" class="<?= $active_tab === 'unterlagen' ? 'active' : '' ?>"><?= $item['icon'] ?> Unterlagen <?= h((string)$jahr) ?></a>
        <?php elseif ($key === 'antrag_stellen'): ?>
            <a href="?tab=antrag_stellen" class="<?= $active_tab === $key ? 'active' : '' ?><?= $antrag_stellen_gesperrt ? ' nav-disabled' : '' ?>">
                <?= $item['icon'] ?> <?= h($item['label']) ?>
            </a>
        <?php elseif ($key === 'kompaktliste'): ?>
            <a href="?tab=kompaktliste" class="<?= $active_tab === $key ? 'active' : '' ?>">
                <?= $item['icon'] ?> <?= h($item['label']) ?>
            </a>
            <?php if ($forum_aktiv): ?>
                <a href="<?= h($config['diskussionsforum_link']) ?>" target="_blank" rel="noopener">💬 Diskussionsforum</a>
            <?php endif; ?>
        <?php else: ?>
            <a href="?tab=<?= h($key) ?>" class="<?= $active_tab === $key ? 'active' : '' ?>">
                <?= $item['icon'] ?> <?= h($item['label']) ?>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php $portal_url = trim($config['portal_url'] ?? ''); if ($mitglied && $portal_url !== '' && preg_match('~^(https?://|/|\.\./)~i', $portal_url)): ?>
        <a href="<?= h($portal_url) ?>">← <?= h(trim($config['portal_name'] ?? '') ?: 'MISS') ?></a>
    <?php endif; ?>
</div>

<div class="container">
    <?php if (!$ist_aktuelles_jahr): ?>
        <div class="info-box" style="border-left-color:var(--danger); color:var(--danger);">
            ⚠️ Du betrachtest das Jahr <?= h((string)$jahr) ?> - nicht das aktuelle Jahr. Hier sind keine Änderungen möglich.
        </div>
    <?php endif; ?>
    <div class="content">
        <?php
        $tab_datei = __DIR__ . '/tab_' . $active_tab . '.php';
        if (is_file($tab_datei)) {
            include $tab_datei;
        } else {
            echo '<div class="info-box">Dieser Bereich ist noch nicht angelegt: ' . h($active_tab) . '</div>';
        }
        ?>
    </div>
</div>

<footer class="page-footer">
    <?= defined('FOOTER_COPYRIGHT') && trim((string)FOOTER_COPYRIGHT) !== '' ? FOOTER_COPYRIGHT : '&copy; ' . date('Y') . ' ' . h($config['vereinsname']) ?>
    <?php if (defined('FOOTER_IMPRESSUM_URL') && trim((string)FOOTER_IMPRESSUM_URL) !== ''): ?>
        | <a href="<?= h(FOOTER_IMPRESSUM_URL) ?>" target="_blank" rel="noopener">Impressum</a>
    <?php endif; ?>
    <?php if (defined('FOOTER_DATENSCHUTZ_URL') && trim((string)FOOTER_DATENSCHUTZ_URL) !== ''): ?>
        | <a href="<?= h(FOOTER_DATENSCHUTZ_URL) ?>" target="_blank" rel="noopener">Datenschutz</a>
    <?php endif; ?>
</footer>

<script>
document.getElementById('hamburgerMenu').addEventListener('click', function () {
    document.getElementById('mainNav').classList.toggle('open');
});

(function () {
    var html = document.documentElement;
    var toggle = document.getElementById('darkModeToggle');
    if (localStorage.getItem('mvDarkMode') === 'on') html.classList.add('dark-mode');
    toggle.addEventListener('click', function () {
        html.classList.toggle('dark-mode');
        localStorage.setItem('mvDarkMode', html.classList.contains('dark-mode') ? 'on' : 'off');
    });
})();
</script>

</body>
</html>
