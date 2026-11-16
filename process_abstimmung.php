<?php
/**
 * process_abstimmung.php - Verarbeitet "Stimmkarten erzeugen" (Admin-Funktion,
 * Abstimmungsmaschine Phase 1). Wird nur bei POST auf
 * tab=admin&bereich=abstimmung eingebunden (siehe index.php), das dortige
 * Gating prüft bereits ist_redaktion() - hier zusätzlich zur Sicherheit noch
 * einmal. Die Abstimmungsrunde selbst (starten/beenden) läuft über die
 * eigene Beamersteuerung-Seite, siehe process_beamersteuerung.php - dort
 * ist_beamersteuerung_berechtigt() statt ist_redaktion(), siehe
 * tab_abstimmung.php.
 */

require_once __DIR__ . '/abstimmung_functions.php';

if (!$ist_redaktion) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

csrf_check();

if (($_POST['aktion'] ?? '') === 'stimmkarten_loeschen') {
    $anzahlVorher = vote_tokens_anzahl($pdo, $jahr);
    $fehler = vote_tokens_loeschen($pdo, $jahr);
    if ($fehler) {
        $_SESSION['admin_fehler'] = $fehler;
    } else {
        mv_log($pdo, $jahr, $mitglied, 'Stimmkarten-Pool gelöscht', $anzahlVorher . ' Stimmkarte(n)');
        $_SESSION['admin_erfolg'] = 'Alle ' . $anzahlVorher . ' Stimmkarte(n) gelöscht.';
    }
} else {
    $anzahl = (int)($_POST['anzahl'] ?? 0);
    // Dieselbe Titelzusammensetzung wie beamer_vorschau_render() ohne
    // Titel-Override, damit die Stimmkarte zur gewohnten Titelleiste passt.
    $titel = trim($config['vereinsname'] . ' – ' . $config['mv_bezeichnung'] . ' ' . $jahr);
    [$datei, $fehler] = vote_stimmkarten_erzeugen(
        $pdo, $jahr, $anzahl, (string)($config['abstimmung_basis_url'] ?? ''),
        $titel, (string)($config['logo_pfad'] ?? ''),
        (string)($config['wlan_ssid'] ?? ''), (string)($config['wlan_passwort'] ?? '')
    );
    if ($fehler) {
        $_SESSION['admin_fehler'] = $fehler;
    } else {
        mv_log($pdo, $jahr, $mitglied, 'Stimmkarten erzeugt', $anzahl . ' Stimmkarte(n), Datei ' . $datei);
        $_SESSION['admin_erfolg'] = $anzahl . ' Stimmkarte(n) erzeugt (' . $datei . ', unten zum Herunterladen).';
    }
}

header('Location: ?tab=admin&bereich=abstimmung');
exit;
