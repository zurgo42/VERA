<?php
/**
 * login_functions.php - Magic-Link-Login (siehe login.php) fuer Vereine
 * ohne vorgelagertes SSO. Nur relevant, wenn die Vereinsvorgabe
 * login_modul_aktiv gesetzt ist (siehe db_functions.php) - greift ausserdem
 * nur, wenn REMOTE_USER leer ist (siehe functions_neu.php:
 * get_aktuelle_mnr()), aendert also am SSO-Verhalten bestehender
 * Installationen (Mensa) nichts.
 *
 * Ablauf: Mitgliedsnummer eingeben -> Zufallstoken erzeugen, nur dessen
 * Hash speichern, per Mail an die hinterlegte Adresse schicken -> Klick auf
 * den Link setzt $_SESSION['login_mnr'] und loescht den Token (Einmalgebrauch,
 * 15 Minuten gueltig).
 */

require_once __DIR__ . '/db_functions.php';

function login_tokens_tabelle_sicherstellen(PDO $pdo): void {
    static $geprueft = false;
    if ($geprueft || !selbstheilung_aktiv()) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mv_login_tokens (
            ID INT AUTO_INCREMENT PRIMARY KEY,
            MNr VARCHAR(12), TokenHash VARCHAR(64), Erstellt DATETIME, Ablauf DATETIME, Benutzt TINYINT(1) DEFAULT 0,
            INDEX idx_tokenhash (TokenHash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $geprueft = true;
}

/**
 * Wie viele (noch gueltige) Anmelde-Mails wurden fuer diese MNr in der
 * letzten Minute angefordert? Einfache Drossel gegen Mail-Flut bei
 * mehrfachem Klick/Missbrauch - kein vollwertiges Rate-Limiting.
 */
function login_anfragen_zuletzt(PDO $pdo, string $mnr): int {
    login_tokens_tabelle_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM mv_login_tokens WHERE MNr = ? AND Erstellt > (NOW() - INTERVAL 1 MINUTE)');
    $stmt->execute([$mnr]);
    return (int)$stmt->fetch()['n'];
}

/** Erzeugt einen neuen Anmelde-Token (15 Minuten gueltig) und gibt den Rohwert zurueck (fuer den Mail-Link - nur der Hash wird gespeichert). */
function login_token_erzeugen(PDO $pdo, string $mnr): string {
    login_tokens_tabelle_sicherstellen($pdo);
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare('INSERT INTO mv_login_tokens (MNr, TokenHash, Erstellt, Ablauf) VALUES (?, ?, NOW(), NOW() + INTERVAL 15 MINUTE)');
    $stmt->execute([$mnr, hash('sha256', $token)]);
    return $token;
}

/** Loest einen Token ein: gueltig, nicht abgelaufen, noch nicht benutzt -> MNr zurueck und als benutzt markiert. Sonst null. */
function login_token_einloesen(PDO $pdo, string $token): ?string {
    login_tokens_tabelle_sicherstellen($pdo);
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare('SELECT ID, MNr FROM mv_login_tokens WHERE TokenHash = ? AND Benutzt = 0 AND Ablauf > NOW() LIMIT 1');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $pdo->prepare('UPDATE mv_login_tokens SET Benutzt = 1 WHERE ID = ?')->execute([$row['ID']]);
    return $row['MNr'];
}

/** Verschickt die Anmelde-Mail mit dem Login-Link. Gibt zurueck, ob der Versand (laut mail()) geklappt hat. */
function login_mail_versenden(string $mail_adresse, string $login_url): bool {
    $betreff = 'Anmeldelink fürs MV-Tool';
    $text = 'Hallo,' . "\n\n"
        . 'mit diesem Link kannst du dich im MV-Tool anmelden (15 Minuten gültig, nur einmal verwendbar):' . "\n\n"
        . $login_url . "\n\n"
        . 'Hast du diese Mail nicht angefordert, kannst du sie einfach ignorieren.';
    return @mail($mail_adresse, $betreff, $text);
}
