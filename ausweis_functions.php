<?php
/**
 * ausweis_functions.php - Elektronischer Mitgliedsausweis (siehe ausweis.php,
 * ausweis_qr.php, ausweis_pruefen.php, ausweis_vcf.php): zeigt Name, Foto
 * (aus MISS, siehe Vorgabe foto_quelle_url) und einen QR-Code.
 *
 * Der QR-Code kodiert bewusst einen LINK (zu ausweis_pruefen.php), keine
 * vCard direkt - eine vCard im QR-Code wuerde von Handykameras nur als
 * "Kontakt hinzufuegen"-Dialog erkannt, nicht als klickbarer Link, den das
 * Einlasskontrolle-Personal (siehe ist_ein_ausgang_berechtigt()) wie bei der
 * schon bestehenden Austritt-Funktion (abstimmen.php) einfach aufrufen
 * koennte. ausweis_pruefen.php entscheidet dann serverseitig je nach
 * anmeldender Person, was angezeigt wird: normale Betrachter:innen (z. B.
 * ein anderes Mitglied) sehen die Kontaktdaten zum Herunterladen als vCard
 * (ausweis_vcf.php), Einlasskontrolle-Personal sieht stattdessen die
 * Mitgliedschaftspruefung.
 *
 * Token-Prinzip wie bei der Abstimmungsmaschine (siehe
 * abstimmung_functions.php: vote_tokens_erzeugen()/vote_token_gueltig()):
 * nur ein zufälliger Rohwert wird herausgegeben, in der Datenbank steht nur
 * dessen Hash - ein DB-Leak allein reicht also nicht, um gültige Ausweise
 * zu fälschen. Anders als ein Abstimmungstoken wird ein Ausweis-Token bei
 * der Prüfung NICHT verbraucht (derselbe Ausweis darf beliebig oft
 * gescannt werden, solange er gültig ist) - jeder Aufruf von
 * ausweis_qr.php erzeugt daher bewusst einen frischen Token statt einen
 * bestehenden wiederzuverwenden; ältere, nicht mehr angezeigte Token
 * bleiben bis zu ihrem Ablauf im Hintergrund gültig, das ist unschädlich.
 */

require_once __DIR__ . '/db_functions.php';
require_once __DIR__ . '/mitgliederquelle_functions.php'; // fuer SVMEMBERS_STATUS_*-Konstanten (einlass_einschaetzung())

const AUSWEIS_TOKEN_GUELTIGKEIT_STUNDEN = 24;

function ausweis_tokens_tabelle_sicherstellen(PDO $pdo): void {
    static $geprueft = false;
    if ($geprueft || !selbstheilung_aktiv()) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mv_ausweis_tokens (
            ID INT AUTO_INCREMENT PRIMARY KEY,
            MNr VARCHAR(12) NOT NULL,
            TokenHash VARCHAR(64) NOT NULL,
            Erstellt DATETIME NOT NULL,
            Ablauf DATETIME NOT NULL,
            INDEX idx_tokenhash (TokenHash),
            INDEX idx_mnr (MNr)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $geprueft = true;
}

/** Erzeugt einen neuen, gültigen Ausweis-Token für diese MNr und gibt den Rohwert zurück (nur dessen Hash wird gespeichert). */
function ausweis_token_erzeugen(PDO $pdo, string $mnr): string {
    ausweis_tokens_tabelle_sicherstellen($pdo);
    $token = bin2hex(random_bytes(24));
    $stmt = $pdo->prepare(
        'INSERT INTO mv_ausweis_tokens (MNr, TokenHash, Erstellt, Ablauf)
         VALUES (?, ?, NOW(), NOW() + INTERVAL ' . AUSWEIS_TOKEN_GUELTIGKEIT_STUNDEN . ' HOUR)'
    );
    $stmt->execute([$mnr, hash('sha256', $token)]);
    return $token;
}

/**
 * Zu welcher MNr gehört dieser (noch gültige, nicht abgelaufene)
 * Ausweis-Token? null, falls unbekannt oder abgelaufen. Verbraucht den
 * Token NICHT (siehe Dateikopf) - für die künftige Einlasskontrolle
 * (Phase 3), die denselben Ausweis ggf. mehrfach pro Versammlung
 * akzeptieren muss (z. B. bei einem fehlgeschlagenen ersten Scan).
 */
function ausweis_token_mnr(PDO $pdo, string $token): ?string {
    ausweis_tokens_tabelle_sicherstellen($pdo);
    $stmt = $pdo->prepare('SELECT MNr FROM mv_ausweis_tokens WHERE TokenHash = ? AND Ablauf > NOW() LIMIT 1');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    return $row !== false ? (string)$row['MNr'] : null;
}

/** vCard-Feldwert escapen (Komma/Semikolon/Zeilenumbruch, siehe RFC 2426). */
function ausweis_vcard_escape(string $text): string {
    return str_replace([',', ';', "\n"], ['\,', '\;', '\n'], $text);
}

/**
 * vCard-Inhalt für den Kontakt-Download (siehe ausweis_vcf.php) - die
 * elektronische Visitenkarte, die ein normaler Betrachter des QR-Codes
 * herunterladen kann. Enthält nur das, was die Seite ausweis_pruefen.php
 * demselben Betrachter zuvor auch sichtbar als Text angezeigt hat
 * (Transparenz: niemand bekommt per QR-Code mehr Daten, als er auf der
 * Seite selbst schon lesen konnte).
 */
function ausweis_vcard_inhalt(string $vorname, string $name, string $telefon, string $mail): string {
    $zeilen = ['BEGIN:VCARD', 'VERSION:3.0'];
    $zeilen[] = 'N:' . ausweis_vcard_escape($name) . ';' . ausweis_vcard_escape($vorname) . ';;;';
    $zeilen[] = 'FN:' . ausweis_vcard_escape(trim($vorname . ' ' . $name));
    if ($telefon !== '') {
        $zeilen[] = 'TEL:' . ausweis_vcard_escape($telefon);
    }
    if ($mail !== '') {
        $zeilen[] = 'EMAIL:' . ausweis_vcard_escape($mail);
    }
    $zeilen[] = 'END:VCARD';
    return implode("\r\n", $zeilen);
}

/** Foto-URL für den Ausweis gemäß Vorgabe foto_quelle_url (Platzhalter {mnr}) - leer, wenn nicht konfiguriert. */
function ausweis_foto_url(array $config, string $mnr): string {
    $muster = trim((string)($config['foto_quelle_url'] ?? ''));
    if ($muster === '') {
        return '';
    }
    return str_replace('{mnr}', rawurlencode($mnr), $muster);
}

/**
 * Bewertet, ob ein Mitglied am Einlass eine Stimmkarte bekommen sollte -
 * reine Einschätzung/Empfehlung, KEINE automatische Entscheidung: das
 * Einlasskontrolle-Personal bestätigt in ausweis_pruefen.php immer manuell
 * (drei explizite Buttons), damit ein Fehler in dieser Logik nicht
 * automatisch eine echte Abstimmung verfälschen kann.
 */
function einlass_einschaetzung(?string $statusWert, ?string $teilnahmeform): array {
    if ($teilnahmeform === 'online') {
        return ['stufe' => 'rot', 'begruendung' => 'Als Online-Teilnehmer:in registriert - keine Stimmkarte vor Ort.'];
    }
    if ($statusWert === null) {
        return ['stufe' => 'unbekannt', 'begruendung' => 'Mitgliedsstatus kann nicht geprüft werden (MISS liefert noch keinen Status).'];
    }
    if ($statusWert === SVMEMBERS_STATUS_STIMMBERECHTIGT) {
        return ['stufe' => 'gruen', 'begruendung' => ''];
    }
    if ($statusWert === SVMEMBERS_STATUS_TEILNAHMEBERECHTIGT_OHNE_STIMMRECHT) {
        return ['stufe' => 'blau', 'begruendung' => 'Teilnahme ja, aber ohne Stimmrecht - keine Stimmkarte.'];
    }
    return ['stufe' => 'rot', 'begruendung' => 'Status „' . $statusWert . '“ - keine Teilnahmeberechtigung.'];
}

/**
 * Absolute URL für den Ausweis-QR-Code (ausweis_pruefen.php mit Token) -
 * nutzt dieselbe Vorgabe wie die Stimmkarten-QR-Codes
 * (abstimmung_basis_url, siehe abstimmung_functions.php), da es dieselbe
 * Basis-Adresse der Installation ist. Leer, wenn diese Vorgabe nicht
 * gesetzt ist (dann kann der Ausweis-QR-Code nicht funktionieren - ohne
 * absolute URL weiss eine fremde Handykamera nicht, welche Installation
 * gemeint ist).
 */
function ausweis_pruefen_url(array $config, string $token): string {
    $basis = rtrim(trim((string)($config['abstimmung_basis_url'] ?? '')), '/');
    if ($basis === '') {
        return '';
    }
    return $basis . '/ausweis_pruefen.php?t=' . rawurlencode($token);
}
