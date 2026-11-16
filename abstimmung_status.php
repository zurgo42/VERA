<?php
declare(strict_types=1);

/**
 * abstimmung_status.php - Schlanker JSON-Endpunkt für den Live-Zähler auf
 * dem Operator-Bildschirm (Beamersteuerung, siehe
 * tab_beamersteuerung.php). Wird von dort per JavaScript (fetch, alle
 * paar Sekunden) abgefragt - bewusst NICHT von den Teilnehmer-Geräten,
 * die rufen abstimmen.php nur aktiv per QR-Code auf (siehe
 * dokumentation/Abstimmungsmaschine.md, Lastabschätzung). Nur dieser eine
 * Operator-Bildschirm pollt, das ist unkritisch.
 */

require_once __DIR__ . '/functions_neu.php';
require_once __DIR__ . '/abstimmung_functions.php';
require_once __DIR__ . '/wahl_functions.php';
require_once __DIR__ . '/beamer_functions.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php';
    session_start();
}

$pdo = get_pdo();
$jahr = get_aktuelles_jahr($pdo);
$mitglied = get_aktuelle_mnr($pdo) !== '' ? get_aktuelles_mitglied($pdo, $jahr) : null;

header('Content-Type: application/json; charset=utf-8');

if (!ist_beamersteuerung_berechtigt($mitglied)) {
    http_response_code(403);
    echo json_encode(['fehler' => 'Keine Berechtigung.']);
    exit;
}

// Der eigene Abstimmungs-Timer (getrennt vom allgemeinen Beamer-Timer fuer
// Redezeiten/Pausen) beendet (und wertet direkt aus) bei Ablauf eine
// laufende Abstimmung automatisch, sofern die Checkbox dafuer gesetzt war -
// siehe beamer_abstimmung_timer_abgelaufen_pruefen(). Diese Status-Abfrage
// wird von der Beamersteuerung laufend gepollt, daher der ideale Ort dafuer
// statt eines eigenen Cronjobs.
beamer_abstimmung_timer_abgelaufen_pruefen($pdo, $jahr);

$round = vote_round_aktuell($pdo, $jahr);
if (!$round) {
    echo json_encode(['laeuft' => false]);
    exit;
}

$ist_wahl = !empty($round['wahlgang_id']);
$zaehler = $ist_wahl ? wahl_round_zaehler($pdo, (int)$round['round_id']) : vote_round_zaehler($pdo, (int)$round['round_id']);
$last = $ist_wahl ? wahl_round_lastschwaeche($pdo, (int)$round['round_id']) : vote_round_lastschwaeche($pdo, (int)$round['round_id']);
$mvtexte = get_mvtexte($pdo, $jahr);
$vote_timer_ziel = $mvtexte['abstimmung_timer'] ?? null;
$vote_timer_auto_beenden = (int)($mvtexte['abstimmung_timer_auto_beenden'] ?? 0) === 1;
// Ohne "automatisch beenden" (z. B. nach dem roten Abbruch-Button) keinen
// Countdown mehr melden - sonst liefe die Ablaufbalken-Anzeige sichtbar
// weiter, obwohl sie nichts mehr bewirkt (siehe beamer_vorschau_daten()).
$vote_timer_rest = ($vote_timer_ziel && $vote_timer_auto_beenden) ? (strtotime($vote_timer_ziel) - time()) : null;
echo json_encode([
    'laeuft' => true,
    'round_id' => (int)$round['round_id'],
    'anzahl' => $zaehler,
    'mittel_ms' => $last['mittel_ms'] ?? null,
    'auffaellig' => $last['auffaellig'] ?? false,
    'vote_timer_rest' => $vote_timer_rest,
    'vote_timer_vorgabe_sek' => isset($mvtexte['abstimmung_timer_vorgabe_sek']) ? (int)$mvtexte['abstimmung_timer_vorgabe_sek'] : null,
]);
