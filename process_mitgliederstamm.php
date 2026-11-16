<?php
/**
 * process_mitgliederstamm.php - Speichert/löscht Einträge der lokalen
 * Mitgliederstamm-Tabelle bzw. verarbeitet einen CSV-Import (siehe
 * tab_mitgliederstamm.php). Wird nur bei POST auf
 * tab=admin&bereich=mitgliederstamm eingebunden.
 */

require_once __DIR__ . '/mitgliederquelle_functions.php';

csrf_check();

$aktion = $_POST['aktion'] ?? '';

if ($aktion === 'speichern') {
    $mnr = trim((string)($_POST['mnr'] ?? ''));
    if ($mnr === '') {
        $_SESSION['admin_fehler'] = 'Bitte eine Mitgliedsnummer angeben.';
    } else {
        $vorname = trim((string)($_POST['vorname'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $mail = trim((string)($_POST['mail'] ?? ''));
        mitgliederstamm_speichern($pdo, $mnr, $vorname, $name, $mail);
        mv_log($pdo, $jahr, $mitglied, 'Mitgliederstamm-Eintrag gespeichert',
            sql_update('mitglieder_stamm', ['Vorname' => $vorname, 'Name' => $name, 'Mail' => $mail], ['MNr' => $mnr]));
        $_SESSION['admin_erfolg'] = 'Eintrag gespeichert.';
    }
} elseif ($aktion === 'loeschen') {
    $mnr = trim((string)($_POST['mnr'] ?? ''));
    if ($mnr !== '') {
        mitgliederstamm_loeschen($pdo, $mnr);
        mv_log($pdo, $jahr, $mitglied, 'Mitgliederstamm-Eintrag gelöscht', 'DELETE FROM mitglieder_stamm WHERE MNr=' . $mnr);
    }
    $_SESSION['admin_erfolg'] = 'Eintrag gelöscht.';
} elseif ($aktion === 'csv_import') {
    if (!empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
        $inhalt = file_get_contents($_FILES['csv']['tmp_name']);
        $anzahl = mitgliederstamm_csv_importieren($pdo, (string)$inhalt);
        mv_log($pdo, $jahr, $mitglied, 'Mitgliederstamm-CSV-Import (' . $anzahl . ' Zeilen)', '');
        $_SESSION['admin_erfolg'] = $anzahl . ' Einträge importiert.';
    } else {
        $_SESSION['admin_fehler'] = 'Bitte eine CSV-Datei auswählen.';
    }
}

header('Location: ?tab=admin&bereich=mitgliederstamm');
exit;
