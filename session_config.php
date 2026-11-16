<?php
/**
 * session_config.php - Zentrale Session-Konfiguration, uebernommen aus der
 * Sitzungsverwaltung (KONA), damit die Produktfamilie dasselbe
 * Anmelde-Verhalten zeigt.
 *
 * MUSS VOR session_start() geladen werden! Wird von allen Dateien
 * eingebunden, die session_start() aufrufen (index.php, login.php,
 * beamerbild.php).
 */

ini_set('session.cookie_path', '/');              // Cookie fuer gesamte Domain
ini_set('session.cookie_httponly', 1);            // Schutz vor XSS
ini_set('session.cookie_samesite', 'Lax');        // CSRF-Schutz
ini_set('session.use_only_cookies', 1);           // Nur Cookies, keine URL-Parameter

// HTTPS-Sicherheit (nur wenn HTTPS aktiv)
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);          // Cookie nur ueber HTTPS
}

// Session-Laufzeit: 15 Tage (wie bei KONA/MISS) - Mitglieder sollen
// zwischen zwei Besuchen waehrend der mehrwoechigen Antrags-/
// Unterstuetzungsphase angemeldet bleiben, statt sich staendig neu
// anmelden zu muessen.
ini_set('session.gc_maxlifetime', 15 * 24 * 60 * 60);    // 15 Tage Server-seitig
ini_set('session.cookie_lifetime', 15 * 24 * 60 * 60);   // 15 Tage Client-seitig
