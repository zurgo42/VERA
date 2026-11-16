<?php
/**
 * process_steuerung.php - Verarbeitet alle Formulare von steuerung.php
 * (siehe dort), Post-Redirect-Get wie bei VERAs process_beamersteuerung.php.
 * Nur dem Ersteller des Projekts oder einer Person mit Admin-Rechten
 * zugänglich (siehe beam_projekt_berechtigt()).
 */

require_once __DIR__ . '/../session_config.php';
session_start();
require_once __DIR__ . '/beam_functions.php';

$pdo = get_pdo();
$rechte = beam_aktuelle_rechte($pdo);
$projektId = (int)($_POST['projekt'] ?? 0);
$projekt = beam_projekt_get($pdo, $projektId);

if (!$rechte['eingeloggt'] || !$rechte['darf_steuern'] || !$projekt || !beam_projekt_berechtigt($projekt, $rechte)) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

csrf_check();

$mitgliedLog = ['MNr' => (string)$rechte['member_id'], 'Vorname' => $rechte['name'], 'Name' => ''];
$aktion = $_POST['aktion'] ?? '';

switch ($aktion) {

    case 'titel_setzen':
        $titel = trim((string)($_POST['titel'] ?? ''));
        if ($titel === '') {
            $_SESSION['beam_fehler'] = 'Bitte einen Titel angeben.';
            break;
        }
        $pdo->prepare('UPDATE beam_projekte SET titel = ? WHERE id = ?')->execute([$titel, $projektId]);
        beam_log($pdo, $projektId, $rechte, 'Titel geändert', $titel);
        break;

    case 'beamer_zeigen':
        $zeige = trim((string)($_POST['zeige'] ?? ''));
        $pausebis = trim((string)($_POST['pausebis'] ?? ''));
        $freitext = trim((string)($_POST['freitext'] ?? ''));
        if ($zeige === '') {
            $_SESSION['beam_fehler'] = 'Keine Folie ausgewählt.';
            break;
        }
        if ($zeige === 'willkommen') {
            // Verallgemeinert ("Herzlich willkommen" ohne "zur ...", siehe
            // dokumentation/BEAM.md) - BEAM hat keine Mitgliederversammlung,
            // deshalb hier direkt statt über beamer_zeige_verarbeiten(), das
            // VERAs mv_bezeichnung hartkodiert nutzt.
            beamer_inhalt_setzen($pdo, $projektId, beamer_rendern_spezial('willkommen', ''), true);
        } elseif (str_starts_with($zeige, 'inhalt:')) {
            $fehler = beam_inhalt_anzeigen($pdo, $projektId, (int)substr($zeige, 7));
            if ($fehler !== '') {
                $_SESSION['beam_fehler'] = $fehler;
            }
        } else {
            $fehler = beamer_zeige_verarbeiten($pdo, $projektId, $zeige, $pausebis, $freitext);
            if ($fehler) {
                $_SESSION['beam_fehler'] = $fehler;
            }
        }
        break;

    // Spontane Korrektur des GERADE projizierten Bilds waehrend der
    // laufenden Sitzung (siehe steuerung.php, Box "Aktuelles Bild
    // bearbeiten") - anders als 'beamer_zeigen' wird der bestehende Inhalt
    // direkt als HTML uebernommen statt neu gerendert; Logo-Status,
    // aktueller Wahlgang und der "zeige"-Kontext bleiben dabei unveraendert
    // erhalten.
    case 'beamer_bild_bearbeiten':
        $html = beamer_html_normalisieren((string)($_POST['html'] ?? ''));
        $mvtexte_aktuell = get_mvtexte($pdo, $projektId) ?? [];
        $wahlgangId = $mvtexte_aktuell['aktueller_wahlgang_id'] ?? null;
        beamer_inhalt_setzen(
            $pdo, $projektId, $html,
            (int)($mvtexte_aktuell['beamer_logo'] ?? 0) === 1,
            $wahlgangId !== null ? (int)$wahlgangId : null,
            $mvtexte_aktuell['beamer_zeige_aktuell'] ?? null
        );
        beam_log($pdo, $projektId, $rechte, 'Beamerbild manuell bearbeitet');
        break;

    case 'beamer_abstimmungsvariante':
        $typ = (string)($_POST['typ'] ?? 'ja_nein');
        $frage = trim((string)($_POST['frage'] ?? ''));
        if ($frage === '') {
            $_SESSION['beam_fehler'] = 'Bitte eine Frage oder einen Text angeben.';
            break;
        }
        if ($typ === 'ja_nein') {
            $fehler = beamer_zeige_verarbeiten($pdo, $projektId, 'text', '', $frage);
            if ($fehler) {
                $_SESSION['beam_fehler'] = $fehler;
            }
            break;
        }
        $modus = $typ === 'praeferenz' ? 'P' : 'B';
        $varianten = explode("\n", (string)($_POST['varianten'] ?? ''));
        [$wahlgangId, $fehler, $hinweis] = wahlgang_anlegen($pdo, $projektId, $frage, $modus, null, $varianten, 'abstimmung');
        if ($fehler) {
            $_SESSION['beam_fehler'] = $fehler;
            break;
        }
        $fehler = beamer_zeige_verarbeiten($pdo, $projektId, 'wahl:' . $wahlgangId);
        if ($fehler) {
            $_SESSION['beam_fehler'] = $fehler;
        } else {
            beam_log($pdo, $projektId, $rechte, 'Spontan-Abstimmungsfrage angezeigt', $frage);
            $_SESSION['beam_erfolg'] = 'Frage „' . $frage . '“ angezeigt - "Abstimmung starten" aktiviert sie für die Stimmkarten.' . ($hinweis !== '' ? ' ' . $hinweis : '');
        }
        break;

    case 'inhalt_text_speichern':
        $position = (int)($_POST['position'] ?? 0);
        $fehler = beam_inhalt_text_speichern($pdo, $projektId, $position, (string)($_POST['text'] ?? ''));
        if ($fehler !== '') {
            $_SESSION['beam_fehler'] = $fehler;
        }
        break;

    case 'inhalt_frage_speichern':
        $position = (int)($_POST['position'] ?? 0);
        $modus = $_POST['typ'] === 'praeferenz' ? 'P' : 'B';
        $varianten = explode("\n", (string)($_POST['varianten'] ?? ''));
        $fehler = beam_inhalt_frage_speichern($pdo, $projektId, $position, (string)($_POST['bezeichnung'] ?? ''), $modus, null, $varianten);
        if ($fehler !== '') {
            $_SESSION['beam_fehler'] = $fehler;
        }
        break;

    case 'inhalt_loeschen':
        beam_inhalt_loeschen($pdo, $projektId, (int)($_POST['position'] ?? 0));
        break;

    case 'akklamation_setzen':
        beamer_akklamation_setzen($pdo, $projektId);
        beam_log($pdo, $projektId, $rechte, 'Zustimmung per Akklamation vermerkt');
        break;

    case 'beamer_uhr':
        beamer_uhr_setzen($pdo, $projektId, isset($_POST['uhr']));
        break;

    case 'beamer_timer_start':
        $minuten = (int)($_POST['minuten'] ?? 0);
        if ($minuten > 0) {
            beamer_timer_starten($pdo, $projektId, $minuten);
        }
        break;

    case 'beamer_timer_stop':
        beamer_timer_stoppen($pdo, $projektId);
        break;

    case 'abstimmung_timer_start':
        $sekunden = max(0, (int)($_POST['minuten'] ?? 0)) * 60 + max(0, min(59, (int)($_POST['sekunden'] ?? 0)));
        if ($sekunden > 0) {
            beamer_abstimmung_timer_starten($pdo, $projektId, $sekunden, isset($_POST['auto_beenden']));
        }
        break;

    case 'abstimmung_timer_auto_beenden_aufheben':
        beamer_abstimmung_timer_auto_beenden_aufheben($pdo, $projektId);
        break;

    case 'abstimmung_starten':
        $mvtexte_aktuell = get_mvtexte($pdo, $projektId);
        $wahlgangId = $mvtexte_aktuell['aktueller_wahlgang_id'] ?? null;
        [$roundId, $fehler] = vote_round_starten($pdo, $projektId, (string)($mvtexte_aktuell['beamer'] ?? ''), $wahlgangId !== null ? (int)$wahlgangId : null);
        if ($fehler) {
            $_SESSION['beam_fehler'] = $fehler;
        } else {
            beam_log($pdo, $projektId, $rechte, $wahlgangId ? 'Wahl gestartet' : 'Abstimmung gestartet', 'Runde ' . $roundId);
        }
        break;

    case 'abstimmung_beenden':
        $roundId = (int)($_POST['round_id'] ?? 0);
        [$text, $fehler] = beamer_runde_beenden_und_auswerten($pdo, $projektId, $mitgliedLog, $roundId);
        if ($fehler !== '') {
            $_SESSION['beam_fehler'] = $fehler;
        } else {
            $_SESSION['beam_erfolg'] = $text;
        }
        break;

    case 'abstimmung_auswerten':
        $roundId = (int)($_POST['round_id'] ?? 0);
        [$text, $fehler] = beamer_runde_ergebnis_auswerten($pdo, $projektId, $mitgliedLog, $roundId);
        if ($fehler !== '') {
            $_SESSION['beam_fehler'] = $fehler;
        } else {
            $_SESSION['beam_erfolg'] = $text;
        }
        break;

    case 'stimmkarten_erzeugen':
        $anzahl = (int)($_POST['anzahl'] ?? 0);
        [$datei, $fehler] = vote_stimmkarten_erzeugen($pdo, $projektId, $anzahl, beam_basis_url(), $projekt['titel']);
        if ($fehler) {
            $_SESSION['beam_fehler'] = $fehler;
        } else {
            beam_log($pdo, $projektId, $rechte, 'Stimmkarten erzeugt', $anzahl . ' Stimmkarte(n), Datei ' . $datei);
            // Bewusst kein dauerhaftes Verzeichnis aller je erzeugten PDFs
            // (wie vote_pdf_liste() es für VERA liefert) - der Speicherort
            // (vote_storage_dir()) ist projektübergreifend gemeinsam genutzt,
            // eine solche Liste würde also auch Dateinamen anderer Projekte/
            // von VERA offenlegen. Der Link hier gilt nur der gerade selbst
            // erzeugten Datei.
            $_SESSION['beam_erfolg_html'] = $anzahl . ' Stimmkarte(n) erzeugt: <a href="stimmkarten_pdf.php?datei='
                . h(urlencode($datei)) . '">' . h($datei) . ' herunterladen</a>.';
        }
        break;

    case 'stimmkarten_loeschen':
        $fehler = vote_tokens_loeschen($pdo, $projektId);
        if ($fehler) {
            $_SESSION['beam_fehler'] = $fehler;
        } else {
            beam_log($pdo, $projektId, $rechte, 'Stimmkarten-Pool gelöscht');
            $_SESSION['beam_erfolg'] = 'Stimmkarten-Pool gelöscht.';
        }
        break;
}

header('Location: steuerung.php?projekt=' . $projektId);
exit;
