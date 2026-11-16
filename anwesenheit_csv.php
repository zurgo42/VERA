<?php
declare(strict_types=1);

/**
 * anwesenheit_csv.php - CSV-Export der Anwesenheitsliste (siehe
 * tab_anwesenheit.php) fürs Protokoll. Nur für Redaktion (Red = 9, wie die
 * übrigen Admin-Funktionen).
 */

require_once __DIR__ . '/functions_neu.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php';
    session_start();
}

$pdo = get_pdo();
$jahr = get_aktuelles_jahr($pdo);
$mitglied = get_aktuelle_mnr($pdo) !== '' ? get_aktuelles_mitglied($pdo, $jahr) : null;

if (!ist_redaktion($mitglied)) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

$tabelle = 'mvmitglieder_' . $jahr;
$stmt = $pdo->prepare(
    "SELECT MNr, Vorname, Name, Teilnahmeform, StimmkarteAusgegeben, AnwesendSeit, AnwesendArt
     FROM `$tabelle` WHERE AnwesendSeit IS NOT NULL ORDER BY AnwesendSeit ASC"
);
$stmt->execute();
$anwesende = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="anwesenheitsliste-' . $jahr . '.csv"');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF"); // UTF-8-BOM, damit Excel die Umlaute richtig anzeigt
fputcsv($out, ['Zeit', 'Mitgliedsnummer', 'Vorname', 'Name', 'Teilnahmeform', 'Stimmkarte ausgegeben', 'Erfassungsart'], ';');
foreach ($anwesende as $a) {
    fputcsv($out, [
        date('d.m.Y H:i', strtotime((string)$a['AnwesendSeit'])),
        $a['MNr'],
        $a['Vorname'],
        $a['Name'],
        $a['Teilnahmeform'] ?? '',
        ((int)$a['StimmkarteAusgegeben'] === 1) ? 'ja' : 'nein',
        $a['AnwesendArt'] ?? '',
    ], ';');
}
fclose($out);
