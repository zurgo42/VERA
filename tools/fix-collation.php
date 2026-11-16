<?php
/**
 * fix-collation.php - Vereinheitlicht die Collation ALLER Tabellen der
 * aktuellen Datenbank auf utf8mb4_unicode_ci.
 *
 * Hintergrund: Auf manchen (insbesondere lokalen/MySQL-8-) Installationen
 * entstehen neu angelegte Tabellen mit der Standard-Collation
 * utf8mb4_0900_ai_ci - eine MySQL-8-spezifische Collation, die MariaDB
 * nicht kennt. Kopiert man Tabellen von dort per Tool (z. B. Navicat,
 * phpMyAdmin) auf eine MariaDB-Zieldatenbank, scheitert das mit
 * "1273 - Unknown collation: 'utf8mb4_0900_ai_ci'", weil das Kopier-Tool
 * die tatsächlich gespeicherte Collation der Quelltabelle mitkopiert - rein
 * serverseitiger Code (wie init-db.php, siehe dort) hat darauf keinen
 * Einfluss, da das Kopieren außerhalb der Anwendung passiert.
 *
 * Dieses Skript auf der QUELLDATENBANK ausführen, BEVOR man Tabellen von
 * dort in eine andere Datenbank kopiert - es stellt per
 * ALTER TABLE ... CONVERT TO CHARACTER SET um (ändert dabei wirklich jede
 * Spalte, nicht nur die Tabellen-Vorgabe für künftige neue Spalten) und
 * passt zusätzlich die Datenbank-Vorgabe selbst an, damit auch künftig neu
 * angelegte Tabellen/Spalten nicht wieder in dieselbe Falle laufen.
 *
 * utf8mb4_unicode_ci wurde als Ziel gewählt, weil es überall (MySQL wie
 * MariaDB, alte wie neue Versionen) unterstützt wird und bereits an
 * mehreren Stellen im Projekt verwendet wird (siehe
 * migrations/004_mvbeschluesse.sql und die seit dem Collation-Fix
 * explizite Angabe in allen *_tabelle(n)_sicherstellen()-Funktionen).
 *
 * Betrifft ALLE Tabellen der aktuell in config.php eingetragenen
 * Datenbank, nicht nur VERA/BEAM-eigene - falls diese Datenbank auch von
 * Geschwisteranwendungen (MISS/KONA/KAND) mitgenutzt wird, werden deren
 * Tabellen (z. B. svmembers) ebenfalls umgestellt. Views werden
 * übersprungen (CONVERT TO CHARACTER SET ist dafür nicht sinnvoll). Rein
 * additiv/datenerhaltend - Inhalte bleiben unverändert, nur die interne
 * Zeichensatz-/Sortierregel wird umgestellt.
 *
 * Sicherheitshinweis wie bei init-db.php: nach Gebrauch löschen oder vor
 * erneutem Aufruf schützen, und vorher ein Backup der Quelldatenbank
 * ziehen (ALTER TABLE ... CONVERT TO CHARACTER SET schreibt jede
 * betroffene Tabelle komplett neu - bei sehr großen Tabellen entsprechend
 * langsam, hier aber unkritisch, da als einmaliges Admin-Werkzeug gedacht).
 */

require_once __DIR__ . '/../functions_neu.php';

const ZIEL_COLLATION = 'utf8mb4_unicode_ci';

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Collation vereinheitlichen</title></head><body style="font-family:sans-serif; max-width:720px; margin:30px auto; line-height:1.5;">';
echo '<h2>Collation vereinheitlichen</h2>';

try {
    $pdo = get_pdo();
    echo '<p style="color:green;">✓ Datenbankverbindung hergestellt.</p>';

    $stmt = $pdo->prepare(
        'SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\'
         ORDER BY TABLE_NAME'
    );
    $stmt->execute();
    $tabellen = $stmt->fetchAll();

    $umgestellt = 0;
    $uebersprungen = 0;
    foreach ($tabellen as $t) {
        $name = $t['TABLE_NAME'];
        if ($t['TABLE_COLLATION'] === ZIEL_COLLATION) {
            $uebersprungen++;
            continue;
        }
        try {
            $pdo->exec('ALTER TABLE `' . $name . '` CONVERT TO CHARACTER SET utf8mb4 COLLATE ' . ZIEL_COLLATION);
            echo '<p style="color:green;">✓ ' . h($name) . ' (' . h((string)$t['TABLE_COLLATION']) . ' → ' . ZIEL_COLLATION . ')</p>';
            $umgestellt++;
        } catch (PDOException $e) {
            echo '<p style="color:red;">✗ ' . h($name) . ': ' . h($e->getMessage()) . '</p>';
        }
    }

    // Datenbank-Vorgabe selbst mit umstellen, damit künftig ohne eigene
    // Angabe neu angelegte Tabellen/Spalten ebenfalls die passende
    // Collation erben, statt wieder in dieselbe Falle zu laufen.
    $pdo->exec('ALTER DATABASE CHARACTER SET utf8mb4 COLLATE ' . ZIEL_COLLATION);
    echo '<p style="color:green;">✓ Datenbank-Vorgabe selbst auf ' . ZIEL_COLLATION . ' umgestellt.</p>';

    echo '<hr><p style="font-weight:bold;">' . $umgestellt . ' Tabelle(n) umgestellt, ' . $uebersprungen . ' bereits korrekt.</p>';
    echo '<p style="color:#856404; background:#fff3cd; border-left:4px solid #ffc107; padding:12px;"><strong>Bitte dieses Skript danach löschen oder vor erneutem Aufruf schützen.</strong></p>';
} catch (PDOException $e) {
    echo '<p style="color:red;">✗ Fehler: ' . h($e->getMessage()) . '</p>';
}

echo '</body></html>';
