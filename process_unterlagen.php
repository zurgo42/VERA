<?php
/**
 * process_unterlagen.php - Verarbeitet die Redaktionsaktionen fuer
 * "Unterlagen <Jahr>" UND die allgemeinen, jahresuebergreifenden
 * Dokumente (frueher eigener Tab "Weitere Unterlagen", jahr=0 als
 * Sentinel - siehe unterlagen_functions.php): jedes Formular in
 * tab_unterlagen_bearbeiten.php traegt ein Feld "ziel_jahr" (entweder die
 * aktuelle Session-Jahreszahl oder 0), das hier auf genau diese beiden
 * gueltigen Werte geprueft wird, um Schreibzugriffe auf andere Jahre
 * auszuschliessen. Wird nur bei POST auf tab=unterlagen eingebunden
 * (siehe index.php), das dortige Gating prueft bereits ist_redaktion() -
 * hier zusaetzlich zur Sicherheit noch einmal.
 */

require_once __DIR__ . '/unterlagen_functions.php';

if (!$ist_redaktion) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

csrf_check();

$aktion = $_POST['aktion'] ?? '';

// Nur die aktuelle Jahreszahl oder 0 (allgemein/jahresuebergreifend) sind gueltige Ziele.
$ziel_jahr = (int)($_POST['ziel_jahr'] ?? $jahr);
if ($ziel_jahr !== $jahr && $ziel_jahr !== 0) {
    http_response_code(400);
    die('Ungültiges Ziel.');
}
// Die jahresuebergreifenden Dokumente (ziel_jahr=0) sind von der Sperre
// bewusst ausgenommen - nur Aenderungen am jeweiligen Jahrgang selbst
// sind auf das aktuelle (neueste) Jahr beschraenkt.
if ($ziel_jahr === $jahr) {
    nur_aktuelles_jahr_bearbeitbar_oder_abbrechen($pdo, $jahr);
}

switch ($aktion) {

    case 'hinzufuegen':
        $titel = trim($_POST['titel'] ?? '');
        $beschreibung = trim($_POST['beschreibung'] ?? '');
        $link = trim($_POST['link'] ?? '');
        if ($titel !== '' && $link !== '') {
            unterlage_hinzufuegen($pdo, $ziel_jahr, $titel, $beschreibung, $link);
            mv_log($pdo, $jahr, $mitglied, 'Unterlage hinzugefügt', sql_insert('mvdateien', [
                'jahr' => $ziel_jahr, 'bezeichnung' => $titel, 'beschreibung' => $beschreibung, 'datei' => $link,
            ]));
        } else {
            $_SESSION['admin_fehler'] = 'Titel und Link sind erforderlich.';
        }
        break;

    case 'aktualisieren':
        $id = (int)($_POST['id'] ?? 0);
        $titel = trim($_POST['titel'] ?? '');
        $beschreibung = trim($_POST['beschreibung'] ?? '');
        $link = trim($_POST['link'] ?? '');
        $zeigen = isset($_POST['zeigen']);
        $farbe = $_POST['farbe'] ?? 'weiss';
        $spaltenumbruch = isset($_POST['spaltenumbruch']);
        if ($id > 0 && $titel !== '' && $link !== '') {
            unterlage_aktualisieren($pdo, $ziel_jahr, $id, $titel, $beschreibung, $link, $zeigen, $farbe, $spaltenumbruch);
            mv_log($pdo, $jahr, $mitglied, 'Unterlage aktualisiert', sql_update('mvdateien',
                ['bezeichnung' => $titel, 'beschreibung' => $beschreibung, 'datei' => $link, 'schonda' => $zeigen ? 1 : 0, 'farbe' => $farbe, 'spaltenumbruch' => $spaltenumbruch ? 1 : 0],
                ['id' => $id, 'jahr' => $ziel_jahr]));
        } else {
            $_SESSION['admin_fehler'] = 'Titel und Link sind erforderlich.';
        }
        break;

    case 'loeschen':
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            unterlage_loeschen($pdo, $ziel_jahr, $id);
            mv_log($pdo, $jahr, $mitglied, 'Unterlage gelöscht', sql_delete('mvdateien', ['id' => $id, 'jahr' => $ziel_jahr]));
        }
        break;

    case 'verschieben':
        $id = (int)($_POST['id'] ?? 0);
        $richtung = $_POST['richtung'] ?? '';
        if ($id > 0 && in_array($richtung, ['hoch', 'runter'], true)) {
            unterlage_verschieben($pdo, $ziel_jahr, $id, $richtung);
            mv_log($pdo, $jahr, $mitglied, 'Unterlage verschoben',
                sql_update('mvdateien', ['lfdnr' => "($richtung, getauscht mit Nachbar)"], ['id' => $id, 'jahr' => $ziel_jahr]));
        }
        break;

    case 'vorjahr_uebernehmen':
        $vorjahr = vorjahr_von($pdo, $jahr);
        if ($vorjahr === null) {
            $_SESSION['admin_fehler'] = 'Es liegt kein Vorjahr mit Unterlagen vor, das übernommen werden könnte.';
        } else {
            $anzahl = unterlagen_aus_vorjahr_uebernehmen($pdo, $vorjahr, $jahr);
            mv_log($pdo, $jahr, $mitglied, 'Unterlagen aus Vorjahr übernommen',
                'INSERT INTO mvdateien (' . $anzahl . ' Einträge aus ' . $vorjahr . ' nach ' . $jahr . ' kopiert, "zeigen" zurückgesetzt)');
            $_SESSION['admin_erfolg'] = $anzahl . ' Unterlagen aus ' . $vorjahr . ' übernommen (zunächst nicht sichtbar).';
        }
        break;
}

header('Location: ?tab=unterlagen&modus=bearbeiten');
exit;
