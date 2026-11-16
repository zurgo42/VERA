<?php
/**
 * process_mvtext.php - Speichert die jahresabhaengigen, durch das
 * Redaktionsteam editierbaren Freitexte in mvtexte (willkommenstext,
 * wahltext, unterlagentext), inkl. "Aus Vorjahr übernehmen". Gemeinsam
 * genutzt von tab_willkommen.php, tab_wahlinfo.php und tab_unterlagen.php,
 * da sich die drei Texte nur im Spaltennamen unterscheiden. Wird nur bei
 * POST mit gesetztem $_POST['feld'] eingebunden (siehe index.php), das
 * dortige Gating prueft bereits ist_redaktion().
 */

require_once __DIR__ . '/db_functions.php';

const MVTEXT_FELDER = ['willkommenstext', 'wahltext', 'unterlagentext'];

if (!$ist_redaktion) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

csrf_check();
nur_aktuelles_jahr_bearbeitbar_oder_abbrechen($pdo, $jahr);

$feld = $_POST['feld'] ?? '';
$rueck_tab = $_POST['rueck_tab'] ?? 'willkommen';
if (!in_array($feld, MVTEXT_FELDER, true)) {
    http_response_code(400);
    die('Ungültiges Textfeld.');
}

mvtexte_spalten_sicherstellen($pdo);
$aktion = $_POST['aktion'] ?? '';

if ($aktion === 'vorjahr_uebernehmen') {
    $stmt = $pdo->prepare("SELECT jahr, `$feld` AS wert FROM mvtexte WHERE jahr < ? AND `$feld` IS NOT NULL AND `$feld` <> '' ORDER BY jahr DESC LIMIT 1");
    $stmt->execute([$jahr]);
    $vorjahr = $stmt->fetch();
    if (!$vorjahr) {
        $_SESSION['admin_fehler'] = 'Es liegt kein Text aus einem Vorjahr vor, der übernommen werden könnte.';
    } else {
        $pdo->prepare("UPDATE mvtexte SET `$feld` = ? WHERE jahr = ?")->execute([$vorjahr['wert'], $jahr]);
        mv_log($pdo, $jahr, $mitglied, 'Text aus Vorjahr übernommen (' . $feld . ')',
            sql_update('mvtexte', [$feld => $vorjahr['wert']], ['jahr' => $jahr]));
        $_SESSION['admin_erfolg'] = 'Text aus ' . $vorjahr['jahr'] . ' übernommen.';
    }
} else {
    $text = trim($_POST['text'] ?? '');
    $pdo->prepare("UPDATE mvtexte SET `$feld` = ? WHERE jahr = ?")->execute([$text, $jahr]);
    mv_log($pdo, $jahr, $mitglied, 'Text gespeichert (' . $feld . ')', sql_update('mvtexte', [$feld => $text], ['jahr' => $jahr]));
    $_SESSION['admin_erfolg'] = 'Text gespeichert.';
}

header('Location: ?tab=' . $rueck_tab);
exit;
