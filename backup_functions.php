<?php
/**
 * backup_functions.php - Jederzeit per Admin-Knopfdruck auslösbares
 * Datenbank-Backup der VERA/BEAM-eigenen Tabellen (siehe datenbank_backup.php).
 *
 * Ergaenzt die rein manuelle phpMyAdmin-Sicherung um eine Moeglichkeit, die
 * waehrend einer laufenden Versammlung ohne Admin-Zugriff auf die Datenbank
 * selbst genutzt werden kann - reine PHP/PDO-Loesung (kein mysqldump/
 * shell_exec noetig), funktioniert daher unabhaengig davon, ob der Server
 * Shell-Zugriff erlaubt.
 *
 * Bewusst NICHT die komplette Datenbank: svmembers/mitgliederverwaltungs-
 * eigene und sonstige von Schwesteranwendungen (MISS/KONA/KAND) genutzte
 * Tabellen gehoeren nicht zu VERA/BEAM und werden ausgelassen (siehe
 * vera_backup_tabellen()).
 */

/**
 * Alle VERA/BEAM-eigenen Tabellen der aktuellen Datenbank (Praefix mv/
 * beam_, plus mitglieder_stamm als einzige Ausnahme vom Praefix-Muster -
 * siehe mitgliederquelle_functions.php).
 */
function vera_backup_tabellen(PDO $pdo): array {
    $stmt = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
           AND (TABLE_NAME LIKE 'mv%' OR TABLE_NAME LIKE 'beam\\_%' OR TABLE_NAME = 'mitglieder_stamm')
         ORDER BY TABLE_NAME"
    );
    return array_column($stmt->fetchAll(), 'TABLE_NAME');
}

/**
 * Erzeugt ein vollstaendiges, wiedereinspielbares SQL-Backup (DROP+CREATE+
 * INSERT) aller VERA/BEAM-Tabellen, in Haeppchen von 500 Zeilen pro
 * INSERT-Anweisung (vermeidet zu lange Einzelanweisungen bei grossen
 * Tabellen, z.B. vielen Stimmkarten-Token).
 */
function datenbank_backup_erzeugen(PDO $pdo): string {
    $tabellen = vera_backup_tabellen($pdo);
    $sql = "-- VERA/BEAM Datenbank-Backup, erzeugt " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Enthaelt " . count($tabellen) . " Tabelle(n): " . implode(', ', $tabellen) . "\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tabellen as $tabelle) {
        $create = $pdo->query("SHOW CREATE TABLE `$tabelle`")->fetch();
        $sql .= "DROP TABLE IF EXISTS `$tabelle`;\n";
        $sql .= $create['Create Table'] . ";\n\n";

        $rows = $pdo->query("SELECT * FROM `$tabelle`");
        $rows->setFetchMode(PDO::FETCH_ASSOC);
        $spalten = null;
        $haeppchen = [];
        $anzahl = 0;
        foreach ($rows as $row) {
            if ($spalten === null) {
                $spalten = array_keys($row);
            }
            $haeppchen[] = '(' . implode(',', array_map(
                fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v),
                $row
            )) . ')';
            $anzahl++;
            if (count($haeppchen) >= 500) {
                $sql .= "INSERT INTO `$tabelle` (`" . implode('`,`', $spalten) . "`) VALUES\n" . implode(",\n", $haeppchen) . ";\n";
                $haeppchen = [];
            }
        }
        if ($haeppchen) {
            $sql .= "INSERT INTO `$tabelle` (`" . implode('`,`', $spalten) . "`) VALUES\n" . implode(",\n", $haeppchen) . ";\n";
        }
        $sql .= "-- $tabelle: $anzahl Zeile(n)\n\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $sql;
}
