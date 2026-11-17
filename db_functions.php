<?php
/**
 * db_functions.php - Datenbankverbindung (PDO) sowie Verwaltung der
 * admin-editierbaren Vereinsvorgaben (Tabelle mvconfig) der Neukonzeption
 * ("mv/neu"). Nicht zu verwechseln mit config.php - das ist die pro
 * Installation einmalig angelegte, nicht versionierte Zugangsdaten-Datei
 * (siehe config_example.php als Vorlage), die hier nur eingebunden wird.
 *
 * Nutzt dieselbe DB wie das Bestandssystem, verbindet aber ueber PDO mit
 * Prepared Statements statt mysqli/Stringverkettung (schliesst die im
 * Sicherheits-Review gefundene SQL-Injection-Kategorie), und bleibt dabei
 * rein prozedural (keine Klassen).
 */

// Standardort: config.php direkt neben den Skripten (wie alle anderen
// Dateien dieses Verzeichnisses) - wichtig, damit "neu/" eigenstaendig an
// die Stelle des Bestandssystems kopiert werden kann, ohne eine
// Verzeichnisebene darueber zu benoetigen. Uebergangsweg fuer aeltere
// Installationen, bei denen config.php noch (wie frueher vorgesehen) eine
// Ebene hoeher liegt - wird automatisch weiterhin gefunden.
$config_pfad = __DIR__ . '/config.php';
if (!is_file($config_pfad)) {
    $config_pfad = __DIR__ . '/../config.php';
}
require_once $config_pfad;
unset($config_pfad);

function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . MYSQL_HOST . ';dbname=' . MYSQL_DATABASE . ';charset=utf8mb4';
        $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

/**
 * Prueft, ob eine Spalte in einer Tabelle bereits existiert - Grundlage der
 * Selbstheilung von Tabellen (siehe die verschiedenen *_spalten_sicherstellen()-
 * Funktionen). Bewusst per SHOW COLUMNS statt der kuerzeren
 * "ALTER TABLE ... ADD COLUMN IF NOT EXISTS": Diese Syntax wird von
 * MySQL-Versionen vor 8.0.29 nicht unterstuetzt und fuehrt dort zu einem
 * abgefangenen Syntaxfehler - die Spalte fehlt dann weiterhin, und ein
 * spaeterer INSERT/UPDATE auf sie bricht mit einem unabgefangenen
 * Datenbankfehler ab (so geschehen beim Beamer-Timer auf einer
 * Bestandsinstallation mit aelterem MySQL). SHOW COLUMNS funktioniert auf
 * jeder MySQL-/MariaDB-Version gleichermassen.
 */
function spalte_existiert(PDO $pdo, string $tabelle, string $spalte): bool {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$tabelle` LIKE ?");
    $stmt->execute([$spalte]);
    return $stmt->fetch() !== false;
}

// ============================================
// VEREINSSPEZIFISCHE EINSTELLUNGEN
// Alles, was das Redaktionsteam selbst aendern koennen soll (inkl.
// Vereinsname und der Bezeichnung "Mitgliederversammlung" - manche
// Vereine nennen ihre Versammlung anders), liegt in der Tabelle mvconfig
// (siehe migrations/001_mvconfig.sql) statt in Code-Konstanten.
// ============================================

/** Voreinstellungen, falls ein Schluessel in mvconfig (noch) fehlt. */
const MV_CONFIG_DEFAULTS = [
    'vereinsname'                     => 'Musterverein e. V.',
    'mv_bezeichnung'                  => 'Mitgliederversammlung',
    'max_hauptantraege_pro_mitglied'  => '2',
    'unterstuetzung_schwellenwert'    => '10',
    'unterstuetzung_frist_wochen'     => '4',
    'stimmungsbild_mindestteilnehmer' => '30',
    'rueckzug_nur_bis_antragsschluss' => '1',
    'logo_pfad'                       => '',
    'favicon_pfad'                    => '',
    'diskussionsforum_aktiv'          => '0',
    'diskussionsforum_link'           => '',
    'portal_url'                      => '',
    'portal_name'                     => 'MISS',
    'beschluesse_aktiv'               => '1',
    'wahlinfo_aktiv'                  => '1',
    'sso_logout_url'                  => '',
    // Basis-URL der Abstimmungsmaschine (ohne abschliessenden Slash, ohne
    // Dateinamen) - fuer die QR-Codes auf den Stimmkarten, siehe
    // abstimmung_functions.php. Leer = Stimmkarten koennen nicht erzeugt
    // werden (die Seite weist darauf hin).
    'abstimmung_basis_url'            => '',
    // WLAN-Zugangsdaten fuer einen dedizierten, nicht-oeffentlichen
    // Versammlungs-Server ohne Internetanbindung (siehe Risikoabschaetzung:
    // Ausfallrisiko des oeffentlichen Webservers). Leer = kein WLAN-QR-Code
    // auf Stimmkarte/Beamerbild - fuer den normalen, ueber das Internet
    // erreichbaren Betrieb irrelevant. Nur WPA/WPA2-Netze unterstuetzt
    // (siehe wlan_qr_inhalt() in abstimmung_functions.php).
    'wlan_ssid'                       => '',
    'wlan_passwort'                   => '',
    // Zaehlen Enthaltungen bei der Mehrheitsfeststellung mit? Je nach Satzung
    // unterschiedlich geregelt, deshalb hier einstellbar statt fest im Code.
    // Default '0' (zaehlen NICHT mit - nur Ja+Nein bilden die Basis der
    // Prozentzahlen): das ist die gaengigere Regel und bewusst so vorgegeben.
    'enthaltung_zaehlt_mit'           => '0',
    // Mitgliederquelle: 'ldap' (Default, Bestandsverhalten fuer Mensa -
    // siehe mitglieder_functions.php: ldapident_neu()) oder 'tabelle'
    // (lokale mitglieder_stamm-Tabelle, siehe mitgliederquelle_functions.php
    // - fuer Vereine ohne eigenes LDAP). Der Default aendert am bestehenden
    // Mensa-Verhalten nichts, solange niemand aktiv umstellt.
    'mitglieder_quelle'               => 'ldap',
    // Login-Modul: '0' (Default) = unveraendertes Verhalten, Anmeldung
    // ausschliesslich per vorgelagertem SSO (REMOTE_USER). '1' = zusaetzlich
    // ein eigenes Magic-Link-Login (siehe login.php) fuer Vereine ohne SSO -
    // greift nur, wenn REMOTE_USER leer ist, siehe get_aktuelle_mnr().
    'login_modul_aktiv'               => '0',
    // Demo-Modus: '0' (Default) = unveraendertes Verhalten. '1' = login.php
    // zeigt zusaetzlich eine Direkt-Login-Auswahl (ohne Mail-Versand) fuer
    // alle Mitglieder der aktuellen Mitgliederquelle, siehe
    // mitgliederquelle_functions.php: demo_mitgliederliste_laden().
    // NUR fuer Test-/Demo-Installationen - umgeht jede Authentifizierung,
    // niemals auf einer Installation mit echten Mitgliederdaten aktivieren.
    'demo_modus_aktiv'                => '0',
    // Hybride/Online-Versammlung: Zeitfenster fuer die automatische
    // Anwesenheitserkennung ohne Einlasskontrolle (siehe
    // mitglied_anwesenheit_simulieren() in mitglieder_functions.php) - nur
    // Seitenaufrufe innerhalb dieses Fensters zaehlen als "wirklich
    // anwesend"; Aufrufe Tage vorher (z. B. zum Ausprobieren auf der
    // Uebungsseite) zaehlen bewusst nicht mit. Leer = Simulation komplett
    // deaktiviert (kein automatisches Anwesenheits-Tracking). Format wie
    // von <input type="datetime-local"> geliefert: "JJJJ-MM-TTTHH:MM".
    'versammlung_beginn'              => '',
    'versammlung_ende'                => '',
    // Mitgliedschaftspruefung am Einlass (QR-Scan des elektronischen
    // Mitgliedsausweises, siehe einlasskontrolle.php) aktiv? '0' (Default)
    // = kein Einlass-Check, Anwesenheit nur simuliert (siehe oben) - fuer
    // Vereine, in denen sich die Mitglieder ohnehin persoenlich kennen und
    // keine foermliche Pruefung brauchen.
    'mitgliedschaftspruefung_aktiv'   => '0',
    // URL-Muster fuer das Mitgliedsfoto auf dem elektronischen
    // Mitgliedsausweis (siehe ausweis.php) - {mnr} wird durch die
    // Mitgliedsnummer ersetzt. Leer = kein Foto angezeigt. Das Foto selbst
    // verwaltet ausschliesslich MISS (Mitgliederverwaltung) - VERA zeigt
    // nur ein <img>, das direkt von dort geladen wird, speichert/cached
    // nichts selbst.
    'foto_quelle_url'                 => '',
    // Hybride/Online-Versammlung: '0' (Default) = unveraendertes
    // Verhalten, keine Teilnahmeform-Auswahl sichtbar (fuer Vereine ohne
    // Online-Teilnahme irrelevant). '1' = Mitglieder sehen einen Button
    // "Teilnahmeform wählen" (siehe teilnahmeform.php) und muessen sich
    // vor Ort/online festlegen - Grundlage fuer die Sperre gegen doppelte
    // Teilnahme (siehe einlass_einschaetzung() in ausweis_functions.php).
    'hybride_versammlung_aktiv'       => '0',
];

/**
 * Laedt alle Vereinsvorgaben aus mvconfig, ergaenzt fehlende Schluessel
 * um die Defaults (z.B. direkt nach einer frischen Installation ohne
 * bereits ausgefuehrte Migration).
 */
function get_config(PDO $pdo): array {
    static $config = null;
    if ($config === null) {
        $config = MV_CONFIG_DEFAULTS;
        try {
            $stmt = $pdo->query('SELECT schluessel, wert FROM mvconfig');
            foreach ($stmt->fetchAll() as $row) {
                $config[$row['schluessel']] = $row['wert'];
            }
        } catch (PDOException $e) {
            // Tabelle existiert noch nicht (Migration nicht ausgefuehrt) -
            // Defaults reichen, damit die Seite trotzdem funktioniert.
        }
        // Optionale Konstanten in config.php haben Vorrang vor der
        // admin-editierbaren Vereinsvorgabe - siehe config_example.php,
        // analog zu ORG_NAME/ORG_LOGO/DEMO_MODE bei den Schwesterprojekten
        // Sitzungsverwaltung (KONA) und Mitgliederverwaltung (MISS). Keine
        // der drei Konstanten ist Pflicht - ungesetzt bleibt alles admin-
        // editierbar wie bisher (Mensa definiert keine davon). DEMO_MODE
        // loest nebenbei das Henne-Ei-Problem beim Demo-Modus (siehe
        // Admin-Installationsanleitung, Abschnitt 6c): laesst sich schon
        // bereitstellen, bevor ueberhaupt jemand angemeldet war - ganz
        // ohne Datenbankeintrag von Hand.
        if (defined('ORG_NAME') && ORG_NAME !== '') {
            $config['vereinsname'] = ORG_NAME;
        }
        if (defined('ORG_LOGO') && ORG_LOGO !== '') {
            $config['logo_pfad'] = ORG_LOGO;
        }
        if (defined('DEMO_MODE')) {
            $config['demo_modus_aktiv'] = DEMO_MODE ? '1' : '0';
        }
        if ($config['demo_modus_aktiv'] === '1') {
            // Ein Demo-Deployment soll ganz ohne weitere Admin-Schritte
            // funktionieren: aktiver Demo-Modus erzwingt deshalb immer die
            // Mitgliederquelle "svmembers" (dort liegen - wie bei KONA/MISS -
            // die eigens dafuer erzeugten Demo-Mitglieder), unabhaengig
            // davon, ob der Demo-Modus per Konstante (DEMO_MODE) oder per
            // Vereinsvorgabe aktiviert wurde, und unabhaengig von der sonst
            // admin-editierbaren Mitgliederquelle.
            $config['mitglieder_quelle'] = 'svmembers';
        }
    }
    return $config;
}

/** Schreibt einen einzelnen Konfigurationswert (legt den Schluessel bei Bedarf an). */
function set_config_value(PDO $pdo, string $schluessel, string $wert): void {
    $stmt = $pdo->prepare(
        'INSERT INTO mvconfig (schluessel, wert) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE wert = VALUES(wert)'
    );
    $stmt->execute([$schluessel, $wert]);
}

/**
 * Ab diesem Wert sind mvtexte.jahr-Zeilen keine echten VERA-Kalenderjahre
 * mehr, sondern BEAM-Projekte (beam_projekte.id, siehe neu/beam/
 * beam_functions.php und dokumentation/BEAM.md) - BEAM nutzt dieselbe
 * mvtexte-Tabelle als Beamerstatus-Speicher, mit einem bewusst weit über
 * jedem realistischen Kalenderjahr liegenden Startwert, damit beide Bereiche
 * garantiert nie kollidieren. Jede Stelle in VERA, die "alle Jahre" oder
 * "das aktuelle Jahr" aus mvtexte ermittelt, muss Zeilen ab dieser Grenze
 * ausschließen - sonst hält VERA ein BEAM-Projekt fälschlich für sein
 * eigenes, neuestes MV-Jahr (siehe get_aktuelles_jahr()).
 */
const BEAM_PROJEKT_ID_START = 1000000;

/**
 * Ob die *_sicherstellen()-Funktionen (CREATE TABLE IF NOT EXISTS, ALTER
 * TABLE ADD COLUMN) bei jedem normalen Seitenaufruf aktiv pruefen/anlegen
 * sollen. Default true (Entwicklung/neue Installationen: Schema entsteht
 * "von selbst" beim ersten Zugriff). Im Produktivbetrieb mit vielen
 * gleichzeitigen Anfragen (z.B. waehrend einer Versammlung) ist das
 * unnoetiger Overhead bei jeder einzelnen Anfrage, da sich das Schema
 * zwischen zwei Deployments nicht mehr aendert - per config.php-Konstante
 * DB_SELBSTHEILUNG_AKTIV=false abschaltbar. Schema-Aenderungen dann
 * stattdessen einmalig nach jedem Deployment ueber
 * tools/db_schema_sync.php anstossen (ruft alle *_sicherstellen()-
 * Funktionen explizit einmal auf) - setzt dafuer $GLOBALS['selbstheilung_erzwingen'],
 * damit genau dieser eine Aufruf auch bei DB_SELBSTHEILUNG_AKTIV=false
 * tatsaechlich prueft/anlegt, statt sich selbst wegen der Konstante zu
 * uebergehen.
 */
function selbstheilung_aktiv(): bool {
    if (!empty($GLOBALS['selbstheilung_erzwingen'])) {
        return true;
    }
    return !defined('DB_SELBSTHEILUNG_AKTIV') || DB_SELBSTHEILUNG_AKTIV;
}

/**
 * Ermittelt das aktuell aktive MV-Jahr (das juengste ECHTE VERA-Jahr in
 * mvtexte, siehe BEAM_PROJEKT_ID_START - BEAM-Projekte zählen hier nicht
 * mit). Spaeter ueber "Einstellungen" umschaltbar (z.B. um ein Vorjahr
 * anzusehen).
 *
 * CAST(jahr AS UNSIGNED) statt eines rohen Spaltenvergleichs: mvtexte ist
 * eine aus dem alten System uebernommene, bereits bestehende Tabelle (siehe
 * mvtexte_spalten_sicherstellen() - CREATE TABLE IF NOT EXISTS aendert eine
 * vorhandene Tabelle nie) und dort steht jahr als VARCHAR, nicht als INT -
 * das alte Skript braucht das offenbar weiterhin so (vergleicht vermutlich
 * den Ruecgabewert typsensitiv). Ein gebundener Integer-Parameter gegen
 * eine VARCHAR-Spalte wird von MySQL/MariaDB beim Vergleich nicht
 * zuverlaessig numerisch behandelt (anders als ein fest im SQL-Text
 * stehender Literalwert) - das CAST erzwingt die numerische Auswertung
 * unabhaengig vom tatsaechlichen Spaltentyp, ohne die Spalte selbst
 * anzufassen.
 */
function get_aktuelles_jahr(PDO $pdo): int {
    mvtexte_spalten_sicherstellen($pdo); // stellt bei einer komplett neuen Installation ueberhaupt erst die Tabelle sicher
    $stmt = $pdo->prepare('SELECT MAX(CAST(jahr AS UNSIGNED)) AS j FROM mvtexte WHERE CAST(jahr AS UNSIGNED) < ?');
    $stmt->execute([BEAM_PROJEKT_ID_START]);
    $row = $stmt->fetch();
    return $row && $row['j'] ? (int)$row['j'] : (int)date('Y');
}

/**
 * Das Jahr, das NICHT privilegierte Besucher:innen ohne explizite
 * Jahresauswahl zu sehen bekommen: das juengste FREIGEGEBENE Jahr (siehe
 * Spalte freigegeben, mvtexte_spalten_sicherstellen()), waehrend
 * Admin/Redaktion (siehe ist_redaktion_im_neuesten_jahr() in
 * mitglieder_functions.php) weiterhin get_aktuelles_jahr() sehen, auch wenn
 * dessen neuestes Jahr noch nicht freigegeben ist - so kann die Redaktion
 * ein neues MV-Jahr in Ruhe vorbereiten, bevor es ueber "Jahr freigeben"
 * (siehe jahr_freigeben() in jahr_functions.php) fuer alle sichtbar wird.
 * Faellt auf get_aktuelles_jahr() zurueck, falls aktuell gar kein Jahr als
 * freigegeben markiert ist (sollte durch den DEFAULT 1 beim Nachruesten der
 * Spalte praktisch nie vorkommen, ausser alle Jahre wurden bewusst
 * gesperrt).
 */
function get_sichtbares_jahr(PDO $pdo, bool $privilegiert): int {
    if ($privilegiert) {
        return get_aktuelles_jahr($pdo);
    }
    mvtexte_spalten_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT MAX(CAST(jahr AS UNSIGNED)) AS j FROM mvtexte WHERE CAST(jahr AS UNSIGNED) < ? AND freigegeben = 1');
    $stmt->execute([BEAM_PROJEKT_ID_START]);
    $row = $stmt->fetch();
    return $row && $row['j'] ? (int)$row['j'] : get_aktuelles_jahr($pdo);
}

/** Ob $jahr fuer nicht privilegierte Besucher:innen freigegeben ist (siehe get_sichtbares_jahr()). */
function jahr_ist_freigegeben(PDO $pdo, int $jahr): bool {
    mvtexte_spalten_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT freigegeben FROM mvtexte WHERE CAST(jahr AS UNSIGNED) = ?');
    $stmt->execute([$jahr]);
    $row = $stmt->fetch();
    return $row === false || (int)$row['freigegeben'] === 1; // unbekanntes Jahr: nicht blockieren, andere Pruefungen greifen ohnehin
}

/**
 * Ergaenzt in mvtexte die von der Neukonzeption zusaetzlich genutzten
 * Spalten - rein additiv per ADD COLUMN IF NOT EXISTS, genau wie bei
 * unterst_tabelle_sicherstellen()/votum_tabelle_sicherstellen(), damit
 * keine manuelle Migration noetig ist. Legt dafuer zunaechst (genau wie
 * diese) die Basistabelle selbst per CREATE TABLE IF NOT EXISTS an, falls
 * sie noch gar nicht existiert - bei Mensa (Bestandssystem) ist das immer
 * ein No-Op, da die Tabelle dort laengst existiert; bei einer komplett
 * neuen Installation ohne Bestandssystem entsteht sie hier zum allerersten
 * Mal, mit denselben Spalten wie im Bestandssystem (jahr/mvdatum/mvbeginn/
 * mvort/antragsende/wahllink/wahlinfo).
 *
 * willkommenstext/wahltext/unterlagentext: jahresabhaengige Redaktionstexte.
 * beamer/uhr/timer/vorgabe: schon im Bestandssystem genutzte Spalten fuer
 * die Beamersteuerung (siehe mvbeamer.php/mvifolien.php) - hier zur
 * Sicherheit ebenfalls per Selbstheilung sichergestellt, falls eine
 * Installation sie (noch) nicht hat. beamer_logo ist neu: steuert das
 * Hintergrundlogo auf "besonderen" Folien (Willkommen/Pause/...) -
 * ersetzt den fragilen Farbcode-Trick des Altsystems (Text-Farbe
 * "#0a1f6f" statt "#0a1f6e" als verstecktes Signal) durch ein echtes Flag.
 */
function mvtexte_spalten_sicherstellen(PDO $pdo): void {
    static $geprueft = false;
    if ($geprueft || !selbstheilung_aktiv()) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mvtexte (
            jahr INT PRIMARY KEY,
            mvdatum VARCHAR(10) DEFAULT '',
            mvbeginn VARCHAR(10) DEFAULT '',
            mvort VARCHAR(150) DEFAULT '',
            antragsende VARCHAR(10) DEFAULT '',
            wahllink VARCHAR(255) DEFAULT '',
            wahlinfo VARCHAR(255) DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $spalten = [
        'willkommenstext' => 'TEXT',
        'wahltext'        => 'TEXT',
        'unterlagentext'  => 'TEXT',
        'beamer'          => 'TEXT',
        'uhr'             => "VARCHAR(1) DEFAULT 'n'",
        // Reine Uhrzeit ("H:i:s"), kein Datum - siehe beamer_timer_starten().
        'timer'           => 'VARCHAR(10) NULL',
        'vorgabe'         => 'INT NULL',
        'beamer_logo'     => 'TINYINT(1) DEFAULT 0',
        // Explizites Enddatum fuer Unterstuetzungs-Voten (TT.MM.JJJJ, siehe
        // tab_termine.php) - hat Vorrang vor der berechneten Frist
        // (Antragsschluss + X Wochen), falls gesetzt (siehe
        // unterstuetzungsfrist_stichtag()).
        'unterstuetzungsende' => 'VARCHAR(10) NULL',
        // Welcher Wahlgang (falls einer) aktuell auf dem Beamer gezeigt wird
        // (siehe beamer_inhalt_setzen() in beamer_functions.php) - damit die
        // Abstimmungsmaschine auf der Beamersteuerung weiß, ob "Abstimmung
        // starten" eine Wahl oder eine einfache Ja/Nein/Enthaltung-
        // Abstimmung startet (siehe wahl_functions.php, Phase 6).
        'aktueller_wahlgang_id' => 'INT NULL',
        // Welche ausgewertete Abstimmungs-/Wahlrunde noch als Ergebnis unter
        // dem aktuellen Beamerbild gezeigt werden darf (siehe
        // beamer_inhalt_setzen(): jeder Folienwechsel setzt dies auf NULL
        // zurueck, damit ein altes Ergebnis beim naechsten Antrag/TOP nicht
        // weiter angezeigt bleibt). NULL = kein Ergebnis anzeigen.
        'ergebnis_round_id' => 'INT NULL',
        // Eigener Timer nur fuer die Abstimmungsmaschine (getrennt vom
        // allgemeinen "timer"/"vorgabe" oben, der z. B. fuer Redezeiten/
        // Pausen gilt und NICHT mehr automatisch Abstimmungen beendet) -
        // siehe beamer_vorschau_daten() und tab_beamersteuerung.php. Braucht
        // Minuten UND Sekunden (kurze Abstimmungen koennen z. B. 20 Sekunden
        // dauern), daher "vorgabe_sek" statt nur Minuten wie beim allgemeinen
        // Timer. "auto_beenden": Checkbox, ob Ablauf die laufende Abstimmung
        // automatisch beendet+auswertet, oder nur angezeigt wird.
        'abstimmung_timer' => 'VARCHAR(10) NULL',
        'abstimmung_timer_vorgabe_sek' => 'INT NULL',
        'abstimmung_timer_auto_beenden' => 'TINYINT(1) DEFAULT 0',
        // Der rohe "zeige"-Wert (siehe beamer_zeige_verarbeiten()), mit dem
        // der aktuelle Beamerinhalt gesetzt wurde - rein fuer die
        // Bedienoberflaeche (tab_beamersteuerung.php: welches Akkordion
        // offen bleibt, welcher Link fett erscheint), siehe
        // beamer_inhalt_setzen().
        'beamer_zeige_aktuell' => 'VARCHAR(255) NULL',
        // Der aktuell gezeigte Inhalt wurde per Akklamation statt per
        // foermlicher Abstimmung beschlossen (siehe beamer_akklamation_setzen()) -
        // erscheint anstelle eines Abstimmungsergebnisses unter dem
        // Beamerbild, wird wie ein echtes Ergebnis bei jedem Folienwechsel
        // zurueckgesetzt (siehe beamer_inhalt_setzen()).
        'akklamation' => 'TINYINT(1) DEFAULT 0',
        // Ob normale Mitglieder dieses Jahr überhaupt sehen/wählen können
        // (siehe get_sichtbares_jahr()) - DEFAULT 1, damit bereits
        // bestehende Jahre beim Nachruesten dieser Spalte unveraendert
        // oeffentlich bleiben. Ein per "Neues Jahr anlegen" (siehe
        // neues_jahr_anlegen()) frisch erzeugtes Jahr startet dagegen
        // bewusst mit 0 (dort ausdruecklich im INSERT gesetzt, ueberschreibt
        // diesen Tabellen-Default) - Admin/Redaktion sollen es erst in Ruhe
        // vorbereiten koennen, bevor es ueber "Jahr freigeben" fuer alle
        // sichtbar wird (siehe jahr_freigeben() in jahr_functions.php).
        'freigegeben' => 'TINYINT(1) DEFAULT 1',
    ];
    foreach ($spalten as $spalte => $typ) {
        if (spalte_existiert($pdo, 'mvtexte', $spalte)) {
            continue;
        }
        try {
            $pdo->exec("ALTER TABLE mvtexte ADD COLUMN `$spalte` $typ");
        } catch (PDOException $e) {
            // unkritisch, falls die Spalte zwischenzeitlich (Race) doch schon existiert.
        }
    }
    $geprueft = true;
}

/**
 * Laedt die Sitzungs-/Vereinsdaten fuer ein Jahr aus mvtexte.
 * Gibt null zurueck, wenn fuer das Jahr noch kein Datensatz existiert.
 */
function get_mvtexte(PDO $pdo, int $jahr): ?array {
    mvtexte_spalten_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT * FROM mvtexte WHERE jahr = ? LIMIT 1');
    $stmt->execute([$jahr]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Juengeres Jahr < $jahr mit eigenem mvtexte-Datensatz, oder null falls keins existiert. */
function vorjahr_von(PDO $pdo, int $jahr): ?int {
    $stmt = $pdo->prepare('SELECT MAX(jahr) AS j FROM mvtexte WHERE jahr < ?');
    $stmt->execute([$jahr]);
    $row = $stmt->fetch();
    return $row && $row['j'] !== null ? (int)$row['j'] : null;
}
