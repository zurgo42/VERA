<?php
declare(strict_types=1);

/**
 * wlan_qr.php - Liefert den WLAN-Beitritts-QR-Code (siehe wlan_qr_inhalt()
 * in abstimmung_functions.php) direkt als PNG aus - fürs Beamerbild
 * (dauerhaft sichtbar, siehe beamer_vorschau_render()), wo anders als bei
 * der gedruckten Stimmkarte kein vorab erzeugtes PDF existiert.
 *
 * Öffentlich erreichbar wie abstimmen.php - zeigt nichts Sensibleres als
 * das WLAN-Passwort selbst, das ohnehin sichtbar auf der Leinwand steht
 * (bzw. gedruckt auf der Stimmkarte).
 */

require_once __DIR__ . '/db_functions.php';
require_once __DIR__ . '/abstimmung_functions.php';

$pdo = get_pdo();
$config = get_config($pdo);
$ssid = (string)($config['wlan_ssid'] ?? '');
$passwort = (string)($config['wlan_passwort'] ?? '');

if ($ssid === '') {
    http_response_code(404);
    exit;
}

$qrcode_dir = vote_lib_verzeichnis_suchen(__DIR__, 'phpqrcode', 'qrlib.php');
if ($qrcode_dir === null) {
    http_response_code(500);
    exit('QR-Bibliothek nicht gefunden.');
}

// Siehe vote_stimmkarten_erzeugen() fuer denselben Deprecation-Hinweis bei
// PHP 8.x (Fremdbibliothek, nicht Teil dieses Repos).
$reporting_vorher = error_reporting(E_ALL & ~E_DEPRECATED);
require_once $qrcode_dir . '/qrlib.php';
// Groesseres pixelPerPoint (10 statt 4 wie auf der gedruckten Karte) - wird
// hier per CSS auf der Leinwand vergroessert dargestellt, ein zu grobes PNG
// wuerde dabei sichtbar unscharf/blockig wirken.
QRcode::png(wlan_qr_inhalt($ssid, $passwort), false, QR_ECLEVEL_M, 10, 2);
error_reporting($reporting_vorher);
