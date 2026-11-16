<?php
/**
 * wahl_functions.php - Wahlen innerhalb der Abstimmungsmaschine (siehe
 * dokumentation/Abstimmungsmaschine.md, Phase 6). Ergänzt die bisherige
 * Ja/Nein/Enthaltung-Abstimmung (abstimmung_functions.php) um echte Wahlen
 * mit bis zu 15 Kandidat:innen, in drei vereinsspezifisch wählbaren Modi:
 *
 *  A: Jede:r Kandidat:in bekommt einzeln Ja/Nein/Enthaltung - gewählt ist,
 *     wer ein positives Stimmendelta (Ja minus Nein) hat.
 *  B: Jede Stimmkarte hat eine Stimme für eine:n Kandidat:in - gewählt ist,
 *     wer die meisten Stimmen bekommt (genau ein Amt).
 *  C: Jede Stimmkarte hat so viele Stimmen wie Ämter zu besetzen sind
 *     (vereinsspezifisch einstellbar) - gewählt sind die Kandidat:innen mit
 *     den meisten Stimmen.
 *  P: Präferenz - jede Stimmkarte kann beliebig viele der Varianten
 *     ankreuzen (reine Mehrfachauswahl ohne Sitzgrenze). Es gibt keine/n
 *     Gewählte:n, nur eine Rangliste nach Stimmenzahl - gedacht für offene
 *     Abstimmungsfragen mit mehreren Varianten, nicht für echte Wahlen.
 *
 * Über das Feld 'art' unterscheidet ein Wahlgang zusätzlich, ob er eine
 * echte Wahl von Kandidat:innen ist ('wahl', Standard, von der Redaktion
 * unter "Wahlgänge verwalten" vorbereitet) oder eine ad-hoc erzeugte
 * Abstimmungs-Variante mit Auswahl- oder Präferenz-Charakter
 * ('abstimmung', direkt von der Beamersteuerung aus angelegt, siehe
 * tab_beamersteuerung.php) - das steuert nur Wortwahl und Darstellung auf
 * dem Beamer (Variante statt Kandidat:in, "-"-Liste statt Nummerierung),
 * nicht die Auszähllogik.
 *
 * Ein Wahlgang (Bezeichnung, Modus, ggf. Ämterzahl, Kandidat:innenliste)
 * wird im Vorfeld von der Redaktion angelegt (siehe tab_wahlgaenge.php) -
 * genau wie bei den Anträgen/der Tagesordnung. Während der Versammlung wird
 * er wie ein Antrag/TOP über die Beamersteuerung auf den Beamer geholt
 * (beamer_zeige_verarbeiten(): Präfix "wahl:ID") und darüber auch als
 * Abstimmungsrunde gestartet/beendet/ausgewertet - dieselbe Mechanik wie
 * bei der Ja/Nein/Enthaltung-Abstimmung (mv_vote_rounds, jetzt mit
 * optionaler wahlgang_id), nur mit einer eigenen Stimmentabelle
 * (mv_vote_kandidatenstimmen statt mv_vote_ballots), weil pro Stimmkarte
 * mehrere Zeilen (Modus A/C) oder genau eine andere Art von Zeile
 * (Modus B) entstehen können.
 */

require_once __DIR__ . '/abstimmung_functions.php';

const WAHL_MODI = ['A', 'B', 'C', 'P'];
const WAHL_MAX_KANDIDATEN = 15;

// ============================================
// WAHLGANG/KANDIDAT:INNEN - VORKONFIGURATION (ADMIN)
// ============================================

function wahlgaenge_tabellen_sicherstellen(PDO $pdo): void {
    static $geprueft = false;
    if ($geprueft || !selbstheilung_aktiv()) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mv_vote_wahlgaenge (
            id INT AUTO_INCREMENT PRIMARY KEY,
            jahr INT NOT NULL,
            bezeichnung VARCHAR(255) NOT NULL DEFAULT '',
            modus VARCHAR(1) NOT NULL DEFAULT 'B',
            anzahl_aemter INT DEFAULT NULL,
            art VARCHAR(12) NOT NULL DEFAULT 'wahl',
            created_at DATETIME NOT NULL,
            KEY idx_jahr (jahr)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Nachtraeglich ergaenzte Spalte (Phase 6b) - siehe spalte_existiert() in
    // db_functions.php: Installationen, die diese Tabelle schon vor Phase 6b
    // angelegt haben, bekommen sie erst hier ergaenzt.
    if (!spalte_existiert($pdo, 'mv_vote_wahlgaenge', 'art')) {
        try {
            $pdo->exec("ALTER TABLE mv_vote_wahlgaenge ADD COLUMN art VARCHAR(12) NOT NULL DEFAULT 'wahl'");
        } catch (PDOException $e) {
            // unkritisch, falls die Spalte zwischenzeitlich (Race) doch schon existiert.
        }
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mv_vote_kandidaten (
            id INT AUTO_INCREMENT PRIMARY KEY,
            wahlgang_id INT NOT NULL,
            position INT NOT NULL DEFAULT 0,
            name VARCHAR(150) NOT NULL DEFAULT '',
            KEY idx_wahlgang_id (wahlgang_id),
            CONSTRAINT fk_mv_vote_kandidaten_wahlgang FOREIGN KEY (wahlgang_id)
                REFERENCES mv_vote_wahlgaenge(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $geprueft = true;
}

/** Alle Wahlgänge eines Jahres inkl. ihrer Kandidat:innen. */
function get_wahlgaenge(PDO $pdo, int $jahr): array {
    wahlgaenge_tabellen_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT * FROM mv_vote_wahlgaenge WHERE jahr = ? ORDER BY id ASC');
    $stmt->execute([$jahr]);
    $wahlgaenge = $stmt->fetchAll();
    foreach ($wahlgaenge as &$w) {
        $w['kandidaten'] = get_wahlgang_kandidaten($pdo, (int)$w['id']);
    }
    unset($w);
    return $wahlgaenge;
}

function get_wahlgang(PDO $pdo, int $id): ?array {
    wahlgaenge_tabellen_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT * FROM mv_vote_wahlgaenge WHERE id = ?');
    $stmt->execute([$id]);
    $w = $stmt->fetch();
    if (!$w) {
        return null;
    }
    $w['kandidaten'] = get_wahlgang_kandidaten($pdo, $id);
    return $w;
}

function get_wahlgang_kandidaten(PDO $pdo, int $wahlgangId): array {
    $stmt = $pdo->prepare('SELECT * FROM mv_vote_kandidaten WHERE wahlgang_id = ? ORDER BY position ASC, id ASC');
    $stmt->execute([$wahlgangId]);
    return $stmt->fetchAll();
}

/** Prüft, ob ein Wahlgang tatsächlich zum angegebenen Jahr gehört (gleiche Begründung wie top_gehoert_zu_jahr()). */
function wahlgang_gehoert_zu_jahr(PDO $pdo, int $id, int $jahr): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM mv_vote_wahlgaenge WHERE id = ? AND jahr = ?');
    $stmt->execute([$id, $jahr]);
    return (bool)$stmt->fetchColumn();
}

/** Prüft Bezeichnung/Modus/Ämter/Kandidatenliste, gibt im Fehlerfall einen Text zurück, sonst ''. */
function wahlgang_eingabe_pruefen(string $bezeichnung, string $modus, ?int $anzahlAemter, array $kandidatenNamen): string {
    if (trim($bezeichnung) === '') {
        return 'Bitte eine Bezeichnung angeben (z. B. "Wahl des Vorstands").';
    }
    if (!in_array($modus, WAHL_MODI, true)) {
        return 'Ungültiger Wahlmodus.';
    }
    if (!$kandidatenNamen) {
        return 'Bitte mindestens eine Kandidatin oder einen Kandidaten angeben.';
    }
    // Mehr als WAHL_MAX_KANDIDATEN sind kein Fehler mehr (siehe
    // wahlgang_anlegen()/wahlgang_aktualisieren() - die kuerzen vorher
    // bereits auf die ersten WAHL_MAX_KANDIDATEN und geben dafuer einen
    // Hinweis statt eines blockierenden Fehlers zurueck), hier daher kein
    // Count-Check mehr.
    if (($modus === 'A' || $modus === 'C') && ($anzahlAemter === null || $anzahlAemter < 1)) {
        return 'Bitte die Anzahl der zu besetzenden Ämter angeben.';
    }
    if ($anzahlAemter !== null && $anzahlAemter > count($kandidatenNamen)) {
        return 'Die Anzahl der Ämter darf nicht größer als die Zahl der Kandidat:innen sein.';
    }
    return '';
}

/** Trimmt und entfernt leere Zeilen aus einer rohen Kandidat:innen-Namensliste (z. B. aus einer Textarea, eine Zeile je Name). */
function wahl_kandidatennamen_bereinigen(array $namen): array {
    return array_values(array_filter(array_map('trim', $namen), fn(string $n): bool => $n !== ''));
}

function kandidaten_speichern(PDO $pdo, int $wahlgangId, array $namen): void {
    $pdo->prepare('DELETE FROM mv_vote_kandidaten WHERE wahlgang_id = ?')->execute([$wahlgangId]);
    $stmt = $pdo->prepare('INSERT INTO mv_vote_kandidaten (wahlgang_id, position, name) VALUES (?,?,?)');
    foreach ($namen as $i => $name) {
        $stmt->execute([$wahlgangId, $i + 1, $name]);
    }
}

/**
 * Kuerzt eine bereits bereinigte Namensliste auf WAHL_MAX_KANDIDATEN
 * Einträge (ueberzaehlige werden einfach verworfen, statt die ganze
 * Eingabe mit einem Fehler abzulehnen) und gibt dafuer - nur falls
 * tatsaechlich gekuerzt wurde - einen deutlichen Hinweistext zurueck.
 * Gibt [gekuerzte Namensliste, Hinweis|''] zurück.
 */
function wahl_kandidatennamen_kuerzen(array $namen): array {
    if (count($namen) <= WAHL_MAX_KANDIDATEN) {
        return [$namen, ''];
    }
    $verworfen = count($namen) - WAHL_MAX_KANDIDATEN;
    $hinweis = 'Es wurden ' . count($namen) . ' Varianten/Kandidat:innen eingegeben - erlaubt sind höchstens '
        . WAHL_MAX_KANDIDATEN . '. Die ersten ' . WAHL_MAX_KANDIDATEN . ' wurden übernommen, die restlichen '
        . $verworfen . ' verworfen.';
    return [array_slice($namen, 0, WAHL_MAX_KANDIDATEN), $hinweis];
}

/** Legt einen neuen Wahlgang an. $art: 'wahl' (Standard, echte Wahl) oder 'abstimmung' (ad-hoc von der Beamersteuerung, siehe oben). Gibt [id|null, Fehler, Hinweis] zurück (Hinweis nur gesetzt, wenn auf WAHL_MAX_KANDIDATEN gekürzt wurde). */
function wahlgang_anlegen(PDO $pdo, int $jahr, string $bezeichnung, string $modus, ?int $anzahlAemter, array $kandidatenNamenRoh, string $art = 'wahl'): array {
    wahlgaenge_tabellen_sicherstellen($pdo);
    [$namen, $hinweis] = wahl_kandidatennamen_kuerzen(wahl_kandidatennamen_bereinigen($kandidatenNamenRoh));
    if ($modus === 'B' || $modus === 'P') {
        $anzahlAemter = null; // Modus B besetzt immer genau ein Amt, Modus P hat keine Sitzgrenze - keine Eingabe nötig.
    }
    $fehler = wahlgang_eingabe_pruefen($bezeichnung, $modus, $anzahlAemter, $namen);
    if ($fehler !== '') {
        return [null, $fehler, ''];
    }
    if (!in_array($art, ['wahl', 'abstimmung'], true)) {
        $art = 'wahl';
    }
    $pdo->prepare('INSERT INTO mv_vote_wahlgaenge (jahr, bezeichnung, modus, anzahl_aemter, art, created_at) VALUES (?,?,?,?,?,NOW())')
        ->execute([$jahr, trim($bezeichnung), $modus, $anzahlAemter, $art]);
    $id = (int)$pdo->lastInsertId();
    kandidaten_speichern($pdo, $id, $namen);
    return [$id, '', $hinweis];
}

/** Aktualisiert einen bestehenden Wahlgang (ersetzt die komplette Kandidat:innenliste). Gibt [Fehler, Hinweis] zurück (Hinweis nur gesetzt, wenn auf WAHL_MAX_KANDIDATEN gekürzt wurde). */
function wahlgang_aktualisieren(PDO $pdo, int $jahr, int $id, string $bezeichnung, string $modus, ?int $anzahlAemter, array $kandidatenNamenRoh, string $art = 'wahl'): array {
    if (!wahlgang_gehoert_zu_jahr($pdo, $id, $jahr)) {
        return ['Wahlgang nicht gefunden.', ''];
    }
    [$namen, $hinweis] = wahl_kandidatennamen_kuerzen(wahl_kandidatennamen_bereinigen($kandidatenNamenRoh));
    if ($modus === 'B' || $modus === 'P') {
        $anzahlAemter = null;
    }
    $fehler = wahlgang_eingabe_pruefen($bezeichnung, $modus, $anzahlAemter, $namen);
    if ($fehler !== '') {
        return [$fehler, ''];
    }
    if (!in_array($art, ['wahl', 'abstimmung'], true)) {
        $art = 'wahl';
    }
    $pdo->prepare('UPDATE mv_vote_wahlgaenge SET bezeichnung = ?, modus = ?, anzahl_aemter = ?, art = ? WHERE id = ? AND jahr = ?')
        ->execute([trim($bezeichnung), $modus, $anzahlAemter, $art, $id, $jahr]);
    kandidaten_speichern($pdo, $id, $namen);
    return ['', $hinweis];
}

function wahlgang_loeschen(PDO $pdo, int $jahr, int $id): void {
    // Kandidat:innen werden per ON DELETE CASCADE mitentfernt.
    $pdo->prepare('DELETE FROM mv_vote_wahlgaenge WHERE id = ? AND jahr = ?')->execute([$id, $jahr]);
}

// ============================================
// WAHLRUNDE DURCHFÜHREN
// ============================================

function wahl_stimmen_tabelle_sicherstellen(PDO $pdo): void {
    static $geprueft = false;
    if ($geprueft || !selbstheilung_aktiv()) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mv_vote_kandidatenstimmen (
            id INT AUTO_INCREMENT PRIMARY KEY,
            round_id INT NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            kandidat_id INT NOT NULL,
            votum VARCHAR(12) DEFAULT NULL,
            voted_at DATETIME NOT NULL,
            dauer_ms INT DEFAULT NULL,
            UNIQUE KEY idx_round_token_kandidat (round_id, token_hash, kandidat_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $geprueft = true;
}

function wahl_ergebnisse_tabelle_sicherstellen(PDO $pdo): void {
    static $geprueft = false;
    if ($geprueft || !selbstheilung_aktiv()) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mv_vote_wahlergebnisse (
            id INT AUTO_INCREMENT PRIMARY KEY,
            round_id INT NOT NULL,
            kandidat_id INT NOT NULL,
            kandidat_name VARCHAR(150) NOT NULL DEFAULT '',
            ja_anzahl INT DEFAULT NULL,
            nein_anzahl INT DEFAULT NULL,
            enthaltung_anzahl INT DEFAULT NULL,
            stimmen_anzahl INT DEFAULT NULL,
            rang INT NOT NULL,
            gewaehlt TINYINT(1) NOT NULL DEFAULT 0,
            stimmengleichheit TINYINT(1) NOT NULL DEFAULT 0,
            KEY idx_round_id (round_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Nachtraeglich ergaenzte Spalte (Phase 6b) - siehe Begruendung oben bei
    // wahlgaenge_tabellen_sicherstellen().
    if (!spalte_existiert($pdo, 'mv_vote_wahlergebnisse', 'stimmengleichheit')) {
        try {
            $pdo->exec('ALTER TABLE mv_vote_wahlergebnisse ADD COLUMN stimmengleichheit TINYINT(1) NOT NULL DEFAULT 0');
        } catch (PDOException $e) {
            // unkritisch, falls die Spalte zwischenzeitlich (Race) doch schon existiert.
        }
    }
    $geprueft = true;
}

/** Hat diese Stimmkarte in dieser Wahlrunde schon abgestimmt? (Analog zu vote_hat_schon_abgestimmt() - irgendeine Zeile genügt als Nachweis, egal bei welcher:m Kandidat:in.) */
function wahl_hat_schon_abgestimmt(PDO $pdo, int $roundId, string $token): bool {
    wahl_stimmen_tabelle_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT 1 FROM mv_vote_kandidatenstimmen WHERE round_id = ? AND token_hash = ? LIMIT 1');
    $stmt->execute([$roundId, hash('sha256', $token)]);
    return $stmt->fetch() !== false;
}

/** Zahl der in dieser Wahlrunde bisher abstimmenden Stimmkarten (nicht Zeilen - bei Modus A/C kann eine Stimmkarte mehrere Zeilen erzeugen). */
function wahl_round_zaehler(PDO $pdo, int $roundId): int {
    wahl_stimmen_tabelle_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT COUNT(DISTINCT token_hash) FROM mv_vote_kandidatenstimmen WHERE round_id = ?');
    $stmt->execute([$roundId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Lasterkennung für den Operator-Bildschirm, analog zu
 * vote_round_lastschwaeche() - hier eine Näherung über die letzten 20
 * ZEILEN statt der letzten 20 Stimmabgaben (bei Modus A/C kann eine
 * Stimmabgabe mehrere Zeilen mit demselben dauer_ms erzeugen); für den
 * groben Lasthinweis reicht das.
 */
function wahl_round_lastschwaeche(PDO $pdo, int $roundId): ?array {
    wahl_stimmen_tabelle_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT dauer_ms FROM mv_vote_kandidatenstimmen WHERE round_id = ? AND dauer_ms IS NOT NULL ORDER BY id DESC LIMIT 20');
    $stmt->execute([$roundId]);
    $werte = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($werte) < 5) {
        return null;
    }
    $mittel = (int)round(array_sum($werte) / count($werte));
    return ['mittel_ms' => $mittel, 'auffaellig' => $mittel >= VOTE_LAST_SCHWELLE_MS];
}

/**
 * Gibt eine Stimme für einen Wahlgang ab - Form von $eingabe je Modus:
 *  A: [kandidat_id => 'ja'|'nein'|'enthaltung', ...] - nicht beantwortete
 *     Kandidat:innen zählen bei der Auswertung weder dafür noch dagegen
 *     (wie eine Enthaltung).
 *  B: eine einzelne Kandidaten-ID.
 *  C: ein Array von Kandidaten-IDs (1 bis anzahl_aemter Stück).
 *  P: ein Array von Kandidaten-IDs (1 bis beliebig viele - keine Sitzgrenze).
 * Bereits gezählte Stimmen (gleicher Token, gleiche Runde) gelten als
 * weiterhin erfolgreich, nicht als Fehler - gleiche Begründung wie bei
 * vote_abstimmen(). Gibt [ok, Fehler] zurück.
 */
function wahl_abstimmen(PDO $pdo, array $round, string $token, $eingabe, ?float $anfrageStart = null): array {
    wahl_stimmen_tabelle_sicherstellen($pdo);
    if (!vote_token_gueltig($pdo, $token, (int)$round['jahr'])) {
        return [false, 'Diese Stimmkarte ist nicht gültig.'];
    }
    if ($round['status'] !== 'offen') {
        return [false, 'Diese Abstimmung ist nicht mehr aktiv.'];
    }
    $roundId = (int)$round['round_id'];
    if (wahl_hat_schon_abgestimmt($pdo, $roundId, $token)) {
        return [true, ''];
    }

    $wahlgang = get_wahlgang($pdo, (int)$round['wahlgang_id']);
    if (!$wahlgang) {
        return [false, 'Dieser Wahlgang existiert nicht mehr.'];
    }
    $gueltigeIds = array_map('intval', array_column($wahlgang['kandidaten'], 'id'));

    $zeilen = []; // [kandidat_id, votum|null]
    switch ($wahlgang['modus']) {
        case 'A':
            if (!is_array($eingabe)) {
                return [false, 'Bitte zu den Kandidat:innen abstimmen.'];
            }
            foreach ($eingabe as $kid => $votum) {
                $kid = (int)$kid;
                if (!in_array($kid, $gueltigeIds, true) || !in_array($votum, VOTE_VOTEN, true)) {
                    continue;
                }
                $zeilen[] = [$kid, $votum];
            }
            if (!$zeilen) {
                return [false, 'Bitte zu mindestens einer Kandidatin oder einem Kandidaten Ja, Nein oder Enthaltung wählen.'];
            }
            break;

        case 'B':
            $kid = (int)$eingabe;
            if (!in_array($kid, $gueltigeIds, true)) {
                return [false, 'Bitte eine Kandidatin oder einen Kandidaten auswählen.'];
            }
            $zeilen[] = [$kid, null];
            break;

        case 'C':
            $ids = array_values(array_unique(array_map('intval', is_array($eingabe) ? $eingabe : [])));
            $ids = array_values(array_filter($ids, fn(int $kid): bool => in_array($kid, $gueltigeIds, true)));
            if (!$ids) {
                return [false, 'Bitte mindestens eine Kandidatin oder einen Kandidaten auswählen.'];
            }
            $aemter = (int)($wahlgang['anzahl_aemter'] ?? count($gueltigeIds));
            if (count($ids) > $aemter) {
                return [false, 'Bitte höchstens ' . $aemter . ' Kandidat:in(nen) auswählen.'];
            }
            foreach ($ids as $kid) {
                $zeilen[] = [$kid, null];
            }
            break;

        case 'P':
            $ids = array_values(array_unique(array_map('intval', is_array($eingabe) ? $eingabe : [])));
            $ids = array_values(array_filter($ids, fn(int $kid): bool => in_array($kid, $gueltigeIds, true)));
            if (!$ids) {
                return [false, 'Bitte mindestens eine Variante auswählen.'];
            }
            foreach ($ids as $kid) {
                $zeilen[] = [$kid, null];
            }
            break;

        default:
            return [false, 'Unbekannter Wahlmodus.'];
    }

    $dauerMs = $anfrageStart !== null ? (int)round((microtime(true) - $anfrageStart) * 1000) : null;
    $tokenHash = hash('sha256', $token);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO mv_vote_kandidatenstimmen (round_id, token_hash, kandidat_id, votum, voted_at, dauer_ms) VALUES (?,?,?,?,NOW(),?)');
        foreach ($zeilen as [$kid, $votum]) {
            $stmt->execute([$roundId, $tokenHash, $kid, $votum, $dauerMs]);
        }
        $pdo->commit();
        return [true, ''];
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ((int)$e->getCode() === 23000) {
            return [true, '']; // Gleichzeitigkeits-Fall: schon gezählt, trotzdem Erfolg
        }
        return [false, 'Die Stimme konnte nicht gespeichert werden - bitte erneut absenden.'];
    }
}

// ============================================
// WAHLRUNDE AUSWERTEN
// ============================================

/**
 * Vergibt auf einer absteigend nach $wertFeld sortierten Ergebnisliste
 * Sitze: die vorderen $plaetze Zeilen (oder, wenn $plaetze null ist, alle
 * oberhalb $mindestwert) werden "gewaehlt". Reicht die Zahl der an der
 * Sitzgrenze gleich stehenden Zeilen nicht für alle an dieser Stelle noch
 * freien Sitze - der Gleichstand also tatsächlich darüber entscheidet, wer
 * hineinkommt -, werden sie statt "gewaehlt" als "stimmengleichheit"
 * markiert (nicht gewählt - das muss dann die Versammlung manuell
 * entscheiden). $mindestwert: Werte darunter oder gleich zählen nie als
 * gewählt (Modus A: delta > 0; Modus B/C: stimmen_anzahl > 0).
 */
function wahl_sitze_vergeben(array $zeilen, string $wertFeld, ?int $plaetze, float $mindestwert): array {
    $cutoffWert = ($plaetze !== null && $plaetze > 0 && isset($zeilen[$plaetze - 1])) ? $zeilen[$plaetze - 1][$wertFeld] : null;
    foreach ($zeilen as &$z) {
        $z['gewaehlt'] = $z[$wertFeld] > $mindestwert && ($cutoffWert === null || $z[$wertFeld] >= $cutoffWert);
        $z['stimmengleichheit'] = false;
    }
    unset($z);

    if ($cutoffWert !== null) {
        $anGrenze = array_filter($zeilen, fn(array $z): bool => $z['gewaehlt'] && $z[$wertFeld] == $cutoffWert);
        $oberhalbGrenze = array_filter($zeilen, fn(array $z): bool => $z['gewaehlt'] && $z[$wertFeld] > $cutoffWert);
        $freieSitzeAnGrenze = $plaetze - count($oberhalbGrenze);
        if (count($anGrenze) > $freieSitzeAnGrenze) {
            foreach ($zeilen as &$z) {
                if ($z['gewaehlt'] && $z[$wertFeld] == $cutoffWert) {
                    $z['gewaehlt'] = false;
                    $z['stimmengleichheit'] = true;
                }
            }
            unset($z);
        }
    }
    return $zeilen;
}

/**
 * Wertet eine beendete Wahlrunde aus (Entsprechung zu vote_round_auswerten()
 * für die Ja/Nein/Enthaltung-Variante): zählt je Kandidat:in, ermittelt die
 * Gewählten je Modus, schreibt zuerst zuverlässig das CSV-Protokoll,
 * speichert das Ergebnis dauerhaft in mv_vote_wahlergebnisse und löscht
 * erst DANACH die Einzelstimmen. Gibt [Ergebniszeilen|null, Fehler] zurück.
 *
 * Modus A: "gewählt" sind die anzahl_aemter Kandidat:innen mit dem höchsten
 * Stimmendelta (Ja minus Nein), sofern dieses positiv ist - nicht automatisch
 * alle mit positivem Delta (anzahl_aemter ist deshalb wie bei Modus C
 * Pflicht, siehe wahlgang_eingabe_pruefen()).
 * Modus B: die/der Kandidat:in mit den meisten Stimmen.
 * Modus C: die anzahl_aemter Kandidat:innen mit den meisten Stimmen.
 * Entscheidet bei A/B/C ein Gleichstand genau an der Sitzgrenze darüber, wer
 * hineinkommt, wird dort statt "gewaehlt" "stimmengleichheit" vermerkt (die
 * Versammlung muss dann manuell entscheiden) - siehe wahl_sitze_vergeben().
 * Modus P (Präferenz): keine Sitzgrenze, keine/kein Gewählte:r - nur eine
 * Rangliste nach Stimmenzahl.
 */
function wahl_round_auswerten(PDO $pdo, int $roundId): array {
    wahl_stimmen_tabelle_sicherstellen($pdo);
    wahl_ergebnisse_tabelle_sicherstellen($pdo);
    $round = vote_round_get($pdo, $roundId);
    if (!$round) {
        return [null, 'Abstimmungsrunde nicht gefunden.'];
    }
    if ($round['status'] !== 'geschlossen') {
        return [null, 'Diese Abstimmungsrunde ist nicht abgeschlossen (erst "Abstimmung beenden").'];
    }
    if (empty($round['wahlgang_id'])) {
        return [null, 'Dies ist keine Wahl.'];
    }

    $wahlgang = get_wahlgang($pdo, (int)$round['wahlgang_id']);
    if (!$wahlgang) {
        return [null, 'Der zugehörige Wahlgang wurde gelöscht.'];
    }

    $stmt = $pdo->prepare('SELECT kandidat_id, votum, COUNT(*) n FROM mv_vote_kandidatenstimmen WHERE round_id = ? GROUP BY kandidat_id, votum');
    $stmt->execute([$roundId]);
    $rohzahlen = []; // kandidat_id => ['ja'=>n,'nein'=>n,'enthaltung'=>n,'stimme'=>n]
    foreach ($stmt->fetchAll() as $r) {
        $kid = (int)$r['kandidat_id'];
        $rohzahlen[$kid] ??= ['ja' => 0, 'nein' => 0, 'enthaltung' => 0, 'stimme' => 0];
        $rohzahlen[$kid][$r['votum'] ?? 'stimme'] = (int)$r['n'];
    }

    $modus = $wahlgang['modus'];
    $aemter = $wahlgang['anzahl_aemter'] !== null ? (int)$wahlgang['anzahl_aemter'] : null;

    $zeilen = [];
    foreach ($wahlgang['kandidaten'] as $k) {
        $kid = (int)$k['id'];
        $z = $rohzahlen[$kid] ?? ['ja' => 0, 'nein' => 0, 'enthaltung' => 0, 'stimme' => 0];
        $zeilen[] = [
            'kandidat_id' => $kid,
            'kandidat_name' => $k['name'],
            'ja_anzahl' => $modus === 'A' ? $z['ja'] : null,
            'nein_anzahl' => $modus === 'A' ? $z['nein'] : null,
            'enthaltung_anzahl' => $modus === 'A' ? $z['enthaltung'] : null,
            'stimmen_anzahl' => $modus === 'A' ? null : $z['stimme'],
            'delta' => $z['ja'] - $z['nein'],
        ];
    }

    if ($modus === 'A') {
        usort($zeilen, fn(array $a, array $b): int => $b['delta'] <=> $a['delta']);
        $zeilen = wahl_sitze_vergeben($zeilen, 'delta', $aemter, 0);
    } elseif ($modus === 'P') {
        usort($zeilen, fn(array $a, array $b): int => $b['stimmen_anzahl'] <=> $a['stimmen_anzahl']);
        foreach ($zeilen as &$z) {
            $z['gewaehlt'] = false;
            $z['stimmengleichheit'] = false;
        }
        unset($z);
    } else { // B oder C
        usort($zeilen, fn(array $a, array $b): int => $b['stimmen_anzahl'] <=> $a['stimmen_anzahl']);
        $plaetze = $modus === 'B' ? 1 : ($aemter ?? 1);
        $zeilen = wahl_sitze_vergeben($zeilen, 'stimmen_anzahl', $plaetze, 0);
    }
    foreach ($zeilen as $i => &$z) {
        $z['rang'] = $i + 1;
    }
    unset($z);

    [$csvDatei, $fehler] = wahl_round_csv_erzeugen($pdo, $round, $wahlgang, $zeilen);
    if ($fehler !== '') {
        return [null, $fehler];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE mv_vote_rounds SET status = 'ausgewertet', csv_datei = ? WHERE round_id = ?")->execute([$csvDatei, $roundId]);
        $insert = $pdo->prepare(
            'INSERT INTO mv_vote_wahlergebnisse (round_id, kandidat_id, kandidat_name, ja_anzahl, nein_anzahl, enthaltung_anzahl, stimmen_anzahl, rang, gewaehlt, stimmengleichheit)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($zeilen as $z) {
            $insert->execute([
                $roundId, $z['kandidat_id'], $z['kandidat_name'],
                $z['ja_anzahl'], $z['nein_anzahl'], $z['enthaltung_anzahl'], $z['stimmen_anzahl'],
                $z['rang'], $z['gewaehlt'] ? 1 : 0, $z['stimmengleichheit'] ? 1 : 0,
            ]);
        }
        $pdo->prepare('DELETE FROM mv_vote_kandidatenstimmen WHERE round_id = ?')->execute([$roundId]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        return [null, 'Das Ergebnis konnte nicht gespeichert werden - bitte erneut versuchen.'];
    }

    return [$zeilen, ''];
}

/** Lädt das gespeicherte Ergebnis einer bereits ausgewerteten Wahlrunde (für die Anzeige, nachdem die Einzelstimmen gelöscht sind). */
function wahl_round_gespeichertes_ergebnis(PDO $pdo, int $roundId): array {
    wahl_ergebnisse_tabelle_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT * FROM mv_vote_wahlergebnisse WHERE round_id = ? ORDER BY rang ASC');
    $stmt->execute([$roundId]);
    return $stmt->fetchAll();
}

/**
 * Schreibt das CSV-Protokoll einer Wahlrunde: Kopfzeile mit Wahlgang-
 * Bezeichnung/Modus, danach das Ergebnis je Kandidat:in, danach - wie bei
 * der Ja/Nein/Enthaltung-Variante (vote_round_csv_erzeugen()) - jede
 * Einzelstimme (Token-Hash, Zeitpunkt, Kandidat:in, Votum). Gleiches
 * Dateinamensschema wie dort ("abstimmung-<round_id>-<Zeit>.csv"), damit
 * abstimmung_csv.php unverändert beide Arten ausliefern kann.
 */
function wahl_round_csv_erzeugen(PDO $pdo, array $round, array $wahlgang, array $ergebnisZeilen): array {
    $dir = vote_storage_dir();
    $filename = 'abstimmung-' . $round['round_id'] . '-' . date('Ymd-His') . '.csv';
    $pfad = $dir . '/' . $filename;
    $fh = fopen($pfad, 'w');
    if (!$fh) {
        return ['', 'Die Protokolldatei konnte nicht angelegt werden.'];
    }

    fwrite($fh, '# Wahlgang: ' . str_replace([';', "\r", "\n"], ' ', $wahlgang['bezeichnung']) . ' (Modus ' . $wahlgang['modus'] . ")\n");
    if ($wahlgang['modus'] === 'A') {
        fwrite($fh, "rang;kandidat;ja;nein;enthaltung;delta;gewaehlt;stimmengleichheit\n");
        foreach ($ergebnisZeilen as $z) {
            fwrite($fh, $z['rang'] . ';' . str_replace(';', ',', $z['kandidat_name']) . ';' . $z['ja_anzahl'] . ';' . $z['nein_anzahl'] . ';' . $z['enthaltung_anzahl'] . ';' . $z['delta'] . ';' . ($z['gewaehlt'] ? 'ja' : 'nein') . ';' . (!empty($z['stimmengleichheit']) ? 'ja' : 'nein') . "\n");
        }
    } else {
        fwrite($fh, "rang;kandidat;stimmen;gewaehlt;stimmengleichheit\n");
        foreach ($ergebnisZeilen as $z) {
            fwrite($fh, $z['rang'] . ';' . str_replace(';', ',', $z['kandidat_name']) . ';' . $z['stimmen_anzahl'] . ';' . ($z['gewaehlt'] ? 'ja' : 'nein') . ';' . (!empty($z['stimmengleichheit']) ? 'ja' : 'nein') . "\n");
        }
    }

    fwrite($fh, "\ntoken_hash;zeitpunkt;kandidat;votum\n");
    $kandidatenNamen = array_column($wahlgang['kandidaten'], 'name', 'id');
    $stmt = $pdo->prepare('SELECT token_hash, voted_at, kandidat_id, votum FROM mv_vote_kandidatenstimmen WHERE round_id = ? ORDER BY id');
    $stmt->execute([(int)$round['round_id']]);
    foreach ($stmt as $zeile) {
        $name = $kandidatenNamen[(int)$zeile['kandidat_id']] ?? ('#' . $zeile['kandidat_id']);
        fwrite($fh, $zeile['token_hash'] . ';' . $zeile['voted_at'] . ';' . str_replace(';', ',', $name) . ';' . ($zeile['votum'] ?? 'stimme') . "\n");
    }
    fclose($fh);
    clearstatcache(true, $pfad);
    if (!is_file($pfad) || filesize($pfad) === 0) {
        return ['', 'Die Protokolldatei scheint leer oder fehlerhaft zu sein - bitte erneut versuchen.'];
    }
    return [$filename, ''];
}

/**
 * Formatierte Anzeigezeilen des Wahlergebnisses für Beamer/Beamersteuerung -
 * eine Zeile je Kandidat:in, analog zu vote_ergebnis_zeilen() für
 * Ja/Nein/Enthaltung. $ergebnisZeilen wie von wahl_round_auswerten()/
 * wahl_round_gespeichertes_ergebnis() zurückgegeben (beide verwenden
 * dieselben Feldnamen, genau damit diese Funktion beide ohne Umwege
 * formatieren kann).
 */
function wahl_ergebnis_zeilen(array $ergebnisZeilen, string $modus): array {
    $zeilen = [];
    foreach ($ergebnisZeilen as $z) {
        $zeile = $z['rang'] . '. ' . $z['kandidat_name'] . ' — ';
        if ($modus === 'A') {
            $delta = (int)$z['ja_anzahl'] - (int)$z['nein_anzahl'];
            $zeile .= 'Ja: ' . $z['ja_anzahl'] . ' - Nein: ' . $z['nein_anzahl'] . ' - Enth.: ' . $z['enthaltung_anzahl'] . ' (Delta ' . ($delta >= 0 ? '+' : '') . $delta . ')';
        } else {
            $zeile .= $z['stimmen_anzahl'] . ' Stimme(n)';
        }
        if ($modus !== 'P') {
            if (!empty($z['stimmengleichheit'])) {
                $zeile .= ' — STIMMENGLEICHHEIT';
            } elseif ($z['gewaehlt']) {
                $zeile .= ' — GEWÄHLT';
            }
        }
        $zeilen[] = $zeile;
    }
    return $zeilen;
}

/**
 * Beamer-Folie für einen Wahlgang: Kandidat:innen-/Variantenliste plus
 * kurzer Hinweis zum Abstimmungsmodus - wie beim TOP/Beschlussentwurf
 * (beamer_rendern_top() in beamer_functions.php) übernimmt das Beamerbild
 * auch hier die Rolle der sonst nötigen separaten "Wahlfolie". Bei
 * art==='abstimmung' (ad-hoc Auswahl-/Präferenzfrage statt echter Wahl,
 * siehe Dateikopf) eine einfache "-"-Liste mit "Variante" statt der
 * nummerierten Kandidat:innenliste.
 */
function wahl_rendern_beamer(PDO $pdo, int $wahlgangId): ?string {
    $wahlgang = get_wahlgang($pdo, $wahlgangId);
    if (!$wahlgang) {
        return null;
    }
    $istAbstimmungArt = ($wahlgang['art'] ?? 'wahl') === 'abstimmung';
    $hinweis = match ($wahlgang['modus']) {
        'A' => 'Bitte je Kandidatin/Kandidat mit Ja, Nein oder Enthaltung abstimmen.',
        'B' => $istAbstimmungArt ? 'Bitte eine Variante auswählen.' : 'Bitte eine Kandidatin oder einen Kandidaten wählen.',
        'C' => 'Bitte bis zu ' . (int)($wahlgang['anzahl_aemter'] ?? 1) . ' Kandidat:in(nen) wählen.',
        'P' => 'Bitte alle zutreffenden Varianten auswählen.',
        default => '',
    };
    $namen = array_column($wahlgang['kandidaten'], 'name');
    $listGroesse = beamer_listengroesse_optimieren($namen);

    $html = '<div style="font-size:32px; color:' . BEAMER_FARBE . '; font-weight:600; margin-bottom:14px;">' . h($wahlgang['bezeichnung']) . '</div>';
    if ($istAbstimmungArt) {
        $html .= '<div style="font-size:' . $listGroesse . 'px; color:' . BEAMER_FARBE . '; line-height:1.6; margin-left:10px;">';
        foreach ($wahlgang['kandidaten'] as $k) {
            $html .= '<div>– ' . h($k['name']) . '</div>';
        }
        $html .= '</div>';
        $html .= '<div style="font-size:18px; color:#555; margin-top:14px;">' . h($hinweis) . '</div>';
        return $html;
    }
    $html .= '<ol style="font-size:' . $listGroesse . 'px; color:' . BEAMER_FARBE . '; line-height:1.5; margin-left:22px;">';
    foreach ($wahlgang['kandidaten'] as $k) {
        $html .= '<li>' . h($k['name']) . '</li>';
    }
    $html .= '</ol>';
    $html .= '<div style="font-size:18px; color:#555; margin-top:14px;">' . h($hinweis) . '</div>';
    return $html;
}
