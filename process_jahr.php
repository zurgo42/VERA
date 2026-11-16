<?php
/**
 * process_jahr.php - Verarbeitet "Neues Jahr anlegen" (Admin-Funktion).
 * Wird nur bei POST auf tab=admin&bereich=jahr eingebunden (siehe
 * index.php), das dortige Gating prueft bereits ist_redaktion() - hier
 * zusaetzlich zur Sicherheit noch einmal.
 */

require_once __DIR__ . '/jahr_functions.php';

if (!$ist_redaktion) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

csrf_check();

if (($_POST['aktion'] ?? '') === 'jahr_freigeben') {
    $freizugebendes_jahr = (int)($_POST['freizugebendes_jahr'] ?? 0);
    jahr_freigeben($pdo, $freizugebendes_jahr);
    mv_log($pdo, $jahr, $mitglied, 'Jahr freigegeben', sql_update('mvtexte', ['freigegeben' => 1], ['jahr' => $freizugebendes_jahr]));
    $_SESSION['admin_erfolg'] = 'Jahr ' . $freizugebendes_jahr . ' ist jetzt für alle Mitglieder sichtbar.';
    header('Location: ?tab=admin&bereich=jahr');
    exit;
}

$neu = (int)($_POST['neues_jahr'] ?? 0);
$vorjahr = get_aktuelles_jahr($pdo);

if ($neu <= 0) {
    $_SESSION['admin_fehler'] = 'Bitte ein gültiges Jahr angeben.';
} else {
    $fehler = neues_jahr_anlegen($pdo, $vorjahr, $neu);
    if ($fehler) {
        $_SESSION['admin_fehler'] = $fehler;
    } else {
        docs_verzeichnis_anlegen($neu);
        mv_log($pdo, $vorjahr, $mitglied, 'Neues Jahr angelegt', sql_insert('mvtexte', ['jahr' => $neu])
            . " -- mvmitglieder_$neu (nur Funktionstraeger:innen aus $vorjahr übernommen), leere mvantraege_$neu/mvunterst_$neu/mvvotum_$neu, Verzeichnis docs$neu");
        $_SESSION['admin_erfolg'] = 'Jahr ' . $neu . ' wurde angelegt (nur Mitglieder mit Rolle oder Vorstandsamt aus '
            . $vorjahr . ' übernommen, Dokumentenverzeichnis docs' . $neu . ' angelegt). Bitte unter „Termine“ '
            . 'Datum, Ort und Antragsschluss für ' . $neu . ' eintragen.';
    }
}

header('Location: ?tab=admin&bereich=jahr');
exit;
