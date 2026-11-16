<?php
/**
 * process_wahlgaenge.php - Verarbeitet "Wahlgänge verwalten" (Admin-Funktion,
 * Abstimmungsmaschine Phase 6, siehe tab_wahlgaenge.php). Wird nur bei POST
 * auf tab=admin&bereich=wahlgaenge eingebunden (siehe index.php), das
 * dortige Gating prüft bereits ist_redaktion() - hier zusätzlich zur
 * Sicherheit noch einmal. Die Wahlrunde selbst (starten/beenden/auswerten)
 * läuft über die eigene Beamersteuerung-Seite, siehe
 * process_beamersteuerung.php.
 */

require_once __DIR__ . '/wahl_functions.php';

if (!$ist_redaktion) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

csrf_check();

$aktion = $_POST['aktion'] ?? '';
$kandidatenNamen = explode("\n", (string)($_POST['kandidaten'] ?? ''));
$anzahlAemterRoh = trim((string)($_POST['anzahl_aemter'] ?? ''));
$anzahlAemter = $anzahlAemterRoh === '' ? null : (int)$anzahlAemterRoh;

switch ($aktion) {

    case 'wahlgang_anlegen':
        [$id, $fehler, $hinweis] = wahlgang_anlegen($pdo, $jahr, (string)($_POST['bezeichnung'] ?? ''), (string)($_POST['modus'] ?? ''), $anzahlAemter, $kandidatenNamen);
        if ($fehler) {
            $_SESSION['admin_fehler'] = $fehler;
        } else {
            mv_log($pdo, $jahr, $mitglied, 'Wahlgang angelegt', sql_insert('mv_vote_wahlgaenge', ['jahr' => $jahr, 'bezeichnung' => $_POST['bezeichnung'] ?? '', 'modus' => $_POST['modus'] ?? '']));
            $_SESSION['admin_erfolg'] = 'Wahlgang angelegt.' . ($hinweis !== '' ? ' ' . $hinweis : '');
        }
        break;

    case 'wahlgang_aktualisieren':
        $id = (int)($_POST['id'] ?? 0);
        [$fehler, $hinweis] = wahlgang_aktualisieren($pdo, $jahr, $id, (string)($_POST['bezeichnung'] ?? ''), (string)($_POST['modus'] ?? ''), $anzahlAemter, $kandidatenNamen);
        if ($fehler) {
            $_SESSION['admin_fehler'] = $fehler;
        } else {
            mv_log($pdo, $jahr, $mitglied, 'Wahlgang geändert', sql_update('mv_vote_wahlgaenge', ['bezeichnung' => $_POST['bezeichnung'] ?? '', 'modus' => $_POST['modus'] ?? ''], ['id' => $id]));
            $_SESSION['admin_erfolg'] = 'Wahlgang gespeichert.' . ($hinweis !== '' ? ' ' . $hinweis : '');
        }
        break;

    case 'wahlgang_loeschen':
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            wahlgang_loeschen($pdo, $jahr, $id);
            mv_log($pdo, $jahr, $mitglied, 'Wahlgang gelöscht', sql_delete('mv_vote_wahlgaenge', ['id' => $id, 'jahr' => $jahr]));
            $_SESSION['admin_erfolg'] = 'Wahlgang gelöscht.';
        }
        break;
}

header('Location: ?tab=admin&bereich=wahlgaenge');
exit;
