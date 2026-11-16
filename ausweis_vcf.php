<?php
declare(strict_types=1);

/**
 * ausweis_vcf.php - Download der elektronischen Visitenkarte (vCard) zu
 * einem Ausweis-Token - verlinkt von ausweis_pruefen.php für normale
 * Betrachter:innen des QR-Codes (siehe dort). Liefert bewusst genau die
 * Daten, die ausweis_pruefen.php der betrachtenden Person davor schon als
 * Text gezeigt hat - keine zusätzlichen, dort unsichtbaren Felder.
 */

require_once __DIR__ . '/functions_neu.php';
require_once __DIR__ . '/ausweis_functions.php';

$pdo = get_pdo();
$config = get_config($pdo);
$token = (string)($_GET['t'] ?? '');
$mnr = $token !== '' ? ausweis_token_mnr($pdo, $token) : null;

if ($mnr === null) {
    http_response_code(410);
    header('Content-Type: text/plain; charset=utf-8');
    exit('QR-Code ungültig oder abgelaufen.');
}

$kontakt = mitgliederquelle_abfragen($pdo, $mnr, $config);
$telefon = svmembers_telefon_von_mnr($pdo, $mnr);
$vcard = ausweis_vcard_inhalt($kontakt['vorname'], $kontakt['name'], $telefon, $kontakt['mail']);

$dateiname = preg_replace('/[^A-Za-z0-9_-]/', '_', trim($kontakt['vorname'] . '_' . $kontakt['name']));
if ($dateiname === '' || $dateiname === '_') {
    $dateiname = 'kontakt';
}

header('Content-Type: text/vcard; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $dateiname . '.vcf"');
echo $vcard;
