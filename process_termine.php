<?php
/**
 * process_termine.php - Speichert die Termine des aktuellen Jahres (mvtexte).
 * Wird nur bei POST auf tab=admin&bereich=termine eingebunden.
 *
 * Prueft per SELECT, ob fuer $jahr schon eine Zeile existiert, statt eine
 * eindeutige Schluesselspalte vorauszusetzen (im Bestandsschema nicht
 * dokumentiert) - so funktioniert es so oder so.
 */

csrf_check();
nur_aktuelles_jahr_bearbeitbar_oder_abbrechen($pdo, $jahr);

mvtexte_spalten_sicherstellen($pdo);

$mvdatum = trim($_POST['mvdatum'] ?? '');
$mvbeginn = trim($_POST['mvbeginn'] ?? '');
$mvort = trim($_POST['mvort'] ?? '');
$antragsende = trim($_POST['antragsende'] ?? '');
$unterstuetzungsende = trim($_POST['unterstuetzungsende'] ?? '');
$wahllink = trim($_POST['wahllink'] ?? '');
$wahlinfo = trim($_POST['wahlinfo'] ?? '');

$stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM mvtexte WHERE jahr = ?');
$stmt->execute([$jahr]);
$existiert = (int)$stmt->fetch()['n'] > 0;

$werte = [
    'mvdatum' => $mvdatum, 'mvbeginn' => $mvbeginn, 'mvort' => $mvort, 'antragsende' => $antragsende,
    'unterstuetzungsende' => $unterstuetzungsende, 'wahllink' => $wahllink, 'wahlinfo' => $wahlinfo,
];

if ($existiert) {
    $stmt = $pdo->prepare(
        'UPDATE mvtexte SET mvdatum = ?, mvbeginn = ?, mvort = ?, antragsende = ?, unterstuetzungsende = ?, wahllink = ?, wahlinfo = ? WHERE jahr = ?'
    );
    $stmt->execute([$mvdatum, $mvbeginn, $mvort, $antragsende, $unterstuetzungsende, $wahllink, $wahlinfo, $jahr]);
    $protokoll_sql = sql_update('mvtexte', $werte, ['jahr' => $jahr]);
} else {
    $stmt = $pdo->prepare(
        'INSERT INTO mvtexte (jahr, mvdatum, mvbeginn, mvort, antragsende, unterstuetzungsende, wahllink, wahlinfo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$jahr, $mvdatum, $mvbeginn, $mvort, $antragsende, $unterstuetzungsende, $wahllink, $wahlinfo]);
    $protokoll_sql = sql_insert('mvtexte', array_merge(['jahr' => $jahr], $werte));
}

mv_log($pdo, $jahr, $mitglied, 'Termine gespeichert', $protokoll_sql);
$_SESSION['admin_erfolg'] = 'Termine für ' . $jahr . ' gespeichert.';
header('Location: ?tab=admin&bereich=termine');
exit;
