<?php
// config.php
error_reporting(E_ALL);
define('MYSQL_HOST',     'localhost');
define('MYSQL_USER',     'xxx');
define('MYSQL_PASS',     'xxx');
define('MYSQL_DATABASE', 'vera');

// LDAP-Zugangsdaten (bisher hartkodiert in fstandard.php)
define('LDAP_BIND_PASS', 'xxx');

// ============================================
// Optional - wie bei den Schwesterprojekten Sitzungsverwaltung (KONA) und
// Mitgliederverwaltung (MISS): Vereinsname/Logo direkt hier statt der
// admin-editierbaren Vereinsvorgabe setzen. Gesetzt, hat die Konstante
// Vorrang; ungesetzt bleibt alles wie gewohnt unter "Admin-Funktionen ->
// Vereinsspezifische Vorgaben" aenderbar.
// define('ORG_NAME', 'Musterverein e. V.');
// define('ORG_LOGO', '');   // Pfad/URL zum Logo, leer = Standardsymbol

// Demo-Modus: Anmeldung per Auswahl eines Demo-Mitglieds auf login.php,
// ganz ohne Mail-Versand/Passwort. In Produktion UNBEDINGT auskommentiert
// lassen (siehe Admin-Installationsanleitung, Abschnitt 6c).
// define('DEMO_MODE', true);

// Admin-Passwort fuer besonders folgenreiche Systemfunktionen (aktuell:
// "Demo-Daten einspielen" unter Vereinsspezifische Vorgaben - loescht dabei
// unwiderruflich alle Antraege/die Tagesordnung des Jahres) - wie bei den
// Schwesterprojekten Sitzungsverwaltung (KONA) und Mitgliederverwaltung
// (MISS). Ohne gesetzte Konstante bleibt die Funktion gesperrt. Einmal pro
// Browser-Sitzung richtig eingegeben, wird es fuer den Rest der Sitzung
// nicht erneut abgefragt.
// define('SYSTEM_ADMIN_PASSWORD', 'xxx');

// Fusszeile (Copyright/Impressum/Datenschutz) - wie bei Sitzungsverwaltung
// (KONA) und Mitgliederverwaltung (MISS), ebenfalls optional. FOOTER_COPYRIGHT
// darf HTML enthalten (wird ungefiltert ausgegeben, wie bei den
// Schwesterprojekten) - ungesetzt erscheint stattdessen "(c) <Jahr>
// <Vereinsname>" wie bisher. Ungesetzte Impressum-/Datenschutz-Links werden
// nicht angezeigt.
// define('FOOTER_COPYRIGHT', '&copy; Dr. Max Mustermann, Musterstr. 1, 12345 Musterstadt Tel. 0123 456789 eMail meier@beispiel.de');
// define('FOOTER_IMPRESSUM_URL', 'https://www.tsv-musterstadt.de/impressum');
// define('FOOTER_DATENSCHUTZ_URL', 'https://www.tsv-musterstadt.de/datenschutz');

// Selbstheilendes Datenbankschema (CREATE TABLE IF NOT EXISTS/ALTER TABLE
// ADD COLUMN bei jedem Seitenaufruf) standardmaessig AN - praktisch fuer
// Entwicklung und neue Installationen. Fuer den Produktivbetrieb mit vielen
// gleichzeitigen Anfragen (z. B. waehrend einer Versammlung) empfohlen:
// ausschalten, damit nicht bei jeder einzelnen Anfrage unnoetig das Schema
// geprueft wird. Nach jedem Deployment dann einmalig
// tools/db_schema_sync.php aufrufen, um neue Tabellen/Spalten nachzuziehen.
// define('DB_SELBSTHEILUNG_AKTIV', false);
?>