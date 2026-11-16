<?php
/**
 * init-db.php - Datenbank initialisieren (für eine neue VERA/BEAM-Instanz).
 *
 * VERA legt seine Tabellen normalerweise "selbstheilend" an: jede
 * Funktion wie z. B. vote_tokens_tabelle_sicherstellen() prüft beim
 * ersten tatsächlichen Gebrauch per CREATE TABLE IF NOT EXISTS/ALTER
 * TABLE, ob ihre Tabelle/Spalten schon existieren, und legt sie sonst an -
 * ein Admin muss also nirgends von Hand ein Schema einspielen, und dieses
 * Skript dupliziert absichtlich KEIN eigenes CREATE-TABLE-Schema (das
 * würde über kurz oder lang vom tatsächlichen, in den *_functions.php
 * gepflegten Stand abweichen). Es ruft stattdessen einmalig genau diese
 * vorhandenen Selbstheilungsfunktionen der Reihe nach auf - praktisch,
 * wenn (wie in den Schwesterprojekten KONA/MISS/KAND üblich) eine neue
 * Instanz auf einen Schlag einsatzbereit sein soll, ohne erst jede
 * einzelne Funktion über die Oberfläche anzustoßen.
 *
 * Legt dabei auch gleich das erste (aktuelle) MV-Jahr an, über
 * neues_jahr_anlegen() - genau die Funktion, die auch "Neues Jahr
 * anlegen" in der Oberfläche nutzt; bei einer komplett neuen Installation
 * (kein Vorjahr vorhanden) erzeugt sie die jahr-gebundenen Pflichttabellen
 * ebenfalls über die Selbstheilungsfunktionen, ohne Funktionsträger:innen
 * zu übernehmen (die gibt es ja noch nicht).
 *
 * KEINE Demo-Daten (siehe demo_daten_functions.php für Demo-Login-Daten,
 * separat über ?demo_mnr=... bei aktiviertem DEMO_MODE in config.php) und
 * KEIN eigenes Benutzerkonto - VERA hat kein eigenes Login-System, die
 * Anmeldung läuft über REMOTE_USER (vorgelagertes SSO), VERAs eigenes
 * login.php, oder die familienweite Sitzung aus Adapter/MISS/KONA/KAND
 * (siehe get_aktuelle_mnr() in functions_neu.php und dokumentation/BEAM.md).
 *
 * Sicherheitshinweis wie bei den Schwesterprojekten: nach der
 * Ersteinrichtung löschen oder zumindest per .htaccess/Dateirechte vor
 * erneutem Aufruf schützen - unbefugt erneut aufgerufen richtet das
 * Skript zwar nichts an (alle Operationen sind idempotent), zeigt aber
 * Interna der Datenbankstruktur.
 */

require_once __DIR__ . '/../functions_neu.php';
require_once __DIR__ . '/../antraege_functions.php';
require_once __DIR__ . '/../mitglieder_functions.php';
require_once __DIR__ . '/../login_functions.php';
require_once __DIR__ . '/../tagesordnung_functions.php';
require_once __DIR__ . '/../unterlagen_functions.php';
require_once __DIR__ . '/../unterstuetzung_functions.php';
require_once __DIR__ . '/../votum_functions.php';
require_once __DIR__ . '/../wahl_functions.php';
require_once __DIR__ . '/../abstimmung_functions.php';
require_once __DIR__ . '/../protokoll_functions.php';
require_once __DIR__ . '/../jahr_functions.php';
require_once __DIR__ . '/../beam/beam_functions.php';

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>VERA/BEAM - Datenbank initialisieren</title></head><body style="font-family:sans-serif; max-width:720px; margin:30px auto; line-height:1.5;">';
echo '<h2>Datenbank-Initialisierung</h2>';

try {
    $pdo = get_pdo();
    echo '<p style="color:green;">✓ Datenbankverbindung hergestellt.</p>';

    // Grundtabelle fuer den Beamerstatus (VERA-Jahre UND BEAM-Projekte,
    // siehe BEAM_PROJEKT_ID_START in db_functions.php) - alles andere baut
    // darauf auf.
    mvtexte_spalten_sicherstellen($pdo);
    echo '<p style="color:green;">✓ mvtexte (Vereins-/Sitzungsdaten, Beamerstatus) sichergestellt.</p>';

    // Erstes MV-Jahr anlegen, falls noch keines existiert - legt dabei
    // ueber dieselbe Funktion wie "Neues Jahr anlegen" in der Oberflaeche
    // auch gleich mvmitglieder_<jahr>/mvantraege_<jahr> an.
    $jahr = (int)date('Y');
    if (!jahr_existiert($pdo, $jahr)) {
        $fehler = neues_jahr_anlegen($pdo, $jahr - 1, $jahr);
        if ($fehler !== null) {
            echo '<p style="color:red;">✗ Jahr ' . h((string)$jahr) . ' konnte nicht angelegt werden: ' . h($fehler) . '</p>';
        } else {
            echo '<p style="color:green;">✓ Erstes MV-Jahr ' . h((string)$jahr) . ' angelegt (mvmitglieder_' . $jahr . ', mvantraege_' . $jahr . ', mvtexte-Zeile).</p>';
        }
    } else {
        echo '<p style="color:orange;">⚠ Jahr ' . h((string)$jahr) . ' existiert bereits - übersprungen.</p>';
    }

    // Weitere jahr-gebundene Tabellen, die neues_jahr_anlegen() bei einer
    // komplett neuen Installation (kein Vorjahr vorhanden) nicht mit
    // anlegt (siehe JAHR_TABELLEN_OPTIONAL in jahr_functions.php) - hier
    // trotzdem sichergestellt, damit die Instanz von Anfang an vollständig
    // ist, statt erst beim jeweils ersten tatsächlichen Gebrauch.
    unterst_tabelle_sicherstellen($pdo, $jahr);
    votum_tabelle_sicherstellen($pdo, $jahr);
    mvlogs_tabelle_sicherstellen($pdo, $jahr);
    echo '<p style="color:green;">✓ Unterstützungs-/Votum-/Protokoll-Tabellen für ' . h((string)$jahr) . ' sichergestellt.</p>';

    // Tagesordnung, Unterlagen, Login-Token-Pool - jahrunabhängig.
    mvtop_tabellen_sicherstellen($pdo);
    mvdateien_spalten_sicherstellen($pdo);
    login_tokens_tabelle_sicherstellen($pdo);
    mitgliederstamm_tabelle_sicherstellen($pdo);
    echo '<p style="color:green;">✓ Tagesordnung, Unterlagen, Login-Token-Pool, Mitgliederstamm sichergestellt.</p>';

    // Wahlen (Wahlgänge/Kandidat:innen/Stimmen/Ergebnisse) - jahrunabhängig
    // (mv_vote_wahlgaenge hat seine eigene jahr-Spalte, siehe wahl_functions.php).
    wahlgaenge_tabellen_sicherstellen($pdo);
    wahl_stimmen_tabelle_sicherstellen($pdo);
    wahl_ergebnisse_tabelle_sicherstellen($pdo);
    echo '<p style="color:green;">✓ Wahlgänge-Tabellen sichergestellt.</p>';

    // Abstimmungsmaschine (Stimmkarten/Runden/Einzelstimmen) - ebenfalls
    // jahrunabhängig (mv_vote_tokens/mv_vote_rounds haben seit der
    // BEAM-Vereinheitlichung ihre eigene jahr-Spalte, siehe
    // abstimmung_functions.php).
    vote_tokens_tabelle_sicherstellen($pdo);
    vote_rounds_tabelle_sicherstellen($pdo);
    vote_ballots_tabelle_sicherstellen($pdo);
    echo '<p style="color:green;">✓ Abstimmungsmaschine-Tabellen (Stimmkarten, Runden, Einzelstimmen) sichergestellt.</p>';

    // BEAM (Standalone-Variante, siehe dokumentation/BEAM.md) - beam_projekte/beam_inhalte.
    beam_tabellen_sicherstellen($pdo);
    echo '<p style="color:green;">✓ BEAM-Tabellen (beam_projekte, beam_inhalte) sichergestellt.</p>';

    // mvconfig (Vereinsvorgaben) und mvbeschluesse (Beschluss-Sammlung) haben
    // KEINE Selbstheilungsfunktion wie alle anderen Tabellen hier - sie
    // kommen bislang nur ueber die manuellen SQL-Migrationen unter
    // neu/migrations/ zustande (siehe Admin-Installationsanleitung.md,
    // Abschnitt 4). Beide Dateien sind bewusst idempotent geschrieben
    // (CREATE TABLE IF NOT EXISTS + INSERT IGNORE) - hier deshalb direkt
    // mit ausgefuehrt, statt sie als separaten manuellen Schritt zu
    // verlangen. 002_tagesordnung.sql/003_mvdateien.sql bleiben dagegen
    // bewusst aussen vor: beide Tabellen legen mvtop_tabellen_sicherstellen()/
    // mvdateien_spalten_sicherstellen() oben bereits vollstaendig selbst an,
    // und 003 enthaelt zudem ein ALTER TABLE ADD COLUMN OHNE
    // "IF NOT EXISTS" (fuer die Migration einer alten Mensa-Bestandstabelle
    // gedacht) - ein erneuter/blinder Lauf wuerde dort mit "Duplicate column"
    // fehlschlagen, wenn die Spalten (wie bei einer frischen Installation)
    // schon vorhanden sind.
    foreach (['001_mvconfig.sql', '004_mvbeschluesse.sql'] as $datei) {
        $pfad = __DIR__ . '/../migrations/' . $datei;
        $sql = file_get_contents($pfad);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $anweisung) {
            $pdo->exec($anweisung);
        }
    }
    echo '<p style="color:green;">✓ mvconfig (Vereinsvorgaben) und mvbeschluesse (Beschluss-Sammlung) sichergestellt.</p>';

    echo '<p style="color:green; font-weight:bold;">✓✓✓ Datenbank-Initialisierung abgeschlossen.</p>';
    echo '<hr><h3>Nächste Schritte</h3><ul>';
    echo '<li><code>config.php</code> neben diesem Verzeichnis (<code>neu/config.php</code>, siehe <code>config_example.php</code>) muss bereits mit den Zugangsdaten angelegt sein, bevor dieses Skript läuft.</li>';
    echo '<li>Unter „Vereinsspezifische Vorgaben" (Administration) die grundlegenden Einstellungen (Vereinsname, Mitgliederquelle, ggf. <code>abstimmung_basis_url</code>) setzen.</li>';
    echo '<li><a href="../index.php">Zu VERA</a> bzw. <a href="../beam/index.php">zu BEAM</a>.</li>';
    echo '<li><strong>Dieses Skript danach löschen oder vor erneutem Aufruf schützen.</strong></li>';
    echo '</ul>';
} catch (PDOException $e) {
    echo '<p style="color:red;">✗ Fehler: ' . h($e->getMessage()) . '</p>';
}

echo '</body></html>';
