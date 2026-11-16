<?php
/**
 * functions_neu.php - Hilfsfunktionen fuer die Neukonzeption.
 * Rein prozedural, jede Funktion ist eigenstaendig testbar.
 */

require_once __DIR__ . '/db_functions.php';
require_once __DIR__ . '/mitgliederquelle_functions.php';

/**
 * Liefert die Mitgliedsnummer der/des aktuell angemeldeten Person.
 * Vorrangig per SSO ueber REMOTE_USER, genau wie im Bestandssystem -
 * unveraendert fuer jede Installation mit vorgelagertem SSO (z. B. Mensa).
 * Danach ein per Login-Modul zustande gekommenes Session-Login (siehe
 * login.php, nur relevant, wenn die Vereinsvorgabe login_modul_aktiv
 * gesetzt ist - siehe login.php, wo $_SESSION['login_mnr'] ausschliesslich
 * gesetzt wird).
 *
 * Erst danach die familienweite Sitzung: MISS/KONA (Sitzungsverwaltung)/
 * KAND teilen sich seit jeher denselben Session-Cookie (siehe
 * session_config.php, "identisch ... fuer Cookie-Sharing") und schreiben
 * beim eigenen Login $_SESSION['member_id'] (svmembers.member_id) - genau
 * die "Durchlaessigkeit", die diese drei Anwendungen untereinander schon
 * haben. VERA kennt dort aber nur Mitgliedsnummern (membership_number),
 * keine internen IDs, daher die Umrechnung ueber svmembers_mnr_von_id() -
 * und nur, wenn VERA ueberhaupt dieselbe svmembers-Tabelle liest
 * (Mitgliederquelle 'svmembers'), sonst kennt VERA die Person ohnehin
 * nicht. $pdo ist dafuer optional: ohne PDO (z. B. an Stellen ohne
 * Datenbankverbindung) bleiben REMOTE_USER/login_mnr weiterhin die einzigen
 * Quellen, unveraendertes Verhalten.
 */
function get_aktuelle_mnr(?PDO $pdo = null): string {
    if (!empty($_SERVER['REMOTE_USER'])) {
        return $_SERVER['REMOTE_USER'];
    }
    if (isset($_SESSION['login_mnr'])) {
        return $_SESSION['login_mnr'];
    }
    if ($pdo !== null && !empty($_SESSION['member_id']) && (get_config($pdo)['mitglieder_quelle'] ?? '') === 'svmembers') {
        $mnr = svmembers_mnr_von_id($pdo, (int)$_SESSION['member_id']);
        if ($mnr !== null) {
            $_SESSION['login_mnr'] = $mnr; // einmal aufgeloest, danach reicht oben der schnelle Pfad
            return $mnr;
        }
    }
    return '';
}

/**
 * Laedt den Mitgliedsdatensatz der/des aktuell Angemeldeten aus der
 * jahresspezifischen Tabelle mvmitglieder_<jahr>.
 * $jahr wird vor der Verwendung im Tabellennamen auf int erzwungen,
 * da PDO Tabellennamen nicht als Parameter binden kann.
 */
function get_aktuelles_mitglied(PDO $pdo, int $jahr): ?array {
    $mnr = get_aktuelle_mnr($pdo);
    if ($mnr === '') return null;

    $tabelle = 'mvmitglieder_' . (int)$jahr;
    $stmt = $pdo->prepare("SELECT * FROM `$tabelle` WHERE MNr = ? LIMIT 1");
    $stmt->execute([$mnr]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Ist die/der Angemeldete Teil des Redaktionsteams? (Red = 9, siehe
 * Prozesskatalog / Kernprozesse-Dokumentation)
 */
function ist_redaktion(?array $mitglied): bool {
    return $mitglied !== null && (int)($mitglied['Red'] ?? 0) === 9;
}

/**
 * Darf die Beamersteuerung bedienen (eigene Seite, siehe
 * tab_beamersteuerung.php) - ausdrücklich nur Technik/Beamer-Team (Red=1)
 * und Redaktionsteam (Red=9, siehe get_rollen()), nicht Versammlungsleitung
 * oder Protokollführung.
 */
function ist_beamersteuerung_berechtigt(?array $mitglied): bool {
    return $mitglied !== null && in_array((int)($mitglied['Red'] ?? 0), [1, 9], true);
}

/**
 * Darf Stimmkarten am Ein-/Ausgang entwerten (siehe abstimmen.php,
 * vote_token_ungueltig_machen()) - ausdrücklich eine eigene, von der
 * Beamersteuerung getrennte Rolle (Red = 6, "Ein-/Ausgang-Team", siehe
 * get_rollen()): die Personen am Einlass/Ausgang sind in der Praxis nicht
 * dieselben wie das Technik/Beamer-Team. Red > 5 greift bewusst auch für
 * das Redaktionsteam (Red = 9) mit, das ohnehin alles darf.
 */
function ist_ein_ausgang_berechtigt(?array $mitglied): bool {
    return $mitglied !== null && (int)($mitglied['Red'] ?? 0) > 5;
}

/**
 * Rollen, die ueber das bestehende Feld mvmitglieder.Red abgebildet werden.
 * Bewusst wenig Rechteverwaltung (siehe Konzeption) - ein Zahlenfeld statt
 * eines eigenen Berechtigungssystems.
 *
 * ANNAHME aus der Datenmodell-Analyse: Beamer-Team entspricht dem dort
 * dokumentierten Wert "Technik" (1) - bitte korrigieren, falls das nicht
 * zusammengehoert.
 */
function get_rollen(): array {
    return [
        0 => 'Kein besonderes Amt',
        1 => 'Technik / Beamer-Team',
        2 => 'Versammlungsleitung',
        3 => 'Protokollführung',
        6 => 'Ein-/Ausgang-Team',
        9 => 'Redaktionsteam',
    ];
}

function get_rollen_label(int $red): string {
    return get_rollen()[$red] ?? ('Unbekannt (' . $red . ')');
}

/** Name des Tools (Familie MISS / KONA / VERA); die Kurzbeschreibung steht in der Kopfzeile unter dem Vereinsnamen. */
const APP_NAME = 'VERA';
const APP_TAG = 'Versammlungs- und Antragsorganisation';

/** Kleine Escaping-Hilfe fuer HTML-Ausgabe, um htmlspecialchars() nicht ueberall auszuschreiben. */
function h(?string $text): string {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

/** Formatiert ein Datum/Zeit-Feld (Format Y-m-d H:i:s) in deutscher Schreibweise, z. B. "01.11.2025, 14:30 Uhr". */
function format_datum_zeit(?string $wert): string {
    if (!$wert) {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $wert);
    if (!$dt) {
        return $wert; // unbekanntes Format -> unveraendert anzeigen statt zu verschlucken
    }
    return $dt->format('d.m.Y, H:i \U\h\r');
}

/**
 * Escaped Text vollstaendig (wie h()), erlaubt aber eine kleine feste Menge
 * ungefaehrlicher Formatierungs-Tags ohne Attribute, die im Altsystem in
 * Antrags-/Beschlusstexten ueblich waren (z. B. <br>, <li>). Nur exakte,
 * attributlose Tags werden zurueckverwandelt - ein Versuch wie
 * "<br onmouseover=...>" landet nach dem Escaping als "&lt;br onmouseover=...&gt;"
 * und passt nicht auf das Muster, bleibt also sicher escaped.
 */
function h_mit_basis_html(?string $text): string {
    $html = h($text);
    foreach (['br', 'li', 'ul', 'ol', 'b', 'i', 'strong', 'em', 'u'] as $tag) {
        $html = preg_replace('/&lt;' . $tag . '\s*\/?&gt;/i', "<$tag>", $html);
        $html = preg_replace('/&lt;\/' . $tag . '&gt;/i', "</$tag>", $html);
    }
    return $html;
}

/** Antrags-/Beschlusstext fuer die Anzeige: Basis-HTML erlaubt, echte Zeilenumbrueche zusaetzlich als <br>. */
function format_antragstext(?string $text): string {
    return nl2br(h_mit_basis_html($text));
}

// ============================================
// CSRF-SCHUTZ fuer die zustandsaendernden Admin-Formulare.
// Bewusst schlank (Session-Token), aber vorhanden - die urspruengliche
// Sicherheitsanalyse des Bestandssystems hatte fehlenden CSRF-Schutz
// explizit als Fund benannt.
// ============================================

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/** Bricht mit 403 ab, wenn das CSRF-Token fehlt oder nicht passt. */
function csrf_check(): void {
    $ok = isset($_POST['csrf'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf']);
    if (!$ok) {
        http_response_code(403);
        die('Ungültige oder abgelaufene Formular-Anfrage. Bitte Seite neu laden und erneut versuchen.');
    }
}

/**
 * Prueft, ob fuer besonders folgenreiche Systemfunktionen (aktuell: Demo-
 * Daten einspielen, siehe process_demo_daten.php) das zusaetzliche Admin-
 * Passwort (Konstante SYSTEM_ADMIN_PASSWORD, config.php) in dieser Browser-
 * Sitzung schon hinterlegt wurde - analog zum gleichnamigen Schutz der
 * Schwesterprojekte Sitzungsverwaltung (KONA) und Mitgliederverwaltung
 * (MISS). Einmal richtig eingegeben, bleibt der Zugang wie dort fuer den
 * Rest der Sitzung bestehen (kein erneutes Abfragen bei jedem Aufruf).
 */
function system_admin_authentifiziert(): bool {
    return !empty($_SESSION['system_admin_authenticated']);
}

/**
 * Prueft ein per Formular eingegebenes Admin-Passwort gegen
 * SYSTEM_ADMIN_PASSWORD (config.php) und merkt im Erfolgsfall
 * $_SESSION['system_admin_authenticated'] (siehe system_admin_authentifiziert()).
 * Ohne gesetzte oder leere Konstante schlaegt die Pruefung immer fehl -
 * sicherer Standard: die geschuetzte Funktion bleibt gesperrt, bis das
 * Passwort ausdruecklich in config.php vergeben wurde.
 */
function system_admin_passwort_pruefen(string $eingabe): bool {
    if (!defined('SYSTEM_ADMIN_PASSWORD') || SYSTEM_ADMIN_PASSWORD === '') {
        return false;
    }
    if ($eingabe !== '' && hash_equals(SYSTEM_ADMIN_PASSWORD, $eingabe)) {
        $_SESSION['system_admin_authenticated'] = true;
        return true;
    }
    return false;
}

/**
 * Bricht mit 403 ab, wenn $jahr nicht das aktuelle (neueste) Jahr ist.
 * Editierende Aktionen (Anträge, Tagesordnung, Rechte, Termine, Texte, ...)
 * sind ausschließlich im neuesten Jahr erlaubt - fuer alle Mitglieder,
 * auch fuer die Redaktion/Admins -, damit niemand versehentlich ein
 * vergangenes, bereits abgeschlossenes Jahr veraendert, nur weil es
 * gerade per Jahresauswahl angezeigt wird (siehe index.php:
 * $_SESSION['vera_gewaehltes_jahr']). Global gueltige Daten (Vereinsvorgaben,
 * lokaler Mitgliederstamm, die jahresuebergreifende Beschluss-Sammlung,
 * sowie das Anlegen eines neuen Jahres selbst) sind von dieser Sperre
 * bewusst ausgenommen - die jeweiligen process_*.php-Dateien rufen diese
 * Funktion deshalb gar nicht erst auf.
 */
function nur_aktuelles_jahr_bearbeitbar_oder_abbrechen(PDO $pdo, int $jahr): void {
    if ($jahr !== get_aktuelles_jahr($pdo)) {
        http_response_code(403);
        die('Änderungen sind nur im aktuellen (neuesten) Jahr möglich - bitte zuerst oben zum aktuellen Jahr wechseln.');
    }
}

/**
 * Definiert die Navigationspunkte der Neukonzeption an einer zentralen
 * Stelle, damit Menu und Tab-Routing nie auseinanderlaufen.
 * 'redaktion' => true blendet den Punkt fuer Nicht-Redaktionsmitglieder aus.
 */
function get_nav_items(): array {
    return [
        'willkommen'        => ['icon' => '👋', 'label' => 'Willkommen',               'redaktion' => false],
        'tagesordnung'      => ['icon' => '📋', 'label' => 'Tagesordnung',            'redaktion' => false],
        'antrag_stellen'    => ['icon' => '✍️', 'label' => 'Neuen Antrag stellen',     'redaktion' => false],
        'antrag_liste'      => ['icon' => '📄', 'label' => 'Anträge zeigen/bearbeiten','redaktion' => false],
        'stimmungsbild'     => ['icon' => '🙋', 'label' => 'Stimmungsbild abgeben',    'redaktion' => false],
        'kompaktliste'      => ['icon' => '📊', 'label' => 'Kompaktliste',             'redaktion' => false],
        'beamersteuerung'   => ['icon' => '📽️', 'label' => 'Beamersteuerung',          'redaktion' => false, 'beamersteuerung' => true],
        'unterlagen'        => ['icon' => '📁', 'label' => 'Unterlagen',               'redaktion' => false],
        'beschluesse'       => ['icon' => '📜', 'label' => 'MV-Beschluss-Sammlung',    'redaktion' => false],
        'wahlinfo'          => ['icon' => '🗳️', 'label' => 'Wahl-Information',         'redaktion' => false],
        'einstellungen'     => ['icon' => '⚙️', 'label' => 'Jahr ändern',              'redaktion' => false],
        'admin'             => ['icon' => '🔐', 'label' => 'Admin-Funktionen',         'redaktion' => true],
    ];
}
