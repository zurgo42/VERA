<?php
/**
 * diagnose_jahr.php - Einmaliges Diagnose-/Reparaturwerkzeug fuer den Fall,
 * dass get_aktuelles_jahr() ein anderes Jahr liefert als eine direkt in
 * phpMyAdmin ausgefuehrte, identische SQL-Abfrage.
 *
 * Hintergrund: Wenn dieselbe Abfrage (SELECT jahr FROM mvtexte WHERE
 * jahr < 1000000 ORDER BY jahr DESC) in phpMyAdmin die erwarteten Zeilen
 * liefert, PHP darueber aber ein falsches/aelteres Jahr berechnet, kann das
 * nur an der PHP-Seite liegen - entweder laeuft dort (trotz hochgeladener
 * neuer Dateien) noch eine per OPcache zwischengespeicherte alte Version von
 * db_functions.php, oder die Anwendung verbindet sich tatsaechlich mit einer
 * anderen Datenbank als der, die in phpMyAdmin geoeffnet ist. Dieses Skript
 * zeigt beides direkt nebeneinander, damit sich das ohne Server-Zugriff
 * unterscheiden laesst - und bietet einen Knopf, um einen eventuell
 * veralteten OPcache einmalig zu leeren.
 *
 * Sicherheitshinweis wie bei den anderen tools/-Skripten: nach Gebrauch
 * loeschen oder vor erneutem Aufruf schuetzen. Zeigt keine Passwoerter an.
 */

require_once __DIR__ . '/../functions_neu.php';

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Jahr-Diagnose</title></head><body style="font-family:sans-serif; max-width:820px; margin:30px auto; line-height:1.6;">';
echo '<h2>Jahr-Diagnose</h2>';

if (isset($_GET['opcache_reset'])) {
    if (function_exists('opcache_reset')) {
        $ok = opcache_reset();
        echo '<p style="padding:10px; border-left:4px solid ' . ($ok ? '#28a745' : '#dc3545') . ';">'
            . ($ok ? '✓ OPcache wurde geleert.' : '✗ opcache_reset() ist fehlgeschlagen.') . '</p>';
    } else {
        echo '<p style="padding:10px; border-left:4px solid #dc3545;">✗ opcache_reset() steht auf diesem Server nicht zur Verfügung (PHP-Funktion nicht vorhanden/deaktiviert).</p>';
    }
    echo '<p><a href="diagnose_jahr.php">Diagnose erneut anzeigen</a></p>';
    echo '</body></html>';
    exit;
}

if (isset($_GET['spalte_korrigieren'])) {
    try {
        $pdo = get_pdo();
        $alle = $pdo->query('SELECT jahr FROM mvtexte')->fetchAll();
        $nicht_numerisch = array_filter($alle, fn($z) => !ctype_digit((string)$z['jahr']));
        if ($nicht_numerisch) {
            echo '<p style="color:red;">✗ Abgebrochen: mindestens ein jahr-Wert ist keine reine Zahl ('
                . h(implode(', ', array_column($nicht_numerisch, 'jahr'))) . '). Keine Änderung vorgenommen.</p>';
        } else {
            $pdo->exec('ALTER TABLE mvtexte MODIFY COLUMN jahr INT NOT NULL');
            echo '<p style="padding:10px; border-left:4px solid #28a745;">✓ Spalte mvtexte.jahr ist jetzt vom Typ INT.</p>';
            echo '<p>get_aktuelles_jahr() liefert jetzt: <strong style="font-size:1.3em;">' . h((string)get_aktuelles_jahr($pdo)) . '</strong></p>';
        }
    } catch (PDOException $e) {
        echo '<p style="color:red;">✗ Fehler: ' . h($e->getMessage()) . '</p>';
    }
    echo '<p><a href="diagnose_jahr.php">Diagnose erneut anzeigen</a></p>';
    echo '</body></html>';
    exit;
}

if (isset($_GET['spalte_zuruecksetzen'])) {
    try {
        $pdo = get_pdo();
        $pdo->exec('ALTER TABLE mvtexte MODIFY COLUMN jahr VARCHAR(10) NOT NULL');
        echo '<p style="padding:10px; border-left:4px solid #28a745;">✓ Spalte mvtexte.jahr ist wieder vom Typ VARCHAR(10) - wie vor der Umstellung.</p>';
        echo '<p>get_aktuelles_jahr() liefert jetzt: <strong style="font-size:1.3em;">' . h((string)get_aktuelles_jahr($pdo)) . '</strong> '
            . '(sollte unverändert korrekt sein, sofern db_functions.php/tab_einstellungen.php bereits mit CAST(jahr AS UNSIGNED) aktualisiert wurden)</p>';
    } catch (PDOException $e) {
        echo '<p style="color:red;">✗ Fehler: ' . h($e->getMessage()) . '</p>';
    }
    echo '<p><a href="diagnose_jahr.php">Diagnose erneut anzeigen</a></p>';
    echo '</body></html>';
    exit;
}

if (isset($_GET['analyze_table'])) {
    try {
        $pdo = get_pdo();
        $stmt = $pdo->query('ANALYZE TABLE mvtexte');
        echo '<h3>ANALYZE TABLE mvtexte</h3><table border="1" cellpadding="6" style="border-collapse:collapse;">';
        foreach ($stmt->fetchAll() as $zeile) {
            echo '<tr>';
            foreach ($zeile as $k => $v) {
                if (is_int($k)) continue;
                echo '<td>' . h((string)$v) . '</td>';
            }
            echo '</tr>';
        }
        echo '</table>';
        echo '<p>get_aktuelles_jahr() liefert jetzt: <strong style="font-size:1.3em;">' . h((string)get_aktuelles_jahr($pdo)) . '</strong></p>';
    } catch (PDOException $e) {
        echo '<p style="color:red;">✗ Fehler: ' . h($e->getMessage()) . '</p>';
    }
    echo '<p><a href="diagnose_jahr.php">Diagnose erneut anzeigen</a></p>';
    echo '</body></html>';
    exit;
}

try {
    $pdo = get_pdo();

    echo '<h3>1. Datenbankverbindung</h3>';
    $db = $pdo->query('SELECT DATABASE() AS db')->fetch();
    echo '<p>Aktuell verbundene Datenbank: <strong>' . h((string)($db['db'] ?? '?')) . '</strong><br>';
    echo 'MYSQL_HOST aus config.php: <strong>' . h(defined('MYSQL_HOST') ? MYSQL_HOST : '(nicht definiert)') . '</strong></p>';

    echo '<h3>2. Direkte Abfrage (wie in phpMyAdmin getestet)</h3>';
    $stmt = $pdo->prepare('SELECT jahr FROM mvtexte WHERE jahr < 1000000 ORDER BY jahr DESC');
    $stmt->execute();
    $jahre = array_column($stmt->fetchAll(), 'jahr');
    echo '<p>Gefundene Jahre: <strong>' . (count($jahre) ? h(implode(', ', $jahre)) : '(keine)') . '</strong></p>';

    echo '<h3>3. Ergebnis der PHP-Funktion get_aktuelles_jahr()</h3>';
    echo '<p>BEAM_PROJEKT_ID_START (Konstante in db_functions.php): <strong>'
        . (defined('BEAM_PROJEKT_ID_START') ? h((string)BEAM_PROJEKT_ID_START) : '(nicht definiert!)') . '</strong></p>';
    echo '<p>get_aktuelles_jahr() liefert: <strong style="font-size:1.3em;">' . h((string)get_aktuelles_jahr($pdo)) . '</strong></p>';

    echo '<h3>3b. MAX(jahr) im Detail (zur Eingrenzung)</h3>';
    $max_literal = $pdo->query('SELECT MAX(jahr) AS j FROM mvtexte WHERE jahr < 1000000')->fetch();
    echo '<p>MAX(jahr), Wert fest im SQL-Text (nicht gebunden): <strong>' . h((string)($max_literal['j'] ?? '(NULL)')) . '</strong></p>';
    $stmt2 = $pdo->prepare('SELECT MAX(jahr) AS j FROM mvtexte WHERE jahr < ?');
    $stmt2->execute([BEAM_PROJEKT_ID_START]);
    $max_gebunden = $stmt2->fetch();
    echo '<p>MAX(jahr), Wert als Parameter gebunden (wie in get_aktuelles_jahr()): <strong>' . h((string)($max_gebunden['j'] ?? '(NULL)')) . '</strong></p>';

    echo '<h3>3c2. Ohne MySQL-Query-Cache (SQL_NO_CACHE)</h3>';
    try {
        $no_cache = $pdo->query('SELECT SQL_NO_CACHE MAX(jahr) AS j FROM mvtexte WHERE jahr < 1000000')->fetch();
        echo '<p>MAX(jahr) mit SQL_NO_CACHE: <strong>' . h((string)($no_cache['j'] ?? '(NULL)')) . '</strong></p>';
    } catch (PDOException $e) {
        echo '<p style="color:#856404;">(SQL_NO_CACHE wird von diesem Server nicht unterstützt: ' . h($e->getMessage()) . ')</p>';
    }
    try {
        $qc = $pdo->query("SHOW VARIABLES LIKE 'query_cache%'")->fetchAll();
        if ($qc) {
            echo '<p>Query-Cache-Einstellungen:<br>';
            foreach ($qc as $zeile) {
                echo h((string)$zeile['Variable_name']) . ' = ' . h((string)$zeile['Value']) . '<br>';
            }
            echo '</p>';
        }
    } catch (PDOException $e) {
        // ignorieren, rein informativ
    }
    $server_info = $pdo->query('SELECT @@hostname AS h, NOW() AS jetzt, @@version AS version')->fetch();
    echo '<p>DB-Server: <strong>' . h((string)($server_info['h'] ?? '?')) . '</strong>, Version ' . h((string)($server_info['version'] ?? '?'))
        . ', Serverzeit laut DB: ' . h((string)($server_info['jetzt'] ?? '?')) . '</p>';

    echo '<h3>3a. Spaltentyp von mvtexte.jahr</h3>';
    $spalte = $pdo->query(
        "SELECT COLUMN_TYPE, DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mvtexte' AND COLUMN_NAME = 'jahr'"
    )->fetch();
    echo '<p>Tatsächlicher Spaltentyp laut Datenbank: <strong style="font-size:1.2em;">'
        . h((string)($spalte['COLUMN_TYPE'] ?? '(nicht gefunden)')) . '</strong></p>';
    if ($spalte && strtolower((string)$spalte['DATA_TYPE']) === 'int') {
        echo '<p style="padding:10px; background:#fff3cd; border-left:4px solid #ffc107;">'
            . 'Die Spalte ist aktuell INT. Falls ein anderes (altes) Skript auf dieselbe Tabelle zugreift und dort einen '
            . 'VARCHAR-Wert erwartet (z. B. per strengem Typvergleich === gegen einen String), kann das zu Fehlfunktionen dort führen. '
            . 'get_aktuelles_jahr()/tab_einstellungen.php in diesem Projekt kommen seit dem CAST(jahr AS UNSIGNED)-Fix mit beiden Typen zurecht.</p>';
        echo '<p><a href="diagnose_jahr.php?spalte_zuruecksetzen=1" style="display:inline-block; padding:8px 16px; background:#6c757d; color:#fff; text-decoration:none; border-radius:4px;" onclick="return confirm(\'mvtexte.jahr zurück auf VARCHAR(10) stellen?\');">Spalte zurück auf VARCHAR stellen</a></p>';
    } elseif ($spalte) {
        echo '<p style="padding:10px; background:#f8d7da; border-left:4px solid #dc3545;">'
            . '<strong>Mögliche Ursache für falsche Jahresermittlung:</strong> Die Spalte ist kein INT, sondern "' . h((string)$spalte['COLUMN_TYPE'])
            . '". CREATE TABLE IF NOT EXISTS ändert eine bereits bestehende Tabelle nie - diese Spalte stammt wahrscheinlich '
            . 'noch aus dem alten System. Seit dem CAST(jahr AS UNSIGNED)-Fix in get_aktuelles_jahr()/tab_einstellungen.php ist eine '
            . 'Umstellung auf INT nicht mehr nötig - nur nötig, falls ein anderes (neueres) Skript ausdrücklich INT erwartet.</p>';
        echo '<p><a href="diagnose_jahr.php?spalte_korrigieren=1" style="display:inline-block; padding:8px 16px; background:#dc3545; color:#fff; text-decoration:none; border-radius:4px;" onclick="return confirm(\'mvtexte.jahr jetzt dauerhaft auf INT umstellen? Vorher Backup empfohlen.\');">Spalte jetzt auf INT umstellen</a></p>';
    }

    echo '<h3>3c. CHECK TABLE mvtexte</h3>';
    $check = $pdo->query('CHECK TABLE mvtexte')->fetchAll();
    echo '<table border="1" cellpadding="6" style="border-collapse:collapse;">';
    foreach ($check as $zeile) {
        echo '<tr><td>' . h((string)$zeile['Msg_type']) . '</td><td>' . h((string)$zeile['Msg_text']) . '</td></tr>';
    }
    echo '</table>';
    echo '<p><a href="diagnose_jahr.php?analyze_table=1" style="display:inline-block; padding:8px 16px; background:#007bff; color:#fff; text-decoration:none; border-radius:4px;">ANALYZE TABLE mvtexte ausführen (ungefährlich, aktualisiert nur Statistiken)</a></p>';

    echo '<h3>4. Datei-Stand auf dem Server</h3>';
    $db_functions_pfad = __DIR__ . '/../db_functions.php';
    echo '<p>db_functions.php zuletzt geändert: <strong>' . h(date('d.m.Y H:i:s', filemtime($db_functions_pfad))) . '</strong> (Server-Zeit)<br>';
    echo 'Prüfsumme (md5): <code>' . h(md5_file($db_functions_pfad)) . '</code></p>';

    echo '<h3>5. OPcache (PHP-Bytecode-Cache)</h3>';
    if (function_exists('opcache_get_status') && ($status = opcache_get_status(false)) !== false) {
        $enabled = $status['opcache_enabled'] ?? false;
        echo '<p>OPcache aktiv: <strong>' . ($enabled ? 'ja' : 'nein') . '</strong></p>';
        if ($enabled && function_exists('opcache_is_script_cached') && opcache_is_script_cached($db_functions_pfad)) {
            echo '<p style="padding:10px; background:#fff3cd; border-left:4px solid #ffc107;">'
                . '⚠ db_functions.php liegt aktuell im OPcache. Falls oben bei Punkt 2 andere Jahre stehen als bei Punkt 3, '
                . 'läuft hier wahrscheinlich noch eine ältere, zwischengespeicherte Version dieser Datei - '
                . 'auch wenn auf der Festplatte (Punkt 4) schon die neue liegt.</p>';
            echo '<p><a href="diagnose_jahr.php?opcache_reset=1" style="display:inline-block; padding:8px 16px; background:#dc3545; color:#fff; text-decoration:none; border-radius:4px;">OPcache jetzt leeren</a></p>';
        } elseif ($enabled) {
            echo '<p>db_functions.php ist aktuell nicht im OPcache zwischengespeichert.</p>';
        }
    } else {
        echo '<p>OPcache-Status ist auf diesem Server nicht abrufbar.</p>';
    }

    if (count($jahre) > 0 && (int)get_aktuelles_jahr($pdo) !== (int)max($jahre)) {
        echo '<hr><p style="padding:10px; background:#f8d7da; border-left:4px solid #dc3545;">'
            . '<strong>Widerspruch:</strong> Die direkte Abfrage (Punkt 2) findet das Jahr ' . h((string)max($jahre))
            . ', get_aktuelles_jahr() (Punkt 3) aber ' . h((string)get_aktuelles_jahr($pdo)) . '. '
            . 'Das deutet auf eine veraltete, zwischengespeicherte Version von db_functions.php hin (siehe Punkt 5) - '
            . 'nicht auf ein Problem in der Datenbank selbst.</p>';
    }
} catch (PDOException $e) {
    echo '<p style="color:red;">✗ Fehler: ' . h($e->getMessage()) . '</p>';
}

echo '<hr><p style="color:#856404; background:#fff3cd; border-left:4px solid #ffc107; padding:12px;"><strong>Bitte dieses Skript danach löschen oder vor erneutem Aufruf schützen.</strong></p>';
echo '</body></html>';
