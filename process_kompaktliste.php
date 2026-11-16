<?php
/**
 * process_kompaktliste.php - Verarbeitet die einzige noch in der
 * Kompaktliste verbliebene schreibende Aktion: die Markierung von
 * Anträgen ohne ausreichende Unterstützung (siehe tab_kompaktliste.php).
 * Das ist eine inhaltliche Entscheidung, keine Beamer-Bedienung, und bleibt
 * deshalb der Redaktion vorbehalten - anders als die Beamer-/
 * Abstimmungssteuerung, die auf einer eigenen Seite liegt (siehe
 * tab_beamersteuerung.php/process_beamersteuerung.php, dort
 * ist_beamersteuerung_berechtigt()). Wird nur bei POST auf
 * tab=kompaktliste eingebunden (siehe index.php), das dortige Gating
 * prueft bereits die Redaktion - hier zusaetzlich zur Sicherheit noch
 * einmal.
 */

if (!$ist_redaktion) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

csrf_check();
nur_aktuelles_jahr_bearbeitbar_oder_abbrechen($pdo, $jahr);

if (($_POST['aktion'] ?? '') === 'quorum_markieren') {
    $ids = array_map('intval', $_POST['quorum'] ?? []);
    foreach ($ids as $antrag_id) {
        antrag_quorum_nicht_erreicht_markieren($pdo, $jahr, $antrag_id);
        mv_log($pdo, $jahr, $mitglied, 'Antrag als "Unterstützung nicht erreicht" markiert',
            sql_update('mvantraege_' . $jahr, ['Art' => ANTRAG_ART_QUORUM_NICHT_ERREICHT], ['ID' => $antrag_id]));
    }
    $_SESSION['admin_erfolg'] = $ids ? count($ids) . ' Antrag/Anträge markiert.' : 'Keine Anträge ausgewählt.';
}

$rueck_query = (string)($_POST['rueck_query'] ?? '');
header('Location: ?tab=kompaktliste' . ($rueck_query !== '' ? '&' . $rueck_query : ''));
exit;
