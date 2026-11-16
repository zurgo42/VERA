<?php
/**
 * process_vorgaben.php - Speichert die vereinsspezifischen Vorgaben (mvconfig).
 * Wird nur bei POST auf tab=admin&bereich=vorgaben eingebunden.
 */

csrf_check();

$felder = [
    'vereinsname'                     => 'text',
    'mv_bezeichnung'                  => 'text',
    'max_hauptantraege_pro_mitglied'  => 'int',
    'unterstuetzung_schwellenwert'    => 'int',
    'unterstuetzung_frist_wochen'     => 'int',
    'stimmungsbild_mindestteilnehmer' => 'int',
    'rueckzug_nur_bis_antragsschluss' => 'bool',
    'logo_pfad'                       => 'text',
    'favicon_pfad'                    => 'text',
    'diskussionsforum_aktiv'          => 'bool',
    'diskussionsforum_link'           => 'text',
    'portal_url'                      => 'text',
    'portal_name'                     => 'text',
    'beschluesse_aktiv'               => 'bool',
    'wahlinfo_aktiv'                  => 'bool',
    'sso_logout_url'                  => 'text',
    'abstimmung_basis_url'            => 'text',
    'wlan_ssid'                       => 'text',
    'wlan_passwort'                   => 'text',
    'enthaltung_zaehlt_mit'           => 'bool',
    'mitglieder_quelle'                => 'enum:ldap,tabelle,svmembers',
    'login_modul_aktiv'                => 'bool',
    'demo_modus_aktiv'                  => 'bool',
    'versammlung_beginn'               => 'datetime',
    'versammlung_ende'                 => 'datetime',
    'mitgliedschaftspruefung_aktiv'    => 'bool',
    'foto_quelle_url'                  => 'text',
    'hybride_versammlung_aktiv'        => 'bool',
];

// Per config.php-Konstante fest vorgegebene Felder sind im Formular
// "disabled" und werden deshalb beim Abschicken gar nicht mitgeschickt -
// hier ueberspringen, statt den bestehenden mvconfig-Wert faelschlich mit
// leer/aus zu ueberschreiben (die Konstante gewinnt ohnehin immer, siehe
// get_config() - das waere sonst nur unnoetiger Datenverlust).
$konstanten_ueberschrieben = [
    'vereinsname'       => defined('ORG_NAME') && ORG_NAME !== '',
    'logo_pfad'         => defined('ORG_LOGO') && ORG_LOGO !== '',
    'demo_modus_aktiv'  => defined('DEMO_MODE'),
    'mitglieder_quelle' => $config['demo_modus_aktiv'] === '1',
];

$fehler = [];
$gespeichert = [];
foreach ($felder as $schluessel => $typ) {
    if ($konstanten_ueberschrieben[$schluessel] ?? false) {
        continue;
    }
    $eingabe = $_POST[$schluessel] ?? null;

    if ($typ === 'bool') {
        $wert = isset($_POST[$schluessel]) ? '1' : '0';
    } elseif ($typ === 'text') {
        $wert = trim((string)$eingabe);
    } elseif (str_starts_with($typ, 'enum:')) {
        $erlaubt = explode(',', substr($typ, 5));
        $wert = (string)$eingabe;
        if (!in_array($wert, $erlaubt, true)) {
            $fehler[] = $schluessel;
            continue;
        }
    } elseif ($typ === 'datetime') {
        // Format von <input type="datetime-local">: "JJJJ-MM-TTTHH:MM".
        // Leer ist gueltig (= Zeitfenster/Pruefung deaktiviert).
        $wert = trim((string)$eingabe);
        if ($wert !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $wert)) {
            $fehler[] = $schluessel;
            continue;
        }
    } else {
        if (!is_numeric($eingabe) || (int)$eingabe < 0) {
            $fehler[] = $schluessel;
            continue;
        }
        $wert = (string)(int)$eingabe;
    }

    set_config_value($pdo, $schluessel, $wert);
    $gespeichert[$schluessel] = $wert;
}

if ($gespeichert) {
    mv_log($pdo, $jahr, $mitglied, 'Vereinsspezifische Vorgaben gespeichert', implode('; ', array_map(
        fn($k, $v) => sql_update('mvconfig', ['wert' => $v], ['schluessel' => $k]),
        array_keys($gespeichert), array_values($gespeichert)
    )));
}

$_SESSION[$fehler ? 'admin_fehler' : 'admin_erfolg'] =
    $fehler ? 'Ungültige Werte bei: ' . implode(', ', $fehler) : 'Vorgaben gespeichert.';

header('Location: ?tab=admin&bereich=vorgaben');
exit;
