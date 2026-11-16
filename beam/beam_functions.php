<?php
/**
 * beam_functions.php - Datenmodell und Rechteprüfung für BEAM, die
 * Standalone-Variante von VERAs Beamersteuerung + Abstimmungsmaschine
 * (siehe dokumentation/BEAM.md für die Gesamtplanung). Nutzt dieselbe
 * Datenbank wie VERA und bindet dessen gemeinsame Engine (Abstimmungs-
 * maschine, Beamerbild-Rendering, mvtexte-Beamerstatus) über relative
 * require_once ein - keine eigene Konfiguration, keine eigene
 * Sitzungsverwaltung, kein eigenes Login.
 */

require_once __DIR__ . '/../functions_neu.php';
require_once __DIR__ . '/../abstimmung_functions.php';
require_once __DIR__ . '/../wahl_functions.php';
require_once __DIR__ . '/../beamer_functions.php';
require_once __DIR__ . '/../protokoll_functions.php';

/** Bis zu so viele vorbereitete Beamer-Inhalte je Projekt (siehe dokumentation/BEAM.md). */
const BEAM_MAX_INHALTE = 25;

/**
 * Legt beam_projekte/beam_inhalte an, falls sie noch nicht existieren
 * (self-healing, gleiches Muster wie spalte_existiert()/CREATE TABLE IF
 * NOT EXISTS in db_functions.php).
 *
 * beam_projekte.id dient direkt als der generalisierte Kontextwert ("jahr"),
 * den die gemeinsame Abstimmungsmaschine-Engine erwartet (siehe
 * abstimmung_functions.php, BEAM-Vereinheitlichung) - und genauso als
 * Schlüssel in mvtexte (siehe beam_projekt_anlegen()), wo der Beamerstatus
 * eines Projekts liegt. Damit das garantiert nie mit einem VERA-Kalenderjahr
 * (grob 2020-2099) kollidiert, startet der AUTO_INCREMENT-Zähler bei
 * 1.000.000 - einmalig angehoben, nie wieder herabgesetzt.
 */
function beam_tabellen_sicherstellen(PDO $pdo): void {
    static $geprueft = false;
    if ($geprueft || !selbstheilung_aktiv()) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS beam_projekte (
            id INT AUTO_INCREMENT PRIMARY KEY,
            titel VARCHAR(255) NOT NULL,
            ersteller_member_id INT NOT NULL,
            erstellt_am DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $stmt = $pdo->prepare(
        'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute(['beam_projekte']);
    $naechster = (int)$stmt->fetchColumn();
    if ($naechster < BEAM_PROJEKT_ID_START) {
        $pdo->exec('ALTER TABLE beam_projekte AUTO_INCREMENT = ' . BEAM_PROJEKT_ID_START);
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS beam_inhalte (
            id INT AUTO_INCREMENT PRIMARY KEY,
            projekt_id INT NOT NULL,
            position INT NOT NULL,
            typ VARCHAR(10) NOT NULL,
            text MEDIUMTEXT DEFAULT NULL,
            frage_bezeichnung VARCHAR(255) DEFAULT NULL,
            frage_modus VARCHAR(2) DEFAULT NULL,
            frage_anzahl_aemter INT DEFAULT NULL,
            frage_kandidaten MEDIUMTEXT DEFAULT NULL,
            UNIQUE KEY idx_projekt_position (projekt_id, position)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $geprueft = true;
}

/**
 * Rechte der aktuell angemeldeten Person für BEAM - KEIN eigenes Login:
 * $_SESSION['member_id'] (svmembers.member_id) wird von den
 * Geschwisteranwendungen (Adapter/KISS/MISS/KONA) bei deren eigenem Login
 * gesetzt, dank domainweitem Session-Cookie (siehe session_config.php,
 * familienweite Sitzung) hier direkt lesbar - BEAM braucht dafür keine
 * eigene Anmeldeseite. Ist diese familienweite Sitzung (noch) nicht
 * gesetzt, genügt aber auch eine bereits bestehende VERA-eigene Anmeldung
 * (REMOTE_USER oder VERAs login.php, siehe get_aktuelle_mnr() in
 * functions_neu.php) - die zugehörige svmembers.member_id wird dann über
 * die Mitgliedsnummer nachgeschlagen (svmembers_id_von_mnr()). So greift
 * die Rechteprüfung unabhängig davon, über welchen der VERA-Logins die
 * Anmeldung tatsächlich zustande kam.
 *
 * Berechtigt, die Steuerung zu bedienen, ist, wer `aktiv > 13` hat
 * (Funktionsstufe, siehe RECHTE.md in der Mitgliederverwaltung: RL/VA ab
 * 14/17, GF 18, Vorstand 19) oder `is_admin` gesetzt hat ("darf immer
 * alles, damit sich niemand aussperren kann").
 */
function beam_aktuelle_rechte(PDO $pdo): array {
    $leer = ['eingeloggt' => false, 'member_id' => 0, 'name' => '', 'aktiv' => 0, 'is_admin' => false, 'darf_steuern' => false];
    $memberId = (int)($_SESSION['member_id'] ?? 0);
    if ($memberId <= 0) {
        $mnr = get_aktuelle_mnr($pdo);
        $memberId = $mnr !== '' ? (int)(svmembers_id_von_mnr($pdo, $mnr) ?? 0) : 0;
    }
    if ($memberId <= 0) {
        return $leer;
    }
    try {
        $stmt = $pdo->prepare('SELECT member_id, first_name, last_name, aktiv, is_admin FROM svmembers WHERE member_id = ?');
        $stmt->execute([$memberId]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        return $leer; // svmembers existiert in dieser Installation nicht
    }
    if (!$row) {
        return $leer;
    }
    $aktiv = (int)$row['aktiv'];
    $istAdmin = (bool)$row['is_admin'];
    return [
        'eingeloggt' => true,
        'member_id' => (int)$row['member_id'],
        'name' => trim($row['first_name'] . ' ' . $row['last_name']),
        'aktiv' => $aktiv,
        'is_admin' => $istAdmin,
        'darf_steuern' => $aktiv > 13 || $istAdmin,
    ];
}

/**
 * Legt ein neues Projekt an und gleich die dafür nötige mvtexte-Zeile
 * (gleicher Mechanismus wie bei einem neuen VERA-Jahr, siehe
 * neues_jahr_anlegen() in jahr_functions.php - hier bewusst nur dieser eine
 * INSERT, ohne die dortige VERA-spezifische Übernahme von Vorjahresdaten) -
 * ohne sie würde jeder spätere beamer_inhalt_setzen() fehlschlagen (UPDATE
 * auf eine nicht existierende Zeile). Gibt [id|null, Fehler] zurück.
 */
function beam_projekt_anlegen(PDO $pdo, int $memberId, string $titel): array {
    beam_tabellen_sicherstellen($pdo);
    $titel = trim($titel);
    if ($titel === '') {
        return [null, 'Bitte einen Titel angeben.'];
    }
    $pdo->prepare('INSERT INTO beam_projekte (titel, ersteller_member_id, erstellt_am) VALUES (?, ?, NOW())')
        ->execute([$titel, $memberId]);
    $id = (int)$pdo->lastInsertId();
    mvtexte_spalten_sicherstellen($pdo);
    $pdo->prepare('INSERT INTO mvtexte (jahr) VALUES (?)')->execute([$id]);
    return [$id, ''];
}

function beam_projekt_get(PDO $pdo, int $projektId): ?array {
    beam_tabellen_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT * FROM beam_projekte WHERE id = ?');
    $stmt->execute([$projektId]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/** Projekte, die diese Person sehen darf: eigene, bei Admin-Rechten alle (siehe dokumentation/BEAM.md: "stets user-gebunden"). */
function beam_projekte_liste(PDO $pdo, array $rechte): array {
    beam_tabellen_sicherstellen($pdo);
    if ($rechte['is_admin']) {
        return $pdo->query('SELECT * FROM beam_projekte ORDER BY erstellt_am DESC')->fetchAll();
    }
    $stmt = $pdo->prepare('SELECT * FROM beam_projekte WHERE ersteller_member_id = ? ORDER BY erstellt_am DESC');
    $stmt->execute([$rechte['member_id']]);
    return $stmt->fetchAll();
}

/** Darf diese Person dieses Projekt steuern/einsehen? Nur der Ersteller selbst oder eine Person mit Admin-Rechten. */
function beam_projekt_berechtigt(array $projekt, array $rechte): bool {
    return $rechte['is_admin'] || (int)$projekt['ersteller_member_id'] === (int)$rechte['member_id'];
}

/** Alle vorbereiteten Inhalte eines Projekts, nach Position sortiert. */
function beam_inhalte_liste(PDO $pdo, int $projektId): array {
    beam_tabellen_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT * FROM beam_inhalte WHERE projekt_id = ? ORDER BY position');
    $stmt->execute([$projektId]);
    return $stmt->fetchAll();
}

function beam_inhalt_get(PDO $pdo, int $projektId, int $position): ?array {
    beam_tabellen_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT * FROM beam_inhalte WHERE projekt_id = ? AND position = ?');
    $stmt->execute([$projektId, $position]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/** Speichert einen reinen Text-Inhalt an dieser Position (1..BEAM_MAX_INHALTE) - ersetzt einen vorhandenen Inhalt derselben Position, auch wenn der vorher eine Frage war. Gibt im Fehlerfall einen Text zurück, sonst ''. */
function beam_inhalt_text_speichern(PDO $pdo, int $projektId, int $position, string $text): string {
    beam_tabellen_sicherstellen($pdo);
    if ($position < 1 || $position > BEAM_MAX_INHALTE) {
        return 'Ungültige Position.';
    }
    if (trim($text) === '') {
        return 'Bitte einen Text angeben.';
    }
    $pdo->prepare(
        'INSERT INTO beam_inhalte (projekt_id, position, typ, text) VALUES (?, ?, "text", ?)
         ON DUPLICATE KEY UPDATE typ = "text", text = VALUES(text),
             frage_bezeichnung = NULL, frage_modus = NULL, frage_anzahl_aemter = NULL, frage_kandidaten = NULL'
    )->execute([$projektId, $position, trim($text)]);
    return '';
}

/**
 * Speichert eine vorbereitete Abstimmungsfrage (Modus A/B/C/P, siehe
 * WAHL_MODI in wahl_functions.php) an dieser Position - wird erst beim
 * tatsächlichen Abruf in der Versammlung zu einem echten Wahlgang
 * (siehe dokumentation/BEAM.md, Phase 3: steuerung.php legt ihn dann über
 * wahlgang_anlegen() an). Gibt im Fehlerfall einen Text zurück, sonst ''.
 */
function beam_inhalt_frage_speichern(PDO $pdo, int $projektId, int $position, string $bezeichnung, string $modus, ?int $anzahlAemter, array $kandidatenNamen): string {
    beam_tabellen_sicherstellen($pdo);
    if ($position < 1 || $position > BEAM_MAX_INHALTE) {
        return 'Ungültige Position.';
    }
    $bezeichnung = trim($bezeichnung);
    if ($bezeichnung === '') {
        return 'Bitte eine Bezeichnung angeben.';
    }
    if (!in_array($modus, WAHL_MODI, true)) {
        return 'Ungültiger Modus.';
    }
    if ($modus === 'B' || $modus === 'P') {
        $anzahlAemter = null;
    }
    $pdo->prepare(
        'INSERT INTO beam_inhalte (projekt_id, position, typ, frage_bezeichnung, frage_modus, frage_anzahl_aemter, frage_kandidaten)
             VALUES (?, ?, "frage", ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE typ = "frage", text = NULL,
             frage_bezeichnung = VALUES(frage_bezeichnung), frage_modus = VALUES(frage_modus),
             frage_anzahl_aemter = VALUES(frage_anzahl_aemter), frage_kandidaten = VALUES(frage_kandidaten)'
    )->execute([$projektId, $position, $bezeichnung, $modus, $anzahlAemter, json_encode(array_values($kandidatenNamen))]);
    return '';
}

function beam_inhalt_loeschen(PDO $pdo, int $projektId, int $position): void {
    beam_tabellen_sicherstellen($pdo);
    $pdo->prepare('DELETE FROM beam_inhalte WHERE projekt_id = ? AND position = ?')->execute([$projektId, $position]);
}

/**
 * Zeigt einen vorbereiteten Inhalt (Position 1..BEAM_MAX_INHALTE) auf dem
 * Beamer an. Bei einer vorbereiteten Abstimmungsfrage wird dafür jetzt erst
 * ein echter Wahlgang angelegt (wahlgang_anlegen(), art='abstimmung',
 * jahr=Projekt-ID - dieselbe Funktion wie bei VERAs Ad-hoc-Abstimmungsfragen,
 * siehe process_beamersteuerung.php), damit sich die Abstimmungsmaschine
 * danach wie gewohnt starten/beenden/auswerten lässt; jeder Abruf legt dabei
 * einen frischen Wahlgang an, kein Wiederverwenden eines früheren. Gibt im
 * Fehlerfall einen Text zurück, sonst ''.
 */
function beam_inhalt_anzeigen(PDO $pdo, int $projektId, int $position): string {
    $inhalt = beam_inhalt_get($pdo, $projektId, $position);
    if (!$inhalt) {
        return 'Dieser Inhalt existiert nicht (mehr).';
    }
    if ($inhalt['typ'] === 'text') {
        beamer_inhalt_setzen($pdo, $projektId, beamer_rendern_text((string)$inhalt['text']));
        return '';
    }
    $kandidaten = json_decode((string)$inhalt['frage_kandidaten'], true) ?: [];
    [$wahlgangId, $fehler, ] = wahlgang_anlegen(
        $pdo, $projektId, (string)$inhalt['frage_bezeichnung'], (string)$inhalt['frage_modus'],
        $inhalt['frage_anzahl_aemter'] !== null ? (int)$inhalt['frage_anzahl_aemter'] : null,
        $kandidaten, 'abstimmung'
    );
    if ($fehler !== '') {
        return $fehler;
    }
    $html = wahl_rendern_beamer($pdo, $wahlgangId);
    if ($html === null) {
        return 'Wahlgang nicht gefunden.';
    }
    beamer_inhalt_setzen($pdo, $projektId, $html, false, $wahlgangId);
    return '';
}

/**
 * Rekonstruiert die Basis-URL dieses BEAM-Deployments aus der aktuellen
 * Anfrage (Schema+Host+Verzeichnis) - anders als bei VERA
 * (mvconfig.abstimmung_basis_url, eine Vereinsvorgabe) braucht es dafür
 * keine eigene Einstellung: BEAMs Stimmkarten sollen immer auf dasselbe
 * Verzeichnis zeigen, in dem die Steuerung selbst läuft.
 */
/**
 * Protokolliert eine Steuerungsaktion unter mvlogs_<Projekt-ID> - dieselbe
 * self-healing Protokollfunktion wie bei VERA (mv_log() in
 * protokoll_functions.php, dort je MV-Jahr eine Tabelle, hier je Projekt),
 * nur mit einem aus $rechte zusammengesetzten, VERA-untypischen
 * $mitglied-Array statt eines echten Mitgliedsdatensatzes.
 */
function beam_log(PDO $pdo, int $projektId, array $rechte, string $was, string $detail = ''): void {
    mv_log($pdo, $projektId, ['MNr' => (string)$rechte['member_id'], 'Vorname' => $rechte['name'], 'Name' => ''], $was, $detail);
}

function beam_basis_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/beam/steuerung.php'))), '/');
    return $scheme . '://' . $host . $dir;
}
