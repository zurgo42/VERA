<?php
/**
 * protokoll_functions.php - Zentrale Protokollierung aller schreibenden
 * Datenbankzugriffe (INSERT/UPDATE/DELETE) in mvlogs_<jahr>: wer hat
 * wann was getan, mit den tatsaechlich geschriebenen Werten - fuer
 * Nachvollziehbarkeit und als Grundlage der Datensicherung.
 *
 * Wird bewusst auf Ebene der process_*.php-Dateien aufgerufen (nicht in
 * den einzelnen *_functions.php), da dort bereits Mitglied, Jahr und die
 * konkret gespeicherten Werte vorliegen - die Datenzugriffsfunktionen
 * selbst bleiben dadurch unveraendert und einfach zu testen.
 *
 * Uebernimmt die Rolle von mvlog()/mvspeichern() aus dem Altsystem
 * (siehe ifunktionen_mv.php), jetzt explizit statt implizit ueber einen
 * zentralen SQL-String-Wrapper aufgerufen.
 */

/** Legt mvlogs_<jahr> an, falls es (auch vom Altsystem her) noch nicht existiert. */
function mvlogs_tabelle_sicherstellen(PDO $pdo, int $jahr): void {
    static $geprueft = [];
    if (isset($geprueft[$jahr]) || !selbstheilung_aktiv()) {
        return;
    }
    $tabelle = 'mvlogs_' . $jahr;
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `$tabelle` (
            ID INT AUTO_INCREMENT PRIMARY KEY,
            MNr VARCHAR(12),
            KurzN VARCHAR(60),
            zeit DATETIME,
            was VARCHAR(200),
            string TEXT,
            INDEX idx_mnr (MNr),
            INDEX idx_zeit (zeit)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $geprueft[$jahr] = true;
}

/**
 * Schreibt einen Protokolleintrag. $sql ist eine lesbare, informative
 * Wiedergabe der tatsaechlich geschriebenen Werte (siehe sql_insert/
 * sql_update/sql_delete) - fuer die Protokollanzeige und Datensicherung,
 * nicht zur erneuten Ausfuehrung gedacht.
 *
 * Ein Fehlschlagen der Protokollierung selbst darf die eigentliche,
 * bereits erfolgte Aktion nicht nachtraeglich als Fehler erscheinen
 * lassen - daher wird hier bewusst nichts weitergeworfen.
 */
function mv_log(PDO $pdo, int $jahr, ?array $mitglied, string $was, string $sql): void {
    try {
        mvlogs_tabelle_sicherstellen($pdo, $jahr);

        $mnr = $mitglied['MNr'] ?? '';
        $kurzn = $mitglied
            ? trim(mb_substr($mitglied['Vorname'] ?? '', 0, 10) . ' ' . mb_substr($mitglied['Name'] ?? '', 0, 4))
            : '';

        $tabelle = 'mvlogs_' . $jahr;
        $stmt = $pdo->prepare("INSERT INTO `$tabelle` (MNr, KurzN, zeit, was, string) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$mnr, $kurzn, date('Y-m-d H:i:s'), $was, $sql]);
    } catch (PDOException $e) {
        // Protokollierung ist Zusatznutzen, kein Grund, die bereits
        // ausgefuehrte Aktion fuer die Nutzenden fehlschlagen zu lassen.
    }
}

/** Stellt einen Wert lesbar als SQL-Literal dar (rein informativ, siehe mv_log). */
function sql_literal($wert): string {
    if ($wert === null) {
        return 'NULL';
    }
    if (is_int($wert) || is_float($wert)) {
        return (string)$wert;
    }
    if (is_bool($wert)) {
        return $wert ? '1' : '0';
    }
    return "'" . str_replace("'", "''", (string)$wert) . "'";
}

/** @param array<string,mixed> $werte Spalte => Wert */
function sql_insert(string $tabelle, array $werte): string {
    $spalten = implode(', ', array_keys($werte));
    $literale = implode(', ', array_map('sql_literal', array_values($werte)));
    return "INSERT INTO $tabelle ($spalten) VALUES ($literale)";
}

/**
 * @param array<string,mixed> $werte Spalte => neuer Wert
 * @param array<string,mixed> $bedingung Spalte => Wert fuer die WHERE-Bedingung
 */
function sql_update(string $tabelle, array $werte, array $bedingung): string {
    $set = implode(', ', array_map(
        fn($spalte, $wert) => "$spalte = " . sql_literal($wert),
        array_keys($werte), array_values($werte)
    ));
    $where = implode(' AND ', array_map(
        fn($spalte, $wert) => "$spalte = " . sql_literal($wert),
        array_keys($bedingung), array_values($bedingung)
    ));
    return "UPDATE $tabelle SET $set WHERE $where";
}

/** @param array<string,mixed> $bedingung Spalte => Wert fuer die WHERE-Bedingung */
function sql_delete(string $tabelle, array $bedingung): string {
    $where = implode(' AND ', array_map(
        fn($spalte, $wert) => "$spalte = " . sql_literal($wert),
        array_keys($bedingung), array_values($bedingung)
    ));
    return "DELETE FROM $tabelle WHERE $where";
}

/** Liest das Protokoll eines Jahres, neueste zuerst, optional nach Stichwort gefiltert. */
function get_protokoll(PDO $pdo, int $jahr, string $stichwort = '', int $limit = 200): array {
    mvlogs_tabelle_sicherstellen($pdo, $jahr);
    $tabelle = 'mvlogs_' . $jahr;

    $bedingung = '';
    $params = [];
    if ($stichwort !== '') {
        $bedingung = 'WHERE MNr LIKE ? OR KurzN LIKE ? OR was LIKE ? OR string LIKE ?';
        $wie = '%' . $stichwort . '%';
        $params = [$wie, $wie, $wie, $wie];
    }

    $stmt = $pdo->prepare("SELECT * FROM `$tabelle` $bedingung ORDER BY zeit DESC LIMIT " . (int)$limit);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
