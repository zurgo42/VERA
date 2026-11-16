<?php
/**
 * process_demo_daten.php - Spielt den kompletten Demo-Datensatz
 * (demo_daten_functions.php: demo_daten_einspielen()) fuer das aktuelle
 * MV-Jahr ein. Nur erreichbar, wenn der Demo-Modus aktiv ist (siehe
 * tab_vorgaben.php) - das Einspielen ersetzt dabei bestehende Antraege/
 * Tagesordnung des Jahres vollstaendig.
 */

csrf_check();
nur_aktuelles_jahr_bearbeitbar_oder_abbrechen($pdo, $jahr);

if (($config['demo_modus_aktiv'] ?? '0') !== '1') {
    $_SESSION['admin_fehler'] = 'Demo-Daten lassen sich nur bei aktivem Demo-Modus einspielen.';
    header('Location: ?tab=admin&bereich=vorgaben');
    exit;
}

// Zusaetzlicher Admin-Passwort-Schutz (siehe system_admin_authentifiziert()
// in functions_neu.php) - analog zu den Schwesterprojekten Sitzungsverwaltung
// (KONA) und Mitgliederverwaltung (MISS): Red=9 (Redaktion) allein reicht
// fuer diese besonders folgenreiche, unwiderrufliche Aktion (loescht alle
// Antraege/die Tagesordnung des Jahres) nicht mehr aus.
if (!system_admin_authentifiziert() && !system_admin_passwort_pruefen((string)($_POST['admin_passwort'] ?? ''))) {
    $_SESSION['admin_fehler'] = 'Falsches oder fehlendes Admin-Passwort (SYSTEM_ADMIN_PASSWORD in config.php).';
    header('Location: ?tab=admin&bereich=vorgaben');
    exit;
}

require_once __DIR__ . '/demo_daten_functions.php';

try {
    $anzahl = demo_daten_einspielen($pdo, $jahr, $config);
    mv_log($pdo, $jahr, $mitglied, 'Demo-Daten eingespielt', $anzahl . ' Hauptanträge samt Tagesordnung/Unterstützungen/Stimmungsbild neu erzeugt (Jahr ' . $jahr . ').');
    $_SESSION['admin_erfolg'] = 'Demo-Daten eingespielt: ' . $anzahl . ' Hauptanträge mit Tagesordnung, Unterstützungen und Stimmungsbild.';
} catch (RuntimeException $e) {
    $_SESSION['admin_fehler'] = $e->getMessage();
}

header('Location: ?tab=admin&bereich=vorgaben');
exit;
