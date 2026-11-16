<?php
// config.php - Vorlage. Nach config.php kopieren und anpassen; config.php wird
// nicht eingecheckt (siehe .gitignore im Repo-Wurzelverzeichnis - config.php wird
// dort unabhängig vom Pfad ausgeschlossen).
//
// WABS ist eine eigenständige Anwendung mit eigener, schlanker Datenbank
// (Tabellen wabs_elections/wabs_tokens/wabs_committee_users) - bewusst NICHT die
// Datenbank von MISS oder der Sitzungsverwaltung. Je nach der in MISS gewählten
// Übertragungsart (admin/wabs.php -> "Wahlserver-Verbindung") ist das entweder
// dieselbe Datenbank-Instanz (direkte Übertragung) oder eine eigene auf einem
// komplett anderen Server.
error_reporting(E_ALL);

define('WABS_DB_HOST', 'localhost');
define('WABS_DB_PORT', '3306');
define('WABS_DB_NAME', 'wahlserver');
define('WABS_DB_USER', 'xxx');
define('WABS_DB_PASS', 'xxx');
