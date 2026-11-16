<?php
/**
 * process_rechte.php - Aendert Rolle (mvmitglieder.Red) und/oder
 * Vorstandsamt (mvmitglieder.Vorstand) eines Mitglieds - zwei
 * unabhaengige Felder im Bestandsschema (siehe tab_rechte.php).
 * Wird nur bei POST auf tab=admin&bereich=rechte eingebunden (siehe index.php).
 * Zugriff ist bereits in index.php auf ist_redaktion() beschraenkt.
 */

csrf_check();
nur_aktuelles_jahr_bearbeitbar_oder_abbrechen($pdo, $jahr);

$erlaubte_rollen = array_keys(get_rollen());

$mnr = trim($_POST['mnr'] ?? '');
$entfernen = isset($_POST['entfernen']);
$neue_rolle = $entfernen ? 0 : (int)($_POST['rolle'] ?? -1);
$neu_vorstand = $entfernen ? 0 : (isset($_POST['vorstand']) ? 1 : 0);

if ($mnr === '' || !in_array($neue_rolle, $erlaubte_rollen, true)) {
    $_SESSION['admin_fehler'] = 'Ungültige Eingabe.';
} else {
    $tabelle = 'mvmitglieder_' . (int)$jahr;
    $stmt = $pdo->prepare("UPDATE `$tabelle` SET Red = ?, Vorstand = ? WHERE MNr = ?");
    $stmt->execute([$neue_rolle, $neu_vorstand, $mnr]);

    if ($stmt->rowCount() > 0) {
        mv_log($pdo, $jahr, $mitglied, 'Rolle/Vorstandsamt geändert',
            sql_update($tabelle, ['Red' => $neue_rolle, 'Vorstand' => $neu_vorstand], ['MNr' => $mnr]));
        $_SESSION['admin_erfolg'] = 'Rolle/Vorstandsamt für ' . $mnr . ' aktualisiert.';
    } else {
        $_SESSION['admin_fehler'] = 'Mitglied mit Mitgliedsnummer ' . h($mnr) . ' nicht gefunden oder Werte unveraendert.';
    }
}

header('Location: ?tab=admin&bereich=rechte');
exit;
