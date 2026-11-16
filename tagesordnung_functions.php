<?php
/**
 * tagesordnung_functions.php - Datenzugriff fuer die Tagesordnung
 * (Tabellen mvtop, mvtopdokumente - siehe migrations/002_tagesordnung.sql).
 *
 * Rein prozedural, jede Funktion einzeln nutzbar von Anzeige- und
 * Prozess-Dateien.
 */

require_once __DIR__ . '/tagesordnung_standard.php';

const TOP_DOKUMENT_STATI = ['abrufbar', 'vorbereitung', 'verborgen'];

/**
 * Stellt mvtop/mvtopdokumente sicher (CREATE TABLE IF NOT EXISTS) - bei
 * Mensa (dort per migrations/002_tagesordnung.sql eingerichtet) immer ein
 * No-Op; bei einer komplett neuen Installation ohne ausgefuehrte Migration
 * entstehen die Tabellen hier zum allerersten Mal, mit demselben Schema.
 */
function mvtop_tabellen_sicherstellen(PDO $pdo): void {
    static $geprueft = false;
    if ($geprueft || !selbstheilung_aktiv()) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mvtop (
            id       INT AUTO_INCREMENT PRIMARY KEY,
            jahr     INT NOT NULL,
            position INT NOT NULL,
            titel    VARCHAR(255) NOT NULL DEFAULT '',
            aktiv    TINYINT(1) NOT NULL DEFAULT 1,
            top_folie MEDIUMTEXT,
            beschlussentwurf MEDIUMTEXT,
            KEY idx_jahr (jahr)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Nachtraeglich ergaenzte Spalten (Beamerinhalte je TOP) - siehe
    // spalte_existiert() in db_functions.php: Installationen, die mvtop
    // schon vorher angelegt haben, bekommen sie erst hier ergaenzt.
    foreach (['top_folie', 'beschlussentwurf'] as $spalte) {
        if (!spalte_existiert($pdo, 'mvtop', $spalte)) {
            try {
                $pdo->exec("ALTER TABLE mvtop ADD COLUMN $spalte MEDIUMTEXT");
            } catch (PDOException $e) {
                // unkritisch, falls die Spalte zwischenzeitlich (Race) doch schon existiert.
            }
        }
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mvtopdokumente (
            id        INT AUTO_INCREMENT PRIMARY KEY,
            top_id    INT NOT NULL,
            position  INT NOT NULL DEFAULT 0,
            titel     VARCHAR(255) NOT NULL,
            url       VARCHAR(500) NOT NULL DEFAULT '',
            ist_datei TINYINT(1) NOT NULL DEFAULT 0,
            status    VARCHAR(20) NOT NULL DEFAULT 'vorbereitung',
            KEY idx_top_id (top_id),
            CONSTRAINT fk_mvtopdokumente_top FOREIGN KEY (top_id) REFERENCES mvtop(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $geprueft = true;
}

/** Liefert die Tagesordnung eines Jahres inkl. der Dokumente je TOP. */
function get_tagesordnung(PDO $pdo, int $jahr): array {
    mvtop_tabellen_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT * FROM mvtop WHERE jahr = ? ORDER BY position ASC, id ASC');
    $stmt->execute([$jahr]);
    $tops = $stmt->fetchAll();

    $doku_stmt = $pdo->prepare('SELECT * FROM mvtopdokumente WHERE top_id = ? ORDER BY position ASC, id ASC');
    foreach ($tops as &$top) {
        $doku_stmt->execute([$top['id']]);
        $top['dokumente'] = $doku_stmt->fetchAll();
    }
    unset($top);

    return $tops;
}

function tagesordnung_existiert(PDO $pdo, int $jahr): bool {
    mvtop_tabellen_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM mvtop WHERE jahr = ?');
    $stmt->execute([$jahr]);
    return (int)$stmt->fetch()['n'] > 0;
}

function naechste_top_position(PDO $pdo, int $jahr): int {
    mvtop_tabellen_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT MAX(position) AS m FROM mvtop WHERE jahr = ?');
    $stmt->execute([$jahr]);
    $m = $stmt->fetch()['m'];
    return $m === null ? 1 : (int)$m + 1;
}

/**
 * Laedt die vorgegebene Standard-Tagesordnung fuer ein (bisher leeres) Jahr -
 * inklusive der in get_standard_tagesordnung() hinterlegten Vorschlaege fuer
 * den Beschlussentwurf (leer = kein Vorschlag, z. B. bei reinen
 * Ablaufpunkten/Wahlen). Das Redaktionsteam kann jeden Text danach wie
 * gewohnt unter "Tagesordnung bearbeiten" anpassen oder loeschen (siehe
 * top_inhalte_speichern()) - diese Funktion setzt nur den Ausgangsstand.
 */
function standard_tagesordnung_laden(PDO $pdo, int $jahr): void {
    mvtop_tabellen_sicherstellen($pdo);
    $stmt = $pdo->prepare('INSERT INTO mvtop (jahr, position, titel, aktiv, beschlussentwurf) VALUES (?, ?, ?, ?, ?)');
    foreach (get_standard_tagesordnung() as $i => $top) {
        $beschlussentwurf = $top['beschlussentwurf'] ?? '';
        $stmt->execute([$jahr, $i + 1, $top['titel'], $top['aktiv'] ? 1 : 0, $beschlussentwurf !== '' ? $beschlussentwurf : null]);
    }
}

/**
 * Uebernimmt Tagesordnung und Dokumenttitel eines Vorjahres in ein
 * (bisher leeres) Jahr. Nur die Titel der Dokumente werden uebernommen,
 * nicht die Dateien/Links selbst - die muessen fuer das neue Jahr ohnehin
 * neu bereitgestellt werden.
 */
function tagesordnung_vorjahr_uebernehmen(PDO $pdo, int $jahr, int $vorjahr): bool {
    $vorjahr_tops = get_tagesordnung($pdo, $vorjahr);
    if (!$vorjahr_tops) {
        return false;
    }

    $top_stmt = $pdo->prepare('INSERT INTO mvtop (jahr, position, titel, aktiv) VALUES (?, ?, ?, ?)');
    $doku_stmt = $pdo->prepare(
        'INSERT INTO mvtopdokumente (top_id, position, titel, url, ist_datei, status) VALUES (?, ?, ?, ?, 0, ?)'
    );

    foreach ($vorjahr_tops as $top) {
        $top_stmt->execute([$jahr, $top['position'], $top['titel'], $top['aktiv']]);
        $neue_top_id = (int)$pdo->lastInsertId();

        foreach ($top['dokumente'] as $doc) {
            $doku_stmt->execute([$neue_top_id, $doc['position'], $doc['titel'], '', 'vorbereitung']);
        }
    }

    return true;
}

function top_hinzufuegen(PDO $pdo, int $jahr, string $titel): void {
    $stmt = $pdo->prepare('INSERT INTO mvtop (jahr, position, titel, aktiv) VALUES (?, ?, ?, 1)');
    $stmt->execute([$jahr, naechste_top_position($pdo, $jahr), $titel]);
}

/**
 * $jahr wird bewusst mitgeprueft (WHERE jahr = ?), damit sich ueber eine
 * manipulierte oder veraltete id niemals ein TOP eines ANDEREN Jahres
 * aendern laesst.
 */
function top_aktualisieren(PDO $pdo, int $jahr, int $id, string $titel, bool $aktiv): void {
    $stmt = $pdo->prepare('UPDATE mvtop SET titel = ?, aktiv = ? WHERE id = ? AND jahr = ?');
    $stmt->execute([$titel, $aktiv ? 1 : 0, $id, $jahr]);
}

/**
 * Speichert die im Vorfeld vom Redaktionsteam vorbereiteten Beamerinhalte
 * eines TOP: die Folie, die beim Aufruf des TOP durch die Versammlungsleitung
 * gezeigt wird, und den zugehörigen Beschlussentwurf (siehe
 * beamer_rendern_top()/beamer_rendern_beschlussentwurf() in
 * beamer_functions.php). Leer gelassen, erscheint beim TOP weiterhin die
 * automatisch erzeugte Titel-Folie bzw. beim Beschlussentwurf ein Hinweis,
 * dass noch keiner hinterlegt ist.
 */
function top_inhalte_speichern(PDO $pdo, int $jahr, int $id, string $topFolie, string $beschlussentwurf): void {
    $stmt = $pdo->prepare('UPDATE mvtop SET top_folie = ?, beschlussentwurf = ? WHERE id = ? AND jahr = ?');
    $stmt->execute([$topFolie !== '' ? $topFolie : null, $beschlussentwurf !== '' ? $beschlussentwurf : null, $id, $jahr]);
}

function top_loeschen(PDO $pdo, int $jahr, int $id): void {
    // Dokumente werden per ON DELETE CASCADE mitentfernt (siehe Migration).
    $stmt = $pdo->prepare('DELETE FROM mvtop WHERE id = ? AND jahr = ?');
    $stmt->execute([$id, $jahr]);
}

/** Vertauscht die Position eines TOP mit seinem direkten Nachbarn. */
function top_verschieben(PDO $pdo, int $jahr, int $id, string $richtung): void {
    $stmt = $pdo->prepare('SELECT id, position FROM mvtop WHERE jahr = ? ORDER BY position ASC, id ASC');
    $stmt->execute([$jahr]);
    $reihenfolge = $stmt->fetchAll();

    $index = null;
    foreach ($reihenfolge as $i => $row) {
        if ((int)$row['id'] === $id) { $index = $i; break; }
    }
    if ($index === null) return;

    $nachbar_index = $richtung === 'hoch' ? $index - 1 : $index + 1;
    if ($nachbar_index < 0 || $nachbar_index >= count($reihenfolge)) return;

    $a = $reihenfolge[$index];
    $b = $reihenfolge[$nachbar_index];

    $update = $pdo->prepare('UPDATE mvtop SET position = ? WHERE id = ?');
    $update->execute([$b['position'], $a['id']]);
    $update->execute([$a['position'], $b['id']]);
}

function dokument_naechste_position(PDO $pdo, int $top_id): int {
    $stmt = $pdo->prepare('SELECT MAX(position) AS m FROM mvtopdokumente WHERE top_id = ?');
    $stmt->execute([$top_id]);
    $m = $stmt->fetch()['m'];
    return $m === null ? 1 : (int)$m + 1;
}

/** Prueft, ob ein TOP tatsaechlich zum angegebenen Jahr gehoert. */
function top_gehoert_zu_jahr(PDO $pdo, int $top_id, int $jahr): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM mvtop WHERE id = ? AND jahr = ?');
    $stmt->execute([$top_id, $jahr]);
    return (bool)$stmt->fetchColumn();
}

/** $jahr wird geprueft, damit sich kein Dokument an einen TOP eines anderen Jahres haengen laesst. */
function dokument_hinzufuegen(PDO $pdo, int $jahr, int $top_id, string $titel, string $url, bool $ist_datei, string $status): void {
    if (!top_gehoert_zu_jahr($pdo, $top_id, $jahr)) return;
    if (!in_array($status, TOP_DOKUMENT_STATI, true)) {
        $status = 'vorbereitung';
    }
    $stmt = $pdo->prepare(
        'INSERT INTO mvtopdokumente (top_id, position, titel, url, ist_datei, status) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$top_id, dokument_naechste_position($pdo, $top_id), $titel, $url, $ist_datei ? 1 : 0, $status]);
}

/** $jahr wird ueber einen JOIN geprueft, damit sich kein Dokument eines anderen Jahres aendern laesst. */
function dokument_status_aendern(PDO $pdo, int $jahr, int $id, string $status): void {
    if (!in_array($status, TOP_DOKUMENT_STATI, true)) return;
    $stmt = $pdo->prepare(
        'UPDATE mvtopdokumente SET status = ?
         WHERE id = ? AND top_id IN (SELECT id FROM mvtop WHERE jahr = ?)'
    );
    $stmt->execute([$status, $id, $jahr]);
}

function dokument_loeschen(PDO $pdo, int $jahr, int $id): void {
    $stmt = $pdo->prepare(
        'SELECT url, ist_datei FROM mvtopdokumente
         WHERE id = ? AND top_id IN (SELECT id FROM mvtop WHERE jahr = ?)'
    );
    $stmt->execute([$id, $jahr]);
    $doc = $stmt->fetch();
    if (!$doc) return; // nicht gefunden oder gehoert zu einem anderen Jahr

    $stmt = $pdo->prepare('DELETE FROM mvtopdokumente WHERE id = ?');
    $stmt->execute([$id]);

    // Hochgeladene Datei nur loeschen, wenn kein anderer Dokument-Eintrag
    // denselben Pfad nutzt (sollte praktisch nie vorkommen, aber sicher ist sicher).
    if ($doc && (int)$doc['ist_datei'] === 1 && $doc['url'] !== '') {
        $stmt2 = $pdo->prepare('SELECT COUNT(*) AS n FROM mvtopdokumente WHERE url = ?');
        $stmt2->execute([$doc['url']]);
        if ((int)$stmt2->fetch()['n'] === 0) {
            $pfad = __DIR__ . '/' . ltrim($doc['url'], '/');
            if (is_file($pfad)) { @unlink($pfad); }
        }
    }
}

const TOP_UPLOAD_ERLAUBTE_ENDUNGEN = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'txt'];

/**
 * Speichert eine per $_FILES hochgeladene Datei fuer ein Jahr ab und
 * liefert den relativen Web-Pfad zurueck (fuer die Spalte url), oder
 * null bei ungueltiger/fehlender Datei bzw. Fehler.
 */
function top_datei_speichern(array $datei, int $jahr): ?string {
    if (!isset($datei['error']) || $datei['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $endung = strtolower(pathinfo($datei['name'], PATHINFO_EXTENSION));
    if (!in_array($endung, TOP_UPLOAD_ERLAUBTE_ENDUNGEN, true)) {
        return null;
    }

    $zielordner = __DIR__ . '/uploads/' . $jahr;
    if (!is_dir($zielordner)) {
        mkdir($zielordner, 0755, true);
        // Verhindert die Ausfuehrung hochgeladener Dateien als Skript,
        // falls der Server ueber Apache/mod_php laeuft.
        file_put_contents($zielordner . '/.htaccess', "php_flag engine off\n");
    }

    $dateiname = bin2hex(random_bytes(8)) . '.' . $endung;
    $zielpfad = $zielordner . '/' . $dateiname;

    if (!move_uploaded_file($datei['tmp_name'], $zielpfad)) {
        return null;
    }

    return 'uploads/' . $jahr . '/' . $dateiname;
}

/** Einen TOP anhand seiner ID laden (fuer die Dokumente-Unterseite). */
function get_top(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM mvtop WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_top_dokumente(PDO $pdo, int $top_id): array {
    $stmt = $pdo->prepare('SELECT * FROM mvtopdokumente WHERE top_id = ? ORDER BY position ASC, id ASC');
    $stmt->execute([$top_id]);
    return $stmt->fetchAll();
}
