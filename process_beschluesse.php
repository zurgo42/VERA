<?php
/**
 * process_beschluesse.php - Verarbeitet die Redaktionsaktionen fuer die
 * MV-Beschluss-Sammlung. Wird nur bei POST auf tab=beschluesse
 * eingebunden (siehe index.php), das dortige Gating prueft bereits
 * ist_redaktion() - hier zusaetzlich zur Sicherheit noch einmal.
 */

require_once __DIR__ . '/beschluesse_functions.php';

if (!$ist_redaktion) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

csrf_check();

$aktion = $_POST['aktion'] ?? '';

switch ($aktion) {

    case 'speichern':
        $alte_nr = trim($_POST['alte_nr'] ?? '');
        $antrnr = trim($_POST['antrnr'] ?? '');
        $titel = trim($_POST['titel'] ?? '');
        $text = trim($_POST['text'] ?? '');

        if ($antrnr === '' || $titel === '' || $text === '') {
            $_SESSION['admin_fehler'] = 'Nummer, Titel und Wortlaut sind erforderlich.';
            $ziel = '?tab=beschluesse&modus=bearbeiten';
            if ($alte_nr !== '') {
                $ziel .= '&antrnr=' . urlencode($alte_nr);
            }
            header('Location: ' . $ziel);
            exit;
        }

        $relevant = (int)($_POST['relevant'] ?? 1);
        if (!array_key_exists($relevant, MV_BESCHLUSS_STATUS)) {
            $relevant = 1;
        }

        $daten = [
            'antrnr'    => $antrnr,
            'titel'     => $titel,
            'text'      => $text,
            'begr'      => trim($_POST['begr'] ?? ''),
            'ergebnis'  => trim($_POST['ergebnis'] ?? ''),
            'dafuer'    => (int)($_POST['dafuer'] ?? 0),
            'dagegen'   => (int)($_POST['dagegen'] ?? 0),
            'enth'      => (int)($_POST['enth'] ?? 0),
            'relevant'  => $relevant,
            'kategorie' => trim($_POST['kategorie'] ?? ''),
        ];
        beschluss_speichern($pdo, $alte_nr, $daten);
        $was = $alte_nr !== '' && $alte_nr !== $antrnr ? "Beschluss gespeichert (umbenannt von $alte_nr)" : 'Beschluss gespeichert';
        mv_log($pdo, $jahr, $mitglied, $was, sql_insert('mvbeschluesse', $daten) . " -- REPLACE (vorherige Nummer: " . ($alte_nr !== '' ? $alte_nr : '–') . ')');
        $_SESSION['admin_erfolg'] = 'Beschluss gespeichert.';
        header('Location: ?tab=beschluesse&stichwort=' . urlencode($antrnr) . '&gueltig=9');
        exit;

    case 'loeschen':
        $antrnr = trim($_POST['antrnr'] ?? '');
        if ($antrnr !== '') {
            beschluss_loeschen($pdo, $antrnr);
            mv_log($pdo, $jahr, $mitglied, 'Beschluss gelöscht', sql_delete('mvbeschluesse', ['antrnr' => $antrnr]));
            $_SESSION['admin_erfolg'] = 'Beschluss gelöscht.';
        }
        header('Location: ?tab=beschluesse');
        exit;
}

header('Location: ?tab=beschluesse');
exit;
