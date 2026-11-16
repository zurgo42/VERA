<?php
declare(strict_types=1);

/**
 * ausweis_qr.php - Liefert den QR-Code für den elektronischen
 * Mitgliedsausweis (siehe ausweis.php) direkt als PNG aus - als
 * <img src="ausweis_qr.php">, damit der (sicherheitsrelevante) Token
 * serverseitig frisch anhand der aktuellen Anmeldung erzeugt wird, statt
 * ihn als URL-Parameter durchzureichen (sonst landete er in
 * Server-/Browser-Logs). Nimmt deshalb bewusst KEINE Parameter vom Client
 * entgegen - wer hier landet, bekommt immer nur den eigenen Ausweis.
 *
 * Der QR-Code kodiert einen Link zu ausweis_pruefen.php (siehe dort und
 * ausweis_functions.php: ausweis_pruefen_url()) - keine vCard direkt, siehe
 * Begründung im Kopf von ausweis_functions.php.
 */

// Fängt jede versehentliche Ausgabe (PHP-Warnung/Notice, Leerzeichen vor
// <?php durch einen fehlerhaften Upload o.ä.) vor dem eigentlichen PNG ab -
// sonst reicht schon ein einziges Zeichen Fremdausgabe, um sowohl das Bild
// unbrauchbar zu machen als auch den folgenden header()-Aufruf wirkungslos
// zu machen (Content-Type bliebe dann faelschlich text/html). Wird am Ende
// entweder sauber verworfen (ob_clean(), dann das echte PNG) oder im
// Fehlerfall als lesbare Meldung ausgegeben statt als leeres/kaputtes Bild.
ob_start();

try {
    require_once __DIR__ . '/functions_neu.php';
    require_once __DIR__ . '/ausweis_functions.php';
    require_once __DIR__ . '/abstimmung_functions.php'; // fuer vote_lib_verzeichnis_suchen()

    if (session_status() === PHP_SESSION_NONE) {
        require_once __DIR__ . '/session_config.php';
        session_start();
    }

    $pdo = get_pdo();
    $mnr = get_aktuelle_mnr($pdo);
    if ($mnr === '') {
        ob_clean();
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Bitte zuerst anmelden.');
    }

    $config = get_config($pdo);
    $token = ausweis_token_erzeugen($pdo, $mnr);
    $inhalt = ausweis_pruefen_url($config, $token);
    if ($inhalt === '') {
        throw new RuntimeException('Vorgabe "Basis-URL der Abstimmungsmaschine" ist nicht gesetzt - ohne sie kann kein Ausweis-QR-Code erzeugt werden.');
    }

    $qrcode_dir = vote_lib_verzeichnis_suchen(__DIR__, 'phpqrcode', 'qrlib.php');
    if ($qrcode_dir === null) {
        throw new RuntimeException('QR-Bibliothek (phpqrcode) wurde nicht gefunden.');
    }

    // Siehe vote_stimmkarten_erzeugen() fuer denselben Deprecation-Hinweis
    // bei PHP 8.x (Fremdbibliothek, nicht Teil dieses Repos).
    $reporting_vorher = error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE);
    require_once $qrcode_dir . '/qrlib.php';
    $png = null;
    ob_start(); // separater innerer Puffer, um genau die rohen PNG-Bytes aufzufangen
    QRcode::png($inhalt, false, QR_ECLEVEL_M, 8, 2);
    $png = ob_get_clean();
    error_reporting($reporting_vorher);

    if ($png === null || $png === '') {
        throw new RuntimeException('QR-Bibliothek hat kein Bild erzeugt.');
    }

    ob_clean(); // verwirft jede bislang unbeabsichtigt gepufferte Ausgabe
    header('Content-type: image/png');
    echo $png;
} catch (Throwable $e) {
    // Falls der innere Puffer (siehe oben, fuer die PNG-Bytes) beim Fehler
    // noch offen war: zurueck auf genau einen Pufferlevel, bevor wir den
    // aeusseren leeren und mit der Fehlermeldung fuellen.
    while (ob_get_level() > 1) {
        ob_end_clean();
    }
    ob_clean();
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Fehler beim Erzeugen des Ausweis-QR-Codes: ' . $e->getMessage();
}
