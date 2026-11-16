<?php
declare(strict_types=1);

/**
 * db.php - Datenbankverbindung (PDO) zur eigenen, schlanken Wahlserver-Datenbank
 * sowie deren Schema. WABS ist eigenständig (kein SSO, keine gemeinsame Datenbank
 * mit MISS/der Sitzungsverwaltung außer bei "direkter Übertragung", siehe
 * config_example.php) - deshalb legt sich das Schema hier selbst an, genau wie
 * MISS es beim direkten Übertragen tut (siehe dort src/wabs.php:
 * md_wabs_transfer_ensure_schema(), md_wabs_committee_sync()). Dadurch läuft WABS
 * auch auf einem frischen Server, bevor von MISS aus zum ersten Mal übertragen
 * wurde.
 */

require_once __DIR__ . '/config.php';

function wabs_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . WABS_DB_HOST . ';port=' . WABS_DB_PORT . ';dbname=' . WABS_DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, WABS_DB_USER, WABS_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        wabs_ensure_schema($pdo);
    }
    return $pdo;
}

/** Legt die drei schlanken Tabellen an, falls sie noch nicht existieren - idempotent, löscht/ändert nie etwas Bestehendes. */
function wabs_ensure_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS wabs_elections (
        election_id INT PRIMARY KEY, title VARCHAR(200) NOT NULL, start_at DATETIME NOT NULL, end_at DATETIME NOT NULL, transferred_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS wabs_tokens (
        token_id INT PRIMARY KEY AUTO_INCREMENT, election_id INT NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS wabs_committee_users (
        user_id INT PRIMARY KEY AUTO_INCREMENT, username VARCHAR(100) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Bremse gegen Brute-Force auf das Login (siehe login.php: wabs_login_throttled()) - analog zu
    // MISS (src/login.php: md_login_attempts), eigene Tabelle statt gemeinsamer mit den Nutzdaten.
    $pdo->exec('CREATE TABLE IF NOT EXISTS wabs_login_attempts (
        ident_hash VARCHAR(64) NOT NULL, attempt_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
