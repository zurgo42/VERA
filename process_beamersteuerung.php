<?php
/**
 * process_beamersteuerung.php - Verarbeitet die Beamersteuerung (eigene
 * Seite, siehe tab_beamersteuerung.php). Wird nur bei POST auf
 * tab=beamersteuerung eingebunden (siehe index.php), das dortige Gating
 * prueft bereits ist_beamersteuerung_berechtigt() - hier zusaetzlich zur
 * Sicherheit noch einmal. Bis zur Aufteilung in eine eigene Seite hiess
 * diese Datei process_beamer.php und wurde von der Kompaktliste aus
 * bedient (jedem Funktionsteam offen) - die Bedienung ist jetzt auf
 * Technik/Beamer-Team und Redaktionsteam eingegrenzt (siehe
 * ist_beamersteuerung_berechtigt()). Die Quorum-Markierung ist dagegen
 * eine inhaltliche Entscheidung der Antragsliste, keine Beamer-Bedienung -
 * die bleibt in process_kompaktliste.php.
 */

require_once __DIR__ . '/beamer_functions.php';
require_once __DIR__ . '/abstimmung_functions.php';
require_once __DIR__ . '/wahl_functions.php';

if (!ist_beamersteuerung_berechtigt($mitglied)) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

csrf_check();
nur_aktuelles_jahr_bearbeitbar_oder_abbrechen($pdo, $jahr);

$aktion = $_POST['aktion'] ?? '';

switch ($aktion) {

    case 'beamer_zeigen':
        $zeige = trim($_POST['zeige'] ?? '');
        $pausebis = trim($_POST['pausebis'] ?? '');
        $freitext = trim($_POST['freitext'] ?? '');
        if ($zeige === '') {
            $_SESSION['admin_fehler'] = 'Keine Folie ausgewählt.';
            break;
        }
        $fehler = beamer_zeige_verarbeiten($pdo, $jahr, $zeige, $pausebis, $freitext);
        if ($fehler) {
            $_SESSION['admin_fehler'] = $fehler;
        } else {
            mv_log($pdo, $jahr, $mitglied, 'Beamerbild geändert', sql_update('mvtexte', ['beamer' => '(' . $zeige . ')'], ['jahr' => $jahr]));
        }
        break;

    // Editierbare Folie mit Abstimmungs-Variante (siehe
    // dokumentation/Abstimmungsmaschine.md) - "ja_nein" ist nur der
    // bisherige Freitext (die Abstimmungsmaschine fragt dafuer ohnehin
    // Ja/Nein/Enthaltung ab, kein Wahlgang noetig); "auswahl"/"praeferenz"
    // legen dafuer einen ad-hoc Wahlgang an (Modus B bzw. P, art=abstimmung
    // - siehe wahl_functions.php) und zeigen ihn sofort.
    case 'beamer_abstimmungsvariante':
        $typ = (string)($_POST['typ'] ?? 'ja_nein');
        $frage = trim((string)($_POST['frage'] ?? ''));
        if ($frage === '') {
            $_SESSION['admin_fehler'] = 'Bitte eine Frage oder einen Text angeben.';
            break;
        }
        if ($typ === 'ja_nein') {
            $fehler = beamer_zeige_verarbeiten($pdo, $jahr, 'text', '', $frage);
            if ($fehler) {
                $_SESSION['admin_fehler'] = $fehler;
            } else {
                mv_log($pdo, $jahr, $mitglied, 'Beamerbild geändert (Abstimmungsfrage)', sql_update('mvtexte', ['beamer' => '(text)'], ['jahr' => $jahr]));
            }
            break;
        }
        $modus = $typ === 'praeferenz' ? 'P' : 'B';
        $varianten = explode("\n", (string)($_POST['varianten'] ?? ''));
        [$wahlgang_id, $fehler, $hinweis] = wahlgang_anlegen($pdo, $jahr, $frage, $modus, null, $varianten, 'abstimmung');
        if ($fehler) {
            $_SESSION['admin_fehler'] = $fehler;
            break;
        }
        $fehler = beamer_zeige_verarbeiten($pdo, $jahr, 'wahl:' . $wahlgang_id);
        if ($fehler) {
            $_SESSION['admin_fehler'] = $fehler;
        } else {
            mv_log($pdo, $jahr, $mitglied, 'Abstimmungsfrage angelegt und angezeigt', sql_insert('mv_vote_wahlgaenge', ['jahr' => $jahr, 'bezeichnung' => $frage, 'modus' => $modus, 'art' => 'abstimmung']));
            $_SESSION['admin_erfolg'] = 'Abstimmungsfrage „' . $frage . '“ angezeigt - "Abstimmung starten" aktiviert sie für die Stimmkarten.' . ($hinweis !== '' ? ' ' . $hinweis : '');
        }
        break;

    // Spontane Korrektur des GERADE projizierten Bilds waehrend der
    // laufenden Sitzung (siehe tab_beamersteuerung.php, Box "Aktuelles
    // Bild bearbeiten") - anders als 'beamer_zeigen' wird hier der
    // bestehende Inhalt direkt als HTML uebernommen statt neu gerendert;
    // Logo-Status, aktueller Wahlgang und der "zeige"-Kontext (fuer
    // Akkordion/fette Markierung, siehe beamer_zeige_aktuell) bleiben
    // dabei unveraendert erhalten.
    case 'beamer_bild_bearbeiten':
        $html = beamer_html_normalisieren((string)($_POST['html'] ?? ''));
        $wahlgang_id = $mvtexte['aktueller_wahlgang_id'] ?? null;
        beamer_inhalt_setzen(
            $pdo, $jahr, $html,
            (int)($mvtexte['beamer_logo'] ?? 0) === 1,
            $wahlgang_id !== null ? (int)$wahlgang_id : null,
            $mvtexte['beamer_zeige_aktuell'] ?? null
        );
        mv_log($pdo, $jahr, $mitglied, 'Beamerbild manuell bearbeitet', sql_update('mvtexte', ['beamer' => '(manuell bearbeitet)'], ['jahr' => $jahr]));
        $_SESSION['admin_erfolg'] = 'Beamerbild aktualisiert.';
        break;

    case 'beamer_timer_start':
        $minuten = (int)($_POST['minuten'] ?? 0);
        if ($minuten <= 0) {
            $_SESSION['admin_fehler'] = 'Bitte eine positive Minutenzahl angeben.';
            break;
        }
        beamer_timer_starten($pdo, $jahr, $minuten);
        mv_log($pdo, $jahr, $mitglied, 'Beamer-Timer gestartet', sql_update('mvtexte', ['timer' => "+$minuten Minuten", 'vorgabe' => $minuten], ['jahr' => $jahr]));
        break;

    case 'beamer_timer_stop':
        beamer_timer_stoppen($pdo, $jahr);
        mv_log($pdo, $jahr, $mitglied, 'Beamer-Timer gestoppt', sql_update('mvtexte', ['timer' => null], ['jahr' => $jahr]));
        break;

    // Eigener Timer der Abstimmungsmaschine (getrennt vom allgemeinen Timer
    // oben - der ist fuer Redezeiten/Pausen und beendet keine Abstimmungen
    // mehr automatisch). Minuten+Sekunden, weil einfache Abstimmungen auch
    // mal nur 20 Sekunden brauchen.
    case 'abstimmung_timer_start':
        $minuten = max(0, (int)($_POST['minuten'] ?? 0));
        $sekunden = max(0, (int)($_POST['sekunden'] ?? 0));
        $gesamtSek = $minuten * 60 + $sekunden;
        if ($gesamtSek <= 0) {
            $_SESSION['admin_fehler'] = 'Bitte eine positive Dauer (Minuten und/oder Sekunden) angeben.';
            break;
        }
        $autoBeenden = isset($_POST['auto_beenden']);
        beamer_abstimmung_timer_starten($pdo, $jahr, $gesamtSek, $autoBeenden);
        mv_log($pdo, $jahr, $mitglied, 'Abstimmungs-Timer gestartet', sql_update('mvtexte', [
            'abstimmung_timer' => "+$gesamtSek Sekunden",
            'abstimmung_timer_vorgabe_sek' => $gesamtSek,
            'abstimmung_timer_auto_beenden' => $autoBeenden ? 1 : 0,
        ], ['jahr' => $jahr]));
        break;

    // Notfall-Button bei technischen Problemen waehrend einer laufenden,
    // automatisch beendenden Abstimmung: beendet NICHT die Abstimmung,
    // sondern hebt nur die Automatik auf - der Timer selbst (Anzeige,
    // Ablaufbalken) laeuft unveraendert weiter, zwingt aber nicht mehr zum
    // Ende. "Abstimmung beenden" bleibt wie gewohnt manuell moeglich.
    case 'abstimmung_timer_auto_beenden_aufheben':
        beamer_abstimmung_timer_auto_beenden_aufheben($pdo, $jahr);
        mv_log($pdo, $jahr, $mitglied, 'Automatisches Beenden der Abstimmung aufgehoben', sql_update('mvtexte', ['abstimmung_timer_auto_beenden' => 0], ['jahr' => $jahr]));
        $_SESSION['admin_erfolg'] = 'Automatisches Beenden aufgehoben - die Abstimmung läuft weiter, bis sie manuell beendet wird.';
        break;

    // Nicht immer ist eine foermliche Abstimmung noetig (siehe
    // beamer_akklamation_setzen()) - vermerkt den aktuell gezeigten Inhalt
    // als per Akklamation beschlossen, statt eine Stimmkarten-Abfrage zu
    // starten.
    case 'akklamation_setzen':
        beamer_akklamation_setzen($pdo, $jahr);
        mv_log($pdo, $jahr, $mitglied, 'Zustimmung per Akklamation vermerkt', sql_update('mvtexte', ['akklamation' => 1], ['jahr' => $jahr]));
        $_SESSION['admin_erfolg'] = 'Zustimmung per Akklamation vermerkt.';
        break;

    case 'beamer_uhr':
        $an = isset($_POST['uhr']);
        beamer_uhr_setzen($pdo, $jahr, $an);
        mv_log($pdo, $jahr, $mitglied, 'Beamer-Uhr umgeschaltet', sql_update('mvtexte', ['uhr' => $an ? 'j' : 'n'], ['jahr' => $jahr]));
        break;

    // Abstimmungsmaschine (siehe dokumentation/Abstimmungsmaschine.md, Phase 2/3) -
    // startet/beendet eine Runde mit dem gerade gezeigten Beamerbild als Fragetext.
    // Zeigt der Beamer gerade einen Wahlgang (Präfix "wahl:", siehe
    // beamer_zeige_verarbeiten()), wird daraus automatisch eine Wahlrunde
    // statt einer einfachen Ja/Nein/Enthaltung-Abstimmung (Phase 6, siehe
    // wahl_functions.php).
    case 'abstimmung_starten':
        $mvtexte_aktuell = get_mvtexte($pdo, $jahr);
        $wahlgang_id = $mvtexte_aktuell['aktueller_wahlgang_id'] ?? null;
        [$round_id, $fehler] = vote_round_starten($pdo, $jahr, (string)($mvtexte_aktuell['beamer'] ?? ''), $wahlgang_id !== null ? (int)$wahlgang_id : null);
        if ($fehler) {
            $_SESSION['admin_fehler'] = $fehler;
        } else {
            mv_log($pdo, $jahr, $mitglied, $wahlgang_id ? 'Wahl gestartet' : 'Abstimmung gestartet', 'Runde ' . $round_id);
            $_SESSION['admin_erfolg'] = ($wahlgang_id ? 'Wahl' : 'Abstimmung') . ' gestartet.';
        }
        break;

    // Beenden loest jetzt sofort die Auswertung mit aus (kein zweiter Klick
    // mehr noetig) - siehe beamer_runde_beenden_und_auswerten() in
    // beamer_functions.php. "abstimmung_auswerten" bleibt als Fallback-
    // Aktion erhalten, falls die automatische Auswertung dabei ausnahmsweise
    // fehlschlaegt (siehe dort sowie die "wartend"-Anzeige in
    // tab_beamersteuerung.php).
    case 'abstimmung_beenden':
        $round_id = (int)($_POST['round_id'] ?? 0);
        [$text, $fehler] = beamer_runde_beenden_und_auswerten($pdo, $jahr, $mitglied, $round_id);
        if ($fehler !== '') {
            $_SESSION['admin_fehler'] = $fehler;
        } else {
            $_SESSION['admin_erfolg'] = $text;
        }
        break;

    case 'abstimmung_auswerten':
        $round_id = (int)($_POST['round_id'] ?? 0);
        $round_vorher = vote_round_get($pdo, $round_id);
        [$text, $fehler] = beamer_runde_ergebnis_auswerten($pdo, $jahr, $mitglied, $round_id);
        if ($fehler !== '') {
            $_SESSION['admin_fehler'] = $fehler;
        } else {
            $ist_wahl = !empty($round_vorher['wahlgang_id']);
            $_SESSION['admin_erfolg'] = ($ist_wahl ? 'Wahl' : 'Abstimmung') . ' ausgewertet: ' . $text;
        }
        break;
}

header('Location: ?tab=beamersteuerung');
exit;
