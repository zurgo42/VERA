<?php
declare(strict_types=1);

/**
 * abstimmung_status.php - Schlanker JSON-Endpunkt für den Live-Zähler auf
 * steuerung.php, siehe neu/abstimmung_status.php (dasselbe Prinzip, hier
 * für ein BEAM-Projekt statt ein VERA-Jahr).
 */

require_once __DIR__ . '/../session_config.php';
session_start();
require_once __DIR__ . '/beam_functions.php';

$pdo = get_pdo();
$rechte = beam_aktuelle_rechte($pdo);
$projektId = (int)($_GET['projekt'] ?? 0);
$projekt = beam_projekt_get($pdo, $projektId);

header('Content-Type: application/json; charset=utf-8');

if (!$rechte['eingeloggt'] || !$rechte['darf_steuern'] || !$projekt || !beam_projekt_berechtigt($projekt, $rechte)) {
    http_response_code(403);
    echo json_encode(['fehler' => 'Keine Berechtigung.']);
    exit;
}

// Siehe neu/abstimmung_status.php: der Abstimmungs-Timer beendet (und wertet
// aus) eine laufende Abstimmung automatisch, sofern "automatisch beenden"
// gesetzt war - diese gepollte Status-Abfrage ist der ideale Ort dafür.
beamer_abstimmung_timer_abgelaufen_pruefen($pdo, $projektId);

$round = vote_round_aktuell($pdo, $projektId);
if (!$round) {
    echo json_encode(['laeuft' => false]);
    exit;
}

$ist_wahl = !empty($round['wahlgang_id']);
$zaehler = $ist_wahl ? wahl_round_zaehler($pdo, (int)$round['round_id']) : vote_round_zaehler($pdo, (int)$round['round_id']);
$last = $ist_wahl ? wahl_round_lastschwaeche($pdo, (int)$round['round_id']) : vote_round_lastschwaeche($pdo, (int)$round['round_id']);
$mvtexte = get_mvtexte($pdo, $projektId);
$vote_timer_ziel = $mvtexte['abstimmung_timer'] ?? null;
$vote_timer_auto_beenden = (int)($mvtexte['abstimmung_timer_auto_beenden'] ?? 0) === 1;
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
