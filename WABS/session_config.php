<?php

/**
 * session_config.php - MUSS VOR session_start() geladen werden.
 *
 * Bewusst KEIN 15-Tage-Cookie wie bei den Schwesterprojekten KONA/MISS/mv-neu:
 * WABS verwaltet die anonyme Token-Liste einer laufenden Wahl und hat nur zwei
 * Benutzer - hier zählt kurze Gültigkeit mehr als Bequemlichkeit. Nach 2 Stunden
 * Inaktivität ist eine erneute Anmeldung nötig.
 */

ini_set('session.cookie_path', '/');
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_only_cookies', 1);

if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
}

ini_set('session.gc_maxlifetime', 2 * 60 * 60);
ini_set('session.cookie_lifetime', 0);   // Sitzungscookie (bis der Browser schließt), kein dauerhaftes Cookie
