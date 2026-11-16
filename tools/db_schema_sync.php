<?php
/**
 * db_schema_sync.php - Fuehrt alle *_sicherstellen()-Funktionen einmal
 * explizit aus (CREATE TABLE IF NOT EXISTS / ALTER TABLE ADD COLUMN).
 *
 * Hintergrund: Diese Funktionen laufen im Normalbetrieb automatisch bei
 * jedem Seitenaufruf mit (selbstheilendes Schema - praktisch fuer
 * Entwicklung und neue Installationen). Ist in config.php
 * DB_SELBSTHEILUNG_AKTIV=false gesetzt (empfohlen im Produktivbetrieb mit
 * vielen gleichzeitigen Anfragen, siehe selbstheilung_aktiv() in
 * db_functions.php), unterbleibt das automatische Pruefen - Schema-
 * Aenderungen aus einem neuen Deployment muessen dann EINMALIG hierueber
 * nachgezogen werden, bevor die neue Version produktiv genutzt wird.
 *
 * Sollte Teil der Deployment-Routine sein: nach jedem Hochladen neuer
 * Dateien einmal aufrufen. Sicherheitshinweis wie bei den anderen
 * tools/-Skripten: nach Gebrauch loeschen oder vor erneutem Aufruf
 * schuetzen.
 */

require_once __DIR__ . '/../functions_neu.php';
require_once __DIR__ . '/../mitglieder_functions.php';
require_once __DIR__ . '/../jahr_functions.php';
require_once __DIR__ . '/../antraege_functions.php';
require_once __DIR__ . '/../unterstuetzung_functions.php';
require_once __DIR__ . '/../votum_functions.php';
require_once __DIR__ . '/../protokoll_functions.php';
require_once __DIR__ . '/../tagesordnung_functions.php';
require_once __DIR__ . '/../unterlagen_functions.php';
require_once __DIR__ . '/../mitgliederquelle_functions.php';
require_once __DIR__ . '/../login_functions.php';
require_once __DIR__ . '/../abstimmung_functions.php';
require_once __DIR__ . '/../ausweis_functions.php';
require_once __DIR__ . '/../wahl_functions.php';
require_once __DIR__ . '/../beam/beam_functions.php';

// Erzwingt die Pruefung/Anlage trotz DB_SELBSTHEILUNG_AKTIV=false in
// config.php - sonst wuerden sich alle *_sicherstellen()-Funktionen hier
// selbst uebergehen (siehe selbstheilung_aktiv() in db_functions.php).
$GLOBALS['selbstheilung_erzwingen'] = true;

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Schema-Abgleich</title></head><body style="font-family:sans-serif; max-width:720px; margin:30px auto; line-height:1.5;">';
echo '<h2>Schema-Abgleich</h2>';

try {
    $pdo = get_pdo();
    echo '<p style="color:green;">✓ Datenbankverbindung hergestellt.</p>';

    // Global (jahresunabhaengig)
    $global = [
        'mvtexte-Spalten'            => fn() => mvtexte_spalten_sicherstellen($pdo),
        'Mitgliederstamm-Tabelle'    => fn() => mitgliederstamm_tabelle_sicherstellen($pdo),
        'Login-Token-Tabelle'        => fn() => login_tokens_tabelle_sicherstellen($pdo),
        'Ausweis-Token-Tabelle'      => fn() => ausweis_tokens_tabelle_sicherstellen($pdo),
        'Abstimmungs-Token-Tabelle'  => fn() => vote_tokens_tabelle_sicherstellen($pdo),
        'Abstimmungsrunden-Tabelle'  => fn() => vote_rounds_tabelle_sicherstellen($pdo),
        'Stimmzettel-Tabelle'        => fn() => vote_ballots_tabelle_sicherstellen($pdo),
        'Online-Abstimmungstoken-Tabellen' => fn() => online_tabellen_sicherstellen($pdo),
        'Wahlgaenge-Tabellen'        => fn() => wahlgaenge_tabellen_sicherstellen($pdo),
        'Kandidatenstimmen-Tabelle'  => fn() => wahl_stimmen_tabelle_sicherstellen($pdo),
        'Wahlergebnisse-Tabelle'     => fn() => wahl_ergebnisse_tabelle_sicherstellen($pdo),
        'BEAM-Tabellen'              => fn() => beam_tabellen_sicherstellen($pdo),
        'Tagesordnung'               => fn() => mvtop_tabellen_sicherstellen($pdo),
        'Dateien-Tabelle'            => fn() => mvdateien_spalten_sicherstellen($pdo),
    ];
    foreach ($global as $label => $fn) {
        try {
            $fn();
            echo '<p style="color:green;">✓ ' . h($label) . '</p>';
        } catch (Throwable $e) {
            echo '<p style="color:red;">✗ ' . h($label) . ': ' . h($e->getMessage()) . '</p>';
        }
    }

    // Jahresabhaengig - nur das aktuelle (neueste) Jahr; aeltere Jahre
    // haben ihr Schema bereits und bekommen keine neuen Spalten mehr
    // (siehe nur_aktuelles_jahr_bearbeitbar_oder_abbrechen()).
    $jahr = get_aktuelles_jahr($pdo);
    echo '<h3>Jahresabhängig (' . h((string)$jahr) . ')</h3>';
    $jahresabhaengig = [
        'Mitgliedertabelle'   => fn() => mvmitglieder_tabelle_sicherstellen($pdo, $jahr),
        'Anträge-Tabelle'     => fn() => antrag_spalten_sicherstellen($pdo, $jahr),
        'Protokoll-Tabelle'   => fn() => mvlogs_tabelle_sicherstellen($pdo, $jahr),
        'Unterstützungen'     => fn() => unterst_tabelle_sicherstellen($pdo, $jahr),
        'Voten-Tabelle'       => fn() => votum_tabelle_sicherstellen($pdo, $jahr),
    ];
    foreach ($jahresabhaengig as $label => $fn) {
        try {
            $fn();
            echo '<p style="color:green;">✓ ' . h($label) . '</p>';
        } catch (Throwable $e) {
            echo '<p style="color:red;">✗ ' . h($label) . ': ' . h($e->getMessage()) . '</p>';
        }
    }

    echo '<hr><p style="font-weight:bold;">Abgleich abgeschlossen.</p>';
    echo '<p style="color:#856404; background:#fff3cd; border-left:4px solid #ffc107; padding:12px;"><strong>Bitte dieses Skript danach löschen oder vor erneutem Aufruf schützen.</strong></p>';
} catch (PDOException $e) {
    echo '<p style="color:red;">✗ Fehler: ' . h($e->getMessage()) . '</p>';
}

echo '</body></html>';
