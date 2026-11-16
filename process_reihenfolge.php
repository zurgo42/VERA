<?php
/**
 * process_reihenfolge.php - Speichert Reihenfolge (Rfolge) und
 * Gem-Gruppierung aller Hauptanträge auf einmal (siehe tab_reihenfolge.php).
 * Nur Redaktion, siehe index.php (bereich=reihenfolge nur dort verdrahtet).
 */

require_once __DIR__ . '/antraege_functions.php';

csrf_check();
nur_aktuelles_jahr_bearbeitbar_oder_abbrechen($pdo, $jahr);

$rfolge_werte = $_POST['rfolge'] ?? [];
$gem_werte = $_POST['gem'] ?? [];
$ids = array_unique(array_map('intval', array_merge(array_keys($rfolge_werte), array_keys($gem_werte))));

foreach ($ids as $antrag_id) {
    $antrag = get_antrag($pdo, $jahr, $antrag_id);
    if (!$antrag || (int)$antrag['Art'] !== ANTRAG_ART_HAUPTANTRAG) {
        continue;
    }

    $rfolge_eingabe = trim((string)($rfolge_werte[$antrag_id] ?? ''));
    $rfolge = $rfolge_eingabe === '' ? null : (int)$rfolge_eingabe;
    $gem_eingabe = trim((string)($gem_werte[$antrag_id] ?? ''));
    $gem = $gem_eingabe === '' ? null : (int)$gem_eingabe;

    antrag_aktualisieren($pdo, $jahr, $antrag_id, ['Rfolge' => $rfolge]);
    antrag_gem_setzen($pdo, $jahr, $antrag_id, $gem);
}

mv_log($pdo, $jahr, $mitglied, 'Reihenfolge/Gruppierung der Hauptanträge geändert', 'Reihenfolge/Gem fuer ' . count($ids) . ' Antrag/Anträge aktualisiert');
$_SESSION['admin_erfolg'] = 'Reihenfolge und Gruppierung wurden gespeichert.';

header('Location: ?tab=admin&bereich=reihenfolge');
exit;
