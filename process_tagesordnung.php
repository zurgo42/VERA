<?php
/**
 * process_tagesordnung.php - Verarbeitet alle schreibenden Aktionen der
 * Tagesordnungs-Redaktionsmaske. Wird nur bei POST auf tab=tagesordnung
 * eingebunden (siehe index.php), das dortige Gating prueft bereits
 * ist_redaktion() - hier zusaetzlich zur Sicherheit noch einmal.
 */

require_once __DIR__ . '/tagesordnung_functions.php';

if (!$ist_redaktion) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

csrf_check();
nur_aktuelles_jahr_bearbeitbar_oder_abbrechen($pdo, $jahr);

$aktion = $_POST['aktion'] ?? '';
$zurueck_top_id = (int)($_POST['top_id'] ?? 0);

switch ($aktion) {

    case 'standard_laden':
        if (!tagesordnung_existiert($pdo, $jahr)) {
            standard_tagesordnung_laden($pdo, $jahr);
            mv_log($pdo, $jahr, $mitglied, 'Standard-Tagesordnung geladen', "INSERT INTO mvtop (...) -- Standardvorlage für Jahr $jahr");
            $_SESSION['admin_erfolg'] = 'Standard-Tagesordnung geladen.';
        }
        break;

    case 'vorjahr_uebernehmen':
        if (!tagesordnung_existiert($pdo, $jahr)) {
            $erfolg = tagesordnung_vorjahr_uebernehmen($pdo, $jahr, $jahr - 1);
            if ($erfolg) {
                mv_log($pdo, $jahr, $mitglied, 'Tagesordnung aus Vorjahr übernommen', "INSERT INTO mvtop (...) -- übernommen aus Jahr " . ($jahr - 1));
            }
            $_SESSION[$erfolg ? 'admin_erfolg' : 'admin_fehler'] =
                $erfolg ? 'Tagesordnung aus ' . ($jahr - 1) . ' übernommen.' : 'Für ' . ($jahr - 1) . ' liegt keine Tagesordnung vor.';
        }
        break;

    case 'top_hinzufuegen':
        $titel = trim($_POST['titel'] ?? '');
        top_hinzufuegen($pdo, $jahr, $titel);
        mv_log($pdo, $jahr, $mitglied, 'Tagesordnungspunkt hinzugefügt', sql_insert('mvtop', ['jahr' => $jahr, 'titel' => $titel]));
        break;

    case 'top_speichern':
        $id = (int)($_POST['id'] ?? 0);
        $titel = trim($_POST['titel'] ?? '');
        $aktiv = isset($_POST['aktiv']);
        if ($id > 0) {
            top_aktualisieren($pdo, $jahr, $id, $titel, $aktiv);
            mv_log($pdo, $jahr, $mitglied, 'Tagesordnungspunkt gespeichert',
                sql_update('mvtop', ['titel' => $titel, 'aktiv' => $aktiv], ['id' => $id, 'jahr' => $jahr]));
        }
        break;

    case 'top_inhalte_speichern':
        $id = (int)($_POST['id'] ?? 0);
        $top_folie = trim($_POST['top_folie'] ?? '');
        $beschlussentwurf = trim($_POST['beschlussentwurf'] ?? '');
        if ($id > 0 && top_gehoert_zu_jahr($pdo, $id, $jahr)) {
            top_inhalte_speichern($pdo, $jahr, $id, $top_folie, $beschlussentwurf);
            mv_log($pdo, $jahr, $mitglied, 'TOP-Beamerinhalte gespeichert',
                sql_update('mvtop', ['top_folie' => mb_substr($top_folie, 0, 60), 'beschlussentwurf' => mb_substr($beschlussentwurf, 0, 60)], ['id' => $id, 'jahr' => $jahr]));
            $_SESSION['admin_erfolg'] = 'Beamerinhalte gespeichert.';
        }
        break;

    case 'top_loeschen':
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            top_loeschen($pdo, $jahr, $id);
            mv_log($pdo, $jahr, $mitglied, 'Tagesordnungspunkt gelöscht', sql_delete('mvtop', ['id' => $id, 'jahr' => $jahr]));
        }
        break;

    case 'top_verschieben':
        $id = (int)($_POST['id'] ?? 0);
        $richtung = $_POST['richtung'] ?? '';
        if ($id > 0 && in_array($richtung, ['hoch', 'runter'], true)) {
            top_verschieben($pdo, $jahr, $id, $richtung);
            mv_log($pdo, $jahr, $mitglied, 'Tagesordnungspunkt verschoben',
                sql_update('mvtop', ['position' => "($richtung, getauscht mit Nachbar)"], ['id' => $id, 'jahr' => $jahr]));
        }
        break;

    case 'dokument_hinzufuegen':
        $titel = trim($_POST['titel'] ?? '');
        $link = trim($_POST['url'] ?? '');
        $status = $_POST['status'] ?? 'vorbereitung';

        if ($zurueck_top_id > 0 && $titel !== '' && top_gehoert_zu_jahr($pdo, $zurueck_top_id, $jahr)) {
            $hochgeladener_pfad = isset($_FILES['datei']) ? top_datei_speichern($_FILES['datei'], $jahr) : null;

            if ($hochgeladener_pfad !== null) {
                dokument_hinzufuegen($pdo, $jahr, $zurueck_top_id, $titel, $hochgeladener_pfad, true, $status);
                mv_log($pdo, $jahr, $mitglied, 'TOP-Dokument hinzugefügt', sql_insert('mvtopdokumente', [
                    'top_id' => $zurueck_top_id, 'titel' => $titel, 'url' => $hochgeladener_pfad, 'ist_datei' => true, 'status' => $status,
                ]));
            } elseif ($link !== '') {
                dokument_hinzufuegen($pdo, $jahr, $zurueck_top_id, $titel, $link, false, $status);
                mv_log($pdo, $jahr, $mitglied, 'TOP-Dokument hinzugefügt', sql_insert('mvtopdokumente', [
                    'top_id' => $zurueck_top_id, 'titel' => $titel, 'url' => $link, 'ist_datei' => false, 'status' => $status,
                ]));
            } else {
                $_SESSION['admin_fehler'] = 'Bitte entweder eine Datei hochladen oder einen Link angeben.';
            }
        }
        break;

    case 'dokument_status':
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($id > 0) {
            dokument_status_aendern($pdo, $jahr, $id, $status);
            mv_log($pdo, $jahr, $mitglied, 'TOP-Dokument-Status geändert', sql_update('mvtopdokumente', ['status' => $status], ['id' => $id]));
        }
        break;

    case 'dokument_loeschen':
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            dokument_loeschen($pdo, $jahr, $id);
            mv_log($pdo, $jahr, $mitglied, 'TOP-Dokument gelöscht', sql_delete('mvtopdokumente', ['id' => $id]));
        }
        break;
}

$ziel = '?tab=tagesordnung&modus=bearbeiten';
if ($zurueck_top_id > 0) {
    $ziel .= '&top_id=' . $zurueck_top_id;
    if (($_POST['ansicht'] ?? '') === 'beamer') {
        $ziel .= '&ansicht=beamer';
    }
}
header('Location: ' . $ziel);
exit;
