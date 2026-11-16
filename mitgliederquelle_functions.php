<?php
/**
 * mitgliederquelle_functions.php - Austauschbare Quelle fuer Stammdaten
 * (Vorname/Name/Mail) neu anzulegender Mitglieder, gesteuert ueber die
 * Vereinsvorgabe "mitglieder_quelle" (siehe db_functions.php: MV_CONFIG_DEFAULTS,
 * Default 'ldap' - bestehende Mensa-Installationen aendern ihr Verhalten
 * dadurch nicht).
 *
 * 'ldap'      - wie bisher: Direktabfrage des Mensa-LDAP (siehe
 *               mitglieder_functions.php: ldapident_neu(), unveraendert).
 * 'tabelle'   - lokale Tabelle mitglieder_stamm fuer Vereine ohne eigenes
 *               LDAP: entweder mit Muster-/Testdaten gestartet oder per
 *               CSV-Import mit echten Daten befuellt (siehe
 *               tab_mitgliederstamm.php, nur sichtbar, wenn diese Quelle
 *               aktiv ist).
 * 'svmembers' - fuer Installationen, die sich eine Datenbank mit der
 *               Sitzungsverwaltung (KONA) und/oder Mitgliederverwaltung
 *               (MISS) teilen: liest die dort bereits gefuehrte Tabelle
 *               svmembers direkt mit, statt eigene Stammdaten zu pflegen.
 *               Rein lesender Zugriff - VERA legt diese Tabelle nie an und
 *               schreibt nie in sie; fehlt sie (z.B. VERA-Solo-Betrieb ohne
 *               KONA/MISS), verhaelt sich die Abfrage wie bei 'tabelle'
 *               ohne Treffer: leere Werte statt Absturz.
 */

require_once __DIR__ . '/db_functions.php'; // fuer spalte_existiert()

/** Legt mitglieder_stamm bei Bedarf an und fuellt sie beim allerersten Mal mit Musterdaten. */
function mitgliederstamm_tabelle_sicherstellen(PDO $pdo): void {
    static $geprueft = false;
    if ($geprueft || !selbstheilung_aktiv()) {
        return;
    }
    $stmt = $pdo->query("SHOW TABLES LIKE 'mitglieder_stamm'");
    $existiert_schon = $stmt->fetch() !== false;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mitglieder_stamm (
            MNr VARCHAR(12) PRIMARY KEY,
            Vorname VARCHAR(100) DEFAULT '',
            Name VARCHAR(100) DEFAULT '',
            Mail VARCHAR(150) DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!$existiert_schon) {
        // Muster-Mitgliederdatenbank: zeigt das erwartete Format und laesst
        // sich sofort ausprobieren - echte Daten ersetzen das per CSV-Import
        // oder manuellem Bearbeiten/Loeschen (siehe tab_mitgliederstamm.php).
        $insert = $pdo->prepare('INSERT INTO mitglieder_stamm (MNr, Vorname, Name, Mail) VALUES (?, ?, ?, ?)');
        foreach ([
            ['muster1', 'Erika', 'Musterfrau', 'erika.musterfrau@beispiel.de'],
            ['muster2', 'Max', 'Mustermann', 'max.mustermann@beispiel.de'],
        ] as $zeile) {
            $insert->execute($zeile);
        }
    }
    $geprueft = true;
}

/** Alle Eintraege der lokalen Mitgliederstamm-Tabelle, alphabetisch sortiert. */
function get_mitgliederstamm_liste(PDO $pdo): array {
    mitgliederstamm_tabelle_sicherstellen($pdo);
    return $pdo->query('SELECT * FROM mitglieder_stamm ORDER BY Name, Vorname')->fetchAll();
}

/** Legt einen Eintrag an oder aktualisiert ihn (MNr als Schluessel). */
function mitgliederstamm_speichern(PDO $pdo, string $mnr, string $vorname, string $name, string $mail): void {
    mitgliederstamm_tabelle_sicherstellen($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO mitglieder_stamm (MNr, Vorname, Name, Mail) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE Vorname = VALUES(Vorname), Name = VALUES(Name), Mail = VALUES(Mail)'
    );
    $stmt->execute([$mnr, $vorname, $name, $mail]);
}

function mitgliederstamm_loeschen(PDO $pdo, string $mnr): void {
    mitgliederstamm_tabelle_sicherstellen($pdo);
    $pdo->prepare('DELETE FROM mitglieder_stamm WHERE MNr = ?')->execute([$mnr]);
}

/**
 * Importiert Zeilen im Format "MNr;Vorname;Name;Mail" (eine pro Zeile,
 * optionale Kopfzeile wird erkannt und uebersprungen). Bestehende
 * Mitgliedsnummern werden ueberschrieben (siehe mitgliederstamm_speichern()).
 * Gibt die Anzahl tatsaechlich importierter Zeilen zurueck.
 */
function mitgliederstamm_csv_importieren(PDO $pdo, string $inhalt): int {
    mitgliederstamm_tabelle_sicherstellen($pdo);
    $anzahl = 0;
    foreach (preg_split('/\r\n|\r|\n/', trim($inhalt)) as $zeile) {
        $zeile = trim($zeile);
        if ($zeile === '') {
            continue;
        }
        $teile = array_map('trim', explode(';', $zeile));
        [$mnr, $vorname, $name, $mail] = array_pad($teile, 4, '');
        if ($mnr === '' || strcasecmp($mnr, 'MNr') === 0) {
            continue; // leere Zeile oder erkannte Kopfzeile
        }
        mitgliederstamm_speichern($pdo, $mnr, $vorname, $name, $mail);
        $anzahl++;
    }
    return $anzahl;
}

/**
 * Liest einen Eintrag direkt aus der svmembers-Tabelle der Sitzungs-
 * verwaltung (KONA) / Mitgliederverwaltung (MISS), wenn VERA sich deren
 * Datenbank teilt. Rein lesend - VERA legt svmembers nie an. Existiert die
 * Tabelle in dieser Installation nicht (VERA-Solo-Betrieb trotz falscher
 * Vorgabe) oder ist die MNr dort unbekannt, werden leere Werte
 * zurueckgegeben statt eines Absturzes.
 */
function mitgliederquelle_svmembers_abfragen(PDO $pdo, string $mnr): array {
    $leer = ['vorname' => '', 'name' => '', 'mail' => ''];
    try {
        $stmt = $pdo->prepare(
            'SELECT first_name, last_name, email FROM svmembers WHERE membership_number = ?'
        );
        $stmt->execute([$mnr]);
    } catch (PDOException $e) {
        return $leer; // svmembers existiert in dieser Installation nicht
    }
    $row = $stmt->fetch();
    if (!$row) {
        return $leer;
    }
    return [
        'vorname' => $row['first_name'] ?? '',
        'name' => $row['last_name'] ?? '',
        'mail' => $row['email'] ?? '',
    ];
}

/**
 * Mitgliedsnummer zu einer svmembers.member_id - fuer die familienweite
 * Sitzung (siehe get_aktuelle_mnr() in functions_neu.php): MISS/KONA/KAND
 * schreiben beim eigenen Login $_SESSION['member_id'] (dieselbe Zahl,
 * dasselbe Sitzungs-Cookie, siehe session_config.php), VERA braucht daraus
 * aber die Mitgliedsnummer (membership_number), nicht die interne ID.
 * Gleiche Fehlertoleranz wie mitgliederquelle_svmembers_abfragen(): null
 * statt Absturz, wenn svmembers in dieser Installation nicht existiert oder
 * die ID dort unbekannt ist.
 */
function svmembers_mnr_von_id(PDO $pdo, int $memberId): ?string {
    try {
        $stmt = $pdo->prepare('SELECT membership_number FROM svmembers WHERE member_id = ?');
        $stmt->execute([$memberId]);
    } catch (PDOException $e) {
        return null; // svmembers existiert in dieser Installation nicht
    }
    $row = $stmt->fetch();
    return $row ? (string)$row['membership_number'] : null;
}

/**
 * Kehrfunktion zu svmembers_mnr_von_id(): die svmembers.member_id zu einer
 * Mitgliedsnummer - für BEAM (siehe neu/beam/beam_functions.php), das die
 * Rechteprüfung unabhängig davon braucht, über welchen der drei bei VERA
 * möglichen Wege (REMOTE_USER, VERAs eigenes login.php, familienweite
 * Sitzung über $_SESSION['member_id'] - siehe get_aktuelle_mnr()) die
 * Anmeldung tatsächlich zustande kam. Gleiche Fehlertoleranz wie dort: null
 * statt Absturz, wenn svmembers nicht existiert oder die Nummer dort
 * unbekannt ist.
 */
function svmembers_id_von_mnr(PDO $pdo, string $mnr): ?int {
    try {
        $stmt = $pdo->prepare('SELECT member_id FROM svmembers WHERE membership_number = ?');
        $stmt->execute([$mnr]);
    } catch (PDOException $e) {
        return null; // svmembers existiert in dieser Installation nicht
    }
    $row = $stmt->fetch();
    return $row ? (int)$row['member_id'] : null;
}

/**
 * Gegenstueck zum Lesen in get_aktuelle_mnr(): spiegelt eine bei VERA
 * zustande gekommene Anmeldung (eigenes login.php - Demo-Klick oder
 * Magic-Link) in die familienweite Sitzungsvariable $_SESSION['member_id'],
 * damit MISS/KONA/KAND dieselbe Person ebenfalls erkennen. Bisher war das
 * eine Einbahnstrasse (VERA las member_id, schrieb es aber nie) - wer sich
 * zuerst in VERA anmeldete, blieb den Schwesteranwendungen unbekannt.
 * Nur wirksam, wenn mitglieder_quelle tatsaechlich 'svmembers' ist (sonst
 * gibt es keine gemeinsame Tabelle, in der eine ID nachschlagbar waere).
 */
function familiensession_mnr_spiegeln(PDO $pdo, array $config, string $mnr): void {
    if (($config['mitglieder_quelle'] ?? '') !== 'svmembers') {
        return;
    }
    $id = svmembers_id_von_mnr($pdo, $mnr);
    if ($id !== null) {
        $_SESSION['member_id'] = $id;
    }
}

/**
 * Telefonnummer zu einer Mitgliedsnummer - fuer die elektronische
 * Visitenkarte (siehe ausweis_functions.php). Anders als
 * first_name/last_name/email (siehe mitgliederquelle_svmembers_abfragen())
 * steht die Telefonnummer nicht in svmembers, sondern in MISS' eigener
 * Kontakttabelle md_contacts (Kind 'phone'/'mobile'), mit je Mitglied und
 * Feld einstellbarer Sichtbarkeit (md_visibility/md_visibility_defaults,
 * Feldschluessel 'contact.phone'/'contact.mobile', MISS-Standard 'me' -
 * nur fuer das Mitglied selbst sichtbar, nicht einmal im MISS-eigenen
 * Mitgliederverzeichnis). VERA gibt die Nummer nur heraus, wenn diese
 * Sichtbarkeit auf 'all' steht - alles andere waere eine Nummer, die das
 * Mitglied bewusst nicht einmal dort fuer alle freigegeben hat; 'phone'
 * hat Vorrang vor 'mobile', falls beide hinterlegt und sichtbar sind.
 * Leerer String statt Absturz, wenn eine der Tabellen fehlt (kein
 * MISS/aeltere Version), die MNr dort unbekannt ist, keine Nummer
 * hinterlegt ist oder keine davon auf 'all' sichtbar ist.
 */
function svmembers_telefon_von_mnr(PDO $pdo, string $mnr): string {
    $memberId = svmembers_id_von_mnr($pdo, $mnr);
    if ($memberId === null) {
        return '';
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT kind, value FROM md_contacts WHERE member_id = ? AND kind IN ('phone', 'mobile')"
        );
        $stmt->execute([$memberId]);
        $kontakte = $stmt->fetchAll();
    } catch (PDOException $e) {
        return ''; // md_contacts existiert in dieser Installation nicht
    }
    if (!$kontakte) {
        return '';
    }
    $sichtbarkeit = [];
    try {
        foreach ($pdo->query(
            "SELECT field_key, level FROM md_visibility_defaults WHERE field_key IN ('contact.phone', 'contact.mobile')"
        ) as $r) {
            $sichtbarkeit[$r['field_key']] = $r['level'];
        }
        $stmt = $pdo->prepare(
            "SELECT field_key, level FROM md_visibility WHERE member_id = ? AND field_key IN ('contact.phone', 'contact.mobile')"
        );
        $stmt->execute([$memberId]);
        foreach ($stmt->fetchAll() as $r) {
            $sichtbarkeit[$r['field_key']] = $r['level']; // Mitglied-eigene Einstellung ueberschreibt die Vorgabe
        }
    } catch (PDOException $e) {
        // md_visibility fehlt (aeltere MISS-Version) - $sichtbarkeit bleibt leer,
        // der Standard 'me' unten greift dann ueberall: sicherheitshalber nie anzeigen.
    }
    foreach (['phone', 'mobile'] as $kind) {
        foreach ($kontakte as $k) {
            if ($k['kind'] !== $kind) {
                continue;
            }
            if (($sichtbarkeit["contact.$kind"] ?? 'me') === 'all') {
                return $k['value'];
            }
        }
    }
    return '';
}

/**
 * Sieben moegliche Werte der Spalte svmembers.status (siehe
 * Admin-Installationsanleitung.md, Abschnitt 10e) - MISS pflegt diese
 * Spalte samt Stichtag-/Beitrags-/Alters-Automatik, VERA liest sie nur.
 */
const SVMEMBERS_STATUS_STIMMBERECHTIGT = 'Stimmberechtigt';
const SVMEMBERS_STATUS_TEILNAHMEBERECHTIGT_OHNE_STIMMRECHT = 'Teilnahmeberechtigt (ohne Stimmrecht)';

/**
 * Mitgliedschaftsstatus zu einer Mitgliedsnummer - fuer die
 * Einlasskontrolle (siehe ausweis_pruefen.php). null bedeutet "nicht
 * ermittelbar" (Tabelle/Spalte fehlt noch, da MISS dies noch nicht
 * liefert, oder die MNr ist dort unbekannt) - ausdruecklich NICHT
 * gleichbedeutend mit einem der sieben echten Statuswerte, damit
 * ausweis_pruefen.php "unbekannt" von "gesperrt" unterscheiden kann.
 */
function svmembers_status_von_mnr(PDO $pdo, string $mnr): ?string {
    try {
        $stmt = $pdo->prepare('SELECT status FROM svmembers WHERE membership_number = ?');
        $stmt->execute([$mnr]);
    } catch (PDOException $e) {
        return null;
    }
    $row = $stmt->fetch();
    if ($row === false || $row['status'] === null || $row['status'] === '') {
        return null;
    }
    return (string)$row['status'];
}

/** Alle Mitglieder aus svmembers, mit denselben Spalten wie mitgliederquelle_svmembers_abfragen(). */
function svmembers_alle_laden(PDO $pdo): array {
    try {
        return $pdo->query(
            'SELECT membership_number, first_name, last_name, email FROM svmembers ORDER BY last_name, first_name'
        )->fetchAll();
    } catch (PDOException $e) {
        return []; // svmembers existiert in dieser Installation nicht
    }
}

/**
 * Liste moeglicher Demo-Logins fuer die aktuell aktive Mitgliederquelle,
 * einheitlich als [mnr, vorname, name] - genutzt von login.php, wenn die
 * Vereinsvorgabe demo_modus_aktiv gesetzt ist (siehe dort). Bei 'ldap'
 * gibt es keine sinnvoll auflistbare Mitgliederliste, daher leer.
 */
function demo_mitgliederliste_laden(PDO $pdo, array $config): array {
    $quelle = $config['mitglieder_quelle'] ?? 'ldap';
    if ($quelle === 'tabelle') {
        return array_map(
            fn($m) => ['mnr' => $m['MNr'], 'vorname' => $m['Vorname'], 'name' => $m['Name']],
            get_mitgliederstamm_liste($pdo)
        );
    }
    if ($quelle === 'svmembers') {
        return array_map(
            fn($m) => ['mnr' => $m['membership_number'], 'vorname' => $m['first_name'], 'name' => $m['last_name']],
            svmembers_alle_laden($pdo)
        );
    }
    return [];
}

/**
 * Fragt Vorname/Name/Mail zu einer Mitgliedsnummer ab - ueber die per
 * Vereinsvorgabe gewaehlte Quelle (siehe oben). Liefert bei 'tabelle' und
 * 'svmembers' leere Werte zurueck, wenn die MNr dort (noch) nicht
 * hinterlegt ist, genau wie ldapident_neu() das bei einem LDAP-Fehlschlag
 * bereits tut - eine Registrierung bricht dadurch nie hart ab.
 */
function mitgliederquelle_abfragen(PDO $pdo, string $mnr, array $config): array {
    $leer = ['vorname' => '', 'name' => '', 'mail' => ''];
    $quelle = $config['mitglieder_quelle'] ?? 'ldap';
    if ($quelle === 'tabelle') {
        mitgliederstamm_tabelle_sicherstellen($pdo);
        $stmt = $pdo->prepare('SELECT Vorname, Name, Mail FROM mitglieder_stamm WHERE MNr = ?');
        $stmt->execute([$mnr]);
        $row = $stmt->fetch();
        if (!$row) {
            return $leer;
        }
        return ['vorname' => $row['Vorname'] ?? '', 'name' => $row['Name'] ?? '', 'mail' => $row['Mail'] ?? ''];
    }
    if ($quelle === 'svmembers') {
        return mitgliederquelle_svmembers_abfragen($pdo, $mnr);
    }
    return ldapident_neu($mnr);
}
