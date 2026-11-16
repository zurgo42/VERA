<?php
/**
 * beamer_functions.php - Steuerung des Beamerbilds (Ablösung von
 * mvifolien.php/mvfolien.php/mvbeamer.php).
 *
 * Architektur wie im Bestandssystem: Technik/Beamer-Team oder Redaktion
 * waehlen auf der Beamersteuerung (tab_beamersteuerung.php) eine Folie
 * aus, die als vorgerenderter HTML-Ausschnitt in mvtexte.beamer geschrieben wird
 * (Spalte existiert bereits im Bestandssystem). Die separate, staendig
 * aktualisierende Beamerbild-Seite (beamerbild.php) liest diese Spalte
 * nur noch aus - sie kennt keine Antrags-/Tagesordnungslogik. Das haelt
 * die Anzeigeseite extrem einfach und schnell, genau wie im Altsystem.
 */

require_once __DIR__ . '/antraege_functions.php';
require_once __DIR__ . '/tagesordnung_functions.php';
require_once __DIR__ . '/wahl_functions.php';
require_once __DIR__ . '/protokoll_functions.php';

const BEAMER_FARBE = '#0a1f6e';

/**
 * Errechnet naeherungsweise, in wie viele Zeilen $text bei einer
 * angenommenen Zeilenbreite von $zeilenlaenge Zeichen umbricht -
 * wortweise, wie im Altsystem (ifunktionen_mv.php/mvifolien.php:
 * anzahl_zeilen()). Dient nur der Schriftgroessen-Optimierung unten, kein
 * echtes Layout.
 */
function beamer_anzahl_zeilen(string $text, int $zeilenlaenge): int {
    $text = trim($text);
    if ($text === '') {
        return 1;
    }
    $zeilen = 0;
    $aktuelle_zeilenlaenge = 0;
    foreach (explode(' ', $text) as $wort) {
        $laenge_wort = mb_strlen($wort);
        if ($aktuelle_zeilenlaenge + $laenge_wort > $zeilenlaenge) {
            $zeilen++;
            $aktuelle_zeilenlaenge = $laenge_wort;
        } else {
            $aktuelle_zeilenlaenge += $laenge_wort;
        }
    }
    return $zeilen + 1;
}

/**
 * Sucht die groesste Schriftgroesse zwischen $von und $bis px, bei der
 * $text (nach der Naeherung aus beamer_anzahl_zeilen()) noch in die
 * Folie passt - genau das Vorgehen aus mvifolien.php: mit der groessten
 * Schrift anfangen und verkleinern, bis die geschaetzte Zeilenzahl unter
 * die (mit kleinerer Schrift wachsende) Vorgabe faellt. Gibt die
 * Schriftgroesse zurueck (nie kleiner als $bis).
 */
function beamer_schriftgroesse_optimieren(string $text, int $von = 36, int $bis = 20): int {
    for ($groesse = $von; $groesse > $bis; $groesse--) {
        $zeilenlaenge = 60 + ($von - $groesse) * 2;
        $zeilenvorgabe = 18 + ($von - $groesse);
        if (beamer_anzahl_zeilen($text, $zeilenlaenge) < $zeilenvorgabe) {
            return $groesse;
        }
    }
    return $bis;
}

/**
 * Schriftgroesse fuer eine Liste kurzer Einträge (Kandidat:innen/Varianten
 * eines Wahlgangs, siehe wahl_rendern_beamer()) - anders als bei
 * beamer_schriftgroesse_optimieren() steht hier nicht ein durchlaufender
 * Fließtext, sondern je ein Eintrag in einer eigenen Zeile: die Schrift
 * schrumpft deshalb mit der Anzahl der Einträge und zusätzlich, falls der
 * längste Name selbst schon lang ist (und sonst umbrechen würde).
 */
function beamer_listengroesse_optimieren(array $namen, int $von = 26, int $bis = 16): int {
    if (!$namen) {
        return $von;
    }
    $anzahl = count($namen);
    $laengster = max(array_map('mb_strlen', $namen));
    $groesse = $von;
    if ($anzahl > 6) {
        $groesse -= min($von - $bis, $anzahl - 6);
    }
    if ($laengster > 40) {
        $groesse -= min($von - $bis, (int)round(($laengster - 40) / 5));
    }
    return max($bis, $groesse);
}

/**
 * Ablage des Rohprotokolls (gleiche Konvention wie TOP-Dateien, siehe
 * tagesordnung_functions.php: top_datei_speichern() - uploads/<jahr>/).
 */
function beamer_protokoll_pfad(int $jahr): string {
    $ordner = __DIR__ . '/uploads/' . $jahr;
    if (!is_dir($ordner)) {
        mkdir($ordner, 0755, true);
        // Verhindert die Ausfuehrung als Skript, falls der Server ueber Apache/mod_php laeuft.
        @file_put_contents($ordner . '/.htaccess', "php_flag engine off\n");
    }
    return $ordner . '/rohprotokoll.txt';
}

/**
 * Letzter protokollierter Text (ohne Datum/Uhrzeit) im Rohprotokoll eines
 * Jahres, oder null, wenn es noch keinen gibt - siehe
 * beamer_protokoll_schreiben() (Erkennung unmittelbar wiederholter
 * Eintraege).
 */
function beamer_protokoll_letzte_zeile(int $jahr): ?string {
    $zeilen = @file(beamer_protokoll_pfad($jahr), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$zeilen) {
        return null;
    }
    $teile = explode("\t", end($zeilen), 3);
    return $teile[2] ?? null;
}

/**
 * Haengt eine Zeile ans Rohprotokoll der Versammlung an: Datum, Uhrzeit und
 * der tatsaechlich angezeigte Inhalt als Klartext (HTML-Tags entfernt,
 * Absatzgrenzen als " — " markiert) - Grundlage fuer das offizielle
 * Versammlungsprotokoll. Wird von beamer_inhalt_setzen() bei JEDER
 * Aenderung des projizierten Beamerbilds automatisch aufgerufen, egal
 * welche Beamerfunktion die Aenderung ausgeloest hat, sowie direkt von
 * beamer_runde_ergebnis_auswerten() fuer Abstimmungs-/Wahlergebnisse (die
 * sonst nirgends im Rohprotokoll aufgetaucht waeren, da eine Auswertung
 * selbst keinen Folienwechsel ausloest).
 *
 * Ein Eintrag, dessen Text exakt dem unmittelbar vorherigen entspricht
 * (z. B. durch einen Doppelklick auf denselben Link oder eine versehentlich
 * doppelt abgeschickte Formular-Anfrage), wird NICHT erneut angehaengt -
 * er wuerde das Rohprotokoll nur unnoetig mit Wiederholungen aufblaehen,
 * ohne eine echte Aenderung zu dokumentieren. Ein inhaltlich anderer
 * Eintrag (auch zum selben Thema, z. B. nach einer Korrektur über
 * "Aktuelles Bild bearbeiten") wird dagegen ganz normal mitgeschrieben.
 */
function beamer_protokoll_schreiben(int $jahr, string $html): void {
    $normalisiert = (string)preg_replace('/<(br\s*\/?|\/p|\/div|\/li)>/i', ' — ', $html);
    $text = trim((string)preg_replace('/\s+/', ' ', strip_tags($normalisiert)));
    $text = trim((string)preg_replace('/(\s*—\s*)+/', ' — ', $text), " —\t\n\r\0\x0B");
    if ($text === '') {
        $text = '(leere Folie)';
    }
    if (beamer_protokoll_letzte_zeile($jahr) === $text) {
        return;
    }
    $zeile = date('d.m.Y') . "\t" . date('H:i:s') . "\t" . $text . "\n";
    @file_put_contents(beamer_protokoll_pfad($jahr), $zeile, FILE_APPEND | LOCK_EX);
}

/**
 * Schreibt eine neue Folie ins Beamerbild (mvtexte.beamer) und protokolliert
 * die Änderung (siehe beamer_protokoll_schreiben()). $wahlgangId: gesetzt nur
 * beim Präfix "wahl:" (siehe beamer_zeige_verarbeiten()) - jeder andere Aufruf
 * setzt mvtexte.aktueller_wahlgang_id damit automatisch wieder auf NULL
 * zurück, ohne dass jede einzelne Beamerfunktion das explizit tun müsste.
 * Aus demselben Grund wird hier auch ergebnis_round_id zurückgesetzt: ein
 * zuletzt angezeigtes Abstimmungs-/Wahlergebnis (siehe
 * beamer_ergebnis_merken()) soll verschwinden, sobald die Folie wechselt,
 * nicht unter jedem folgenden Beamerbild weiter stehen bleiben.
 *
 * $zeige: der rohe "zeige"-Wert (siehe beamer_zeige_verarbeiten()), unter
 * dem dieser Inhalt aufgerufen wurde (z. B. "top:12", "go:3", "A1") - wird
 * nur für die Bedienoberfläche gespeichert (tab_beamersteuerung.php:
 * welches Akkordion offen bleiben soll, welcher Link fett erscheint), hat
 * sonst keine Wirkung. null, wenn der Aufrufer keinen solchen Wert hat
 * (z. B. BEAMs eigene Inhalte-Verwaltung, die ihre eigene, auf diese
 * Oberfläche nicht zutreffende Positions-Nummerierung nutzt).
 */
function beamer_inhalt_setzen(PDO $pdo, int $jahr, string $html, bool $logo_zeigen = false, ?int $wahlgangId = null, ?string $zeige = null): void {
    mvtexte_spalten_sicherstellen($pdo);
    $stmt = $pdo->prepare('UPDATE mvtexte SET beamer = ?, beamer_logo = ?, aktueller_wahlgang_id = ?, ergebnis_round_id = NULL, akklamation = 0, beamer_zeige_aktuell = ? WHERE jahr = ?');
    $stmt->execute([$html, $logo_zeigen ? 1 : 0, $wahlgangId, $zeige, $jahr]);
    beamer_protokoll_schreiben($jahr, $html);
}

/**
 * Trennt den eigentlichen Text optisch vom umgebenden HTML (fuegt an jeder
 * Tag->Text- bzw. Text->Tag-Grenze einen Zeilenumbruch ein, z. B.
 * "<p ...>Text</p>" wird zu "<p ...>\nText\n</p>") - fuer die Textarea in
 * "Aktuelles Bild bearbeiten" (siehe tab_beamersteuerung.php/beam/
 * steuerung.php), damit der Text beim Bearbeiten nicht im Markup
 * "versteckt" ist. Unmittelbar aufeinanderfolgende Tags OHNE Text
 * dazwischen (z. B. "<br><br>") bleiben dagegen auf einer Zeile zusammen -
 * nur echter Text bekommt eine eigene Zeile, nicht jedes einzelne Tag.
 * Rein fuer die Anzeige - die Umkehrung passiert beim Speichern in
 * beamer_html_normalisieren().
 */
function beamer_html_lesbar_machen(string $html): string {
    $html = preg_replace('/>(?!<)\s*/', ">\n", $html);
    $html = preg_replace('/\s*(?<!>)</', "\n<", $html);
    return trim($html);
}

/**
 * Entfernt nur die an einer Tag-Grenze (direkt nach '>' oder direkt vor
 * '<') eingefuegten Zeilenumbrueche wieder (Umkehrung von
 * beamer_html_lesbar_machen()), bevor das bearbeitete HTML gespeichert wird
 * (siehe process_beamersteuerung.php/beam/process_steuerung.php, Aktion
 * beamer_bild_bearbeiten). Echte, mitten im Text eingefuegte Zeilenumbrueche
 * (z. B. bei einer Folie mit white-space:pre-line) bleiben erhalten, weil
 * sie keine Tag-Grenze beruehren - der Rundweg format -> normalisieren
 * ergibt deshalb bei einer unveraenderten Übernahme wieder exakt das
 * Ausgangs-HTML.
 */
function beamer_html_normalisieren(string $html): string {
    $html = preg_replace('/>[ \t\r\n]+/', '>', $html);
    $html = preg_replace('/[ \t\r\n]+</', '<', $html);
    return trim($html);
}

/**
 * Merkt sich, dass das Ergebnis dieser Runde unter dem aktuellen Beamerbild
 * gezeigt werden darf (siehe beamerbild.php) - aufgerufen direkt nach einer
 * erfolgreichen Auswertung (vote_round_auswerten()/wahl_round_auswerten()).
 * Ein anschließender Folienwechsel löscht das wieder (siehe
 * beamer_inhalt_setzen()), damit ein altes Ergebnis nicht unter einem
 * späteren Antrag/TOP weiter angezeigt bleibt.
 */
function beamer_ergebnis_merken(PDO $pdo, int $jahr, int $roundId): void {
    mvtexte_spalten_sicherstellen($pdo);
    $pdo->prepare('UPDATE mvtexte SET ergebnis_round_id = ? WHERE jahr = ?')->execute([$roundId, $jahr]);
}

/**
 * Die ausgewertete Runde, deren Ergebnis noch zur aktuell gezeigten Folie
 * gehört (siehe beamer_ergebnis_merken()), oder null, wenn seitdem die
 * Folie gewechselt hat oder noch nie ausgewertet wurde. Gemeinsam genutzt
 * von beamerbild.php UND tab_beamersteuerung.php, damit beide garantiert
 * dasselbe zeigen (oder eben beide nichts, sobald die Folie gewechselt
 * hat) - genau wie vote_ergebnis_zeilen() schon die Formatierung an einer
 * Stelle buendelt.
 */
function beamer_aktuelles_ergebnis(PDO $pdo, array $mvtexte): ?array {
    if (empty($mvtexte['ergebnis_round_id'])) {
        return null;
    }
    $round = vote_round_get($pdo, (int)$mvtexte['ergebnis_round_id']);
    return ($round && $round['status'] === 'ausgewertet') ? $round : null;
}

/**
 * Markiert den aktuell gezeigten Inhalt als per Akklamation beschlossen -
 * fuer Themen, die keine foermliche Abstimmung brauchen (z. B. eine
 * einstimmige/unwidersprochene Zustimmung per Handzeichen, etwa bei der
 * Wahl der Versammlungsleitung). Erscheint anstelle eines
 * Abstimmungsergebnisses unter dem Beamerbild (siehe
 * beamer_vorschau_render()) und wird, wie ein echtes Ergebnis, im
 * Rohprotokoll vermerkt (siehe beamer_protokoll_schreiben()). Ein
 * anschließender Folienwechsel löscht die Markierung wieder (siehe
 * beamer_inhalt_setzen()), genau wie bei einem echten Abstimmungsergebnis.
 */
function beamer_akklamation_setzen(PDO $pdo, int $jahr): void {
    mvtexte_spalten_sicherstellen($pdo);
    $pdo->prepare('UPDATE mvtexte SET akklamation = 1, ergebnis_round_id = NULL WHERE jahr = ?')->execute([$jahr]);
    beamer_protokoll_schreiben($jahr, 'Zustimmung per Akklamation');
}

/**
 * Wertet eine bereits geschlossene Abstimmungs-/Wahlrunde aus, schreibt den
 * Logeintrag und merkt sich das Ergebnis für die Beamer-Anzeige (siehe
 * beamer_ergebnis_merken()) - Wahl oder einfache Ja/Nein/Enthaltung je
 * nachdem, ob die Runde eine wahlgang_id trägt. $automatisch: true, wenn
 * dies durch Timer-Ablauf statt durch einen Klick ausgelöst wurde (siehe
 * beamer_abstimmung_timer_abgelaufen_pruefen()) - wirkt sich nur auf den
 * Log-Text aus. Gibt [Ergebnistext, Fehlertext] zurück (einer davon immer
 * '').
 */
function beamer_runde_ergebnis_auswerten(PDO $pdo, int $jahr, ?array $mitglied, int $roundId, bool $automatisch = false): array {
    $round_vorher = vote_round_get($pdo, $roundId);
    $suffix = $automatisch ? ' (automatisch, Timer abgelaufen)' : '';
    if (!empty($round_vorher['wahlgang_id'])) {
        [$ergebnis, $fehler] = wahl_round_auswerten($pdo, $roundId);
        if ($fehler) {
            return ['', $fehler];
        }
        $wahlgang = get_wahlgang($pdo, (int)$round_vorher['wahlgang_id']);
        if ($wahlgang && $wahlgang['modus'] === 'P') {
            $text = 'Ergebnis ermittelt (' . count($ergebnis) . ' Variante(n) ausgezählt).';
        } else {
            $gewaehlte = implode(', ', array_column(array_filter($ergebnis, fn($z) => $z['gewaehlt']), 'kandidat_name'));
            $gleichstand = implode(', ', array_column(array_filter($ergebnis, fn($z) => !empty($z['stimmengleichheit'])), 'kandidat_name'));
            $text = 'gewählt: ' . ($gewaehlte !== '' ? $gewaehlte : '(niemand)') . ($gleichstand !== '' ? ' - Stimmengleichheit (Versammlung muss entscheiden): ' . $gleichstand : '') . '.';
        }
        mv_log($pdo, $jahr, $mitglied, 'Wahl ausgewertet' . $suffix, 'Runde ' . $roundId . ' (' . ($wahlgang['bezeichnung'] ?? '') . '): ' . $text);
        beamer_ergebnis_merken($pdo, $jahr, $roundId);
        // Taucht sonst nirgends im Rohprotokoll auf, da eine Auswertung
        // selbst keinen Folienwechsel (und damit keinen Aufruf von
        // beamer_inhalt_setzen()) ausloest - siehe beamer_protokoll_schreiben().
        beamer_protokoll_schreiben($jahr, 'Ergebnis ' . ($wahlgang['bezeichnung'] ?? 'Wahl') . ': ' . $text);
        return [$text, ''];
    }
    [$ergebnis, $fehler] = vote_round_auswerten($pdo, $roundId);
    if ($fehler) {
        return ['', $fehler];
    }
    $text = 'Ja ' . $ergebnis['ja'] . ', Nein ' . $ergebnis['nein'] . ', Enthaltung ' . $ergebnis['enthaltung'] . '.';
    mv_log($pdo, $jahr, $mitglied, 'Abstimmung ausgewertet' . $suffix, 'Runde ' . $roundId . ': ' . $text);
    beamer_ergebnis_merken($pdo, $jahr, $roundId);
    beamer_protokoll_schreiben($jahr, 'Abstimmungsergebnis: ' . $text);
    return [$text, ''];
}

/**
 * Beendet eine laufende Abstimmungs-/Wahlrunde und wertet sie direkt im
 * Anschluss aus - kein zweiter Klick mehr nötig (vorher musste nach
 * "Beenden" noch extra "Auswerten" geklickt werden). Schlägt die
 * automatische Auswertung ausnahmsweise fehl, bleibt die Runde
 * "geschlossen" stehen und der Fallback-Button in tab_beamersteuerung.php
 * ("Auswerten", ruft beamer_runde_ergebnis_auswerten() direkt auf) bleibt
 * sichtbar. Gibt [Erfolgstext, Fehlertext] zurück (einer davon immer '').
 */
function beamer_runde_beenden_und_auswerten(PDO $pdo, int $jahr, ?array $mitglied, int $roundId, bool $automatisch = false): array {
    $round_vorher = vote_round_get($pdo, $roundId);
    $fehler = vote_round_beenden($pdo, $roundId);
    if ($fehler) {
        return ['', $fehler];
    }
    $ist_wahl = !empty($round_vorher['wahlgang_id']);
    $anzahl = $ist_wahl ? wahl_round_zaehler($pdo, $roundId) : vote_round_zaehler($pdo, $roundId);
    $einheit = $ist_wahl ? 'Teilnehmer:in(nen)' : 'Stimme(n)';
    $suffix = $automatisch ? ' (automatisch, Timer abgelaufen)' : '';
    mv_log($pdo, $jahr, $mitglied, ($ist_wahl ? 'Wahl' : 'Abstimmung') . ' beendet' . $suffix, 'Runde ' . $roundId . ', ' . $anzahl . ' ' . $einheit);
    [$text, $auswertungFehler] = beamer_runde_ergebnis_auswerten($pdo, $jahr, $mitglied, $roundId, $automatisch);
    if ($auswertungFehler !== '') {
        return [($ist_wahl ? 'Wahl' : 'Abstimmung') . ' beendet (' . $anzahl . ' ' . $einheit . '). Automatische Auswertung fehlgeschlagen: ' . $auswertungFehler . ' Bitte erneut auswerten.', ''];
    }
    return [($ist_wahl ? 'Wahl' : 'Abstimmung') . ' beendet und ausgewertet (' . $anzahl . ' ' . $einheit . '): ' . $text, ''];
}

/**
 * Startet den eigenen Timer der Abstimmungsmaschine (getrennt vom
 * allgemeinen Beamer-Timer, siehe beamer_timer_starten() - der dient
 * Redezeiten/Pausen und hat mit Abstimmungen nichts mehr zu tun). Braucht
 * Minuten UND Sekunden, weil einfache Abstimmungen oft nur wenige Sekunden
 * brauchen. $autoBeenden: ob der Ablauf die laufende Abstimmung automatisch
 * beendet+auswertet (siehe beamer_abstimmung_timer_abgelaufen_pruefen())
 * oder nur angezeigt wird.
 */
function beamer_abstimmung_timer_starten(PDO $pdo, int $jahr, int $sekundenGesamt, bool $autoBeenden): void {
    mvtexte_spalten_sicherstellen($pdo);
    $ziel = (new DateTime())->modify("+{$sekundenGesamt} seconds")->format('H:i:s');
    $stmt = $pdo->prepare('UPDATE mvtexte SET abstimmung_timer = ?, abstimmung_timer_vorgabe_sek = ?, abstimmung_timer_auto_beenden = ? WHERE jahr = ?');
    $stmt->execute([$ziel, $sekundenGesamt, $autoBeenden ? 1 : 0, $jahr]);
}

/** Stoppt/löscht den Abstimmungs-Timer (die zuletzt genutzte Dauer/Checkbox-Einstellung bleibt als Vorgabe für das nächste Mal erhalten). Intern genutzt von beamer_abstimmung_timer_abgelaufen_pruefen() beim automatischen Beenden - es gibt bewusst keinen eigenen "Stoppen"-Button mehr dafür (erneutes Starten überschreibt den Timer ohnehin, und mit dem Ende der Runde verschwindet er automatisch aus der Anzeige). */
function beamer_abstimmung_timer_stoppen(PDO $pdo, int $jahr): void {
    mvtexte_spalten_sicherstellen($pdo);
    $pdo->prepare('UPDATE mvtexte SET abstimmung_timer = NULL WHERE jahr = ?')->execute([$jahr]);
}

/**
 * Hebt nur die Automatik auf (Checkbox "automatisch beenden"), ohne den
 * Timer selbst zu stoppen - Notfall-Knopf für den Fall technischer
 * Probleme während einer laufenden Abstimmung: der Timer läuft zur
 * Information weiter (Anzeige, Ablaufbalken), zwingt aber nicht mehr zum
 * automatischen Beenden; "Abstimmung beenden" bleibt weiterhin manuell möglich.
 */
function beamer_abstimmung_timer_auto_beenden_aufheben(PDO $pdo, int $jahr): void {
    mvtexte_spalten_sicherstellen($pdo);
    $pdo->prepare('UPDATE mvtexte SET abstimmung_timer_auto_beenden = 0 WHERE jahr = ?')->execute([$jahr]);
}

/**
 * Beendet und wertet die laufende Abstimmung automatisch aus, sobald der
 * eigene Abstimmungs-Timer abgelaufen ist UND die Checkbox "automatisch
 * beenden" dafür gesetzt war. Wird bei jeder Status-Abfrage aufgerufen
 * (abstimmung_status.php, von der Beamersteuerung alle paar Sekunden
 * gepollt, UND beamerbild.php, das sich per Meta-Refresh noch häufiger neu
 * lädt) - das erspart einen eigenen Cronjob/Hintergrundprozess. Löscht den
 * Timer dabei gleich mit (sonst stünde er als "Zeitüberschreitung" weiter
 * auf dem - durch das Beenden ohnehin verschwindenden - Beamer-Banner, was
 * nach einem automatischen Beenden nur verwirrt). Mitglied bewusst null
 * (System-Aktion, nicht einer bestimmten Person zuzuordnen - wessen
 * Bildschirm zufällig zuerst pollt, ist Zufall).
 */
function beamer_abstimmung_timer_abgelaufen_pruefen(PDO $pdo, int $jahr): void {
    $mvtexte = get_mvtexte($pdo, $jahr);
    $timerZiel = $mvtexte['abstimmung_timer'] ?? null;
    if (!$timerZiel || strtotime($timerZiel) > time()) {
        return;
    }
    if ((int)($mvtexte['abstimmung_timer_auto_beenden'] ?? 0) !== 1) {
        return;
    }
    $round = vote_round_aktuell($pdo, $jahr);
    if (!$round) {
        return;
    }
    beamer_abstimmung_timer_stoppen($pdo, $jahr);
    beamer_runde_beenden_und_auswerten($pdo, $jahr, null, (int)$round['round_id'], true);
}

/**
 * Alle Daten fuer eine Beamerbild-Anzeige an einer Stelle zusammengetragen -
 * gemeinsam genutzt von beamerbild.php UND der kleinen Live-Vorschau auf
 * der Beamersteuerung (tab_beamersteuerung.php, siehe beamer_vorschau_render()
 * und beamer_vorschau_status.php), damit beide niemals voneinander abweichen
 * koennen (gleiches Prinzip wie beamer_aktuelles_ergebnis()). Prueft dabei
 * auch gleich den Ablauf des Abstimmungs-Timers (siehe
 * beamer_abstimmung_timer_abgelaufen_pruefen()).
 *
 * Zwei getrennte Timer: der allgemeine ("timer_rest", z. B. fuer Redezeiten/
 * Pausen - rein informativ, beendet nichts automatisch) und der eigene
 * Abstimmungs-Timer ("vote_timer_rest", siehe beamer_abstimmung_timer_starten()) -
 * letzterer nur relevant/sichtbar, waehrend eine Abstimmung/Wahl laeuft.
 * Laeuft eine Abstimmung, ist aber kein Abstimmungs-Timer gesetzt, wird
 * stattdessen eine seit Rundenbeginn hochzaehlende Stoppuhr ermittelt
 * (vote_timer_elapsed) - auf dem Beamerbild soll waehrend jeder Abstimmung
 * immer ein Timer laufen; er verschwindet automatisch, sobald die Runde
 * endet (vote_round dann null).
 */
function beamer_vorschau_daten(PDO $pdo, int $jahr): array {
    beamer_abstimmung_timer_abgelaufen_pruefen($pdo, $jahr);

    $mvtexte = get_mvtexte($pdo, $jahr);
    $config = get_config($pdo);
    $logo_pfad = trim($config['logo_pfad'] ?? '');

    $vote_round = vote_round_aktuell($pdo, $jahr);
    $vote_round_ist_wahl = $vote_round && !empty($vote_round['wahlgang_id']);
    $vote_zaehler = $vote_round ? ($vote_round_ist_wahl ? wahl_round_zaehler($pdo, (int)$vote_round['round_id']) : vote_round_zaehler($pdo, (int)$vote_round['round_id'])) : 0;
    // Ergebnis nur zeigen, solange seit der Auswertung kein Folienwechsel
    // stattgefunden hat (siehe beamer_aktuelles_ergebnis()) - sonst bliebe
    // ein altes Ergebnis unter einem spaeter gezeigten Antrag/TOP
    // faelschlich weiter stehen.
    $vote_ergebnis_round = $vote_round ? null : beamer_aktuelles_ergebnis($pdo, $mvtexte);
    $vote_ergebnis_ist_wahl = $vote_ergebnis_round && !empty($vote_ergebnis_round['wahlgang_id']);
    $vote_ergebnis = ($vote_ergebnis_round && !$vote_ergebnis_ist_wahl) ? vote_round_gespeichertes_ergebnis($vote_ergebnis_round) : null;
    $wahl_ergebnis_zeilen = null;
    $letzter_wahlgang = null;
    if ($vote_ergebnis_round && $vote_ergebnis_ist_wahl) {
        $letzter_wahlgang = get_wahlgang($pdo, (int)$vote_ergebnis_round['wahlgang_id']);
        if ($letzter_wahlgang) {
            $wahl_ergebnis_zeilen = wahl_ergebnis_zeilen(wahl_round_gespeichertes_ergebnis($pdo, (int)$vote_ergebnis_round['round_id']), $letzter_wahlgang['modus']);
        }
    }
    // Wie beim Abstimmungsergebnis: nur zeigen, solange seit dem Setzen kein
    // Folienwechsel stattgefunden hat (siehe beamer_akklamation_setzen()).
    $akklamation = !$vote_round && !empty($mvtexte['akklamation']);

    $uhr_an = ($mvtexte['uhr'] ?? 'n') === 'j';

    // Allgemeiner Timer (Redezeit/Pause) - rein informativ, ohne jeden Bezug zur Abstimmungsmaschine.
    $timer_ziel = $mvtexte['timer'] ?? null;
    $timer_rest = $timer_ziel ? (strtotime($timer_ziel) - time()) : null;

    // Eigener Abstimmungs-Timer - nur relevant, waehrend eine Runde laeuft.
    $vote_timer_ziel = $mvtexte['abstimmung_timer'] ?? null;
    $vote_timer_auto_beenden = (int)($mvtexte['abstimmung_timer_auto_beenden'] ?? 0) === 1;
    // Wird die Automatik ueber den roten Abbruch-Button aufgehoben (siehe
    // beamer_abstimmung_timer_auto_beenden_aufheben()), blendet das sofort
    // auch die Countdown-/Ablaufbalken-Anzeige aus (nicht nur die
    // Auto-Beenden-Wirkung) - ein weiterlaufender Countdown ohne Wirkung
    // wuerde sonst verwirren. Es bleibt dann bei der Stoppuhr wie ohne
    // gesetzten Timer.
    $vote_timer_aktiv = $vote_timer_ziel && $vote_timer_auto_beenden;
    $vote_timer_rest = ($vote_round && $vote_timer_aktiv) ? (strtotime($vote_timer_ziel) - time()) : null;
    $vote_timer_elapsed = ($vote_round && !$vote_timer_aktiv) ? max(0, time() - strtotime($vote_round['started_at'])) : null;
    $vote_timer_vorgabe_sek = isset($mvtexte['abstimmung_timer_vorgabe_sek']) && $mvtexte['abstimmung_timer_vorgabe_sek'] !== null ? (int)$mvtexte['abstimmung_timer_vorgabe_sek'] : null;
    $vote_timer_prozent = ($vote_timer_vorgabe_sek && $vote_timer_rest !== null)
        ? max(0, min(100, 100 * (1 - $vote_timer_rest / $vote_timer_vorgabe_sek)))
        : null;

    return [
        'mvtexte' => $mvtexte, 'config' => $config, 'logo_pfad' => $logo_pfad,
        'vote_round' => $vote_round, 'vote_round_ist_wahl' => $vote_round_ist_wahl, 'vote_zaehler' => $vote_zaehler,
        'vote_ergebnis' => $vote_ergebnis, 'wahl_ergebnis_zeilen' => $wahl_ergebnis_zeilen, 'letzter_wahlgang' => $letzter_wahlgang,
        'akklamation' => $akklamation,
        'uhr_an' => $uhr_an, 'timer_rest' => $timer_rest,
        'vote_timer_rest' => $vote_timer_rest, 'vote_timer_elapsed' => $vote_timer_elapsed, 'vote_timer_prozent' => $vote_timer_prozent,
    ];
}

/**
 * CSS-Regeln zu beamer_vorschau_render() - einmal pro Seite ausgeben
 * (siehe beamerbild.php und tab_beamersteuerung.php). Alles unter dem
 * Wurzel-Selektor .beamer-vorschau-flaeche, damit dieselben Regeln sowohl
 * fuer die echte Vollbild-Seite als auch - herunterskaliert - fuer die
 * kleine Live-Vorschau gelten, ohne Kollision mit dem Rest der jeweiligen
 * Seite. ".ecke" ist bewusst "position: absolute" (statt "fixed" wie
 * frueher direkt in beamerbild.php) - "fixed" wuerde in der kleinen,
 * herunterskalierten Vorschau am echten Browser-Viewport ausgerichtet und
 * dadurch riesig und an falscher Stelle erscheinen; ".beamer-vorschau-flaeche"
 * ist "position: relative" und deckt die volle Flaeche ab, sodass sich auf
 * der echten Vollbild-Seite optisch nichts aendert.
 */
function beamer_vorschau_css(): string {
    return <<<CSS
    .beamer-vorschau-flaeche { position: relative; min-height: 100%; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: white; }
    .beamer-vorschau-flaeche .kopf { background: #005596; color: white; padding: 12px 30px; display: flex; align-items: center; gap: 12px; }
    .beamer-vorschau-flaeche .kopf h1 { font-size: 20px; margin: 0; color: white; }
    .beamer-vorschau-flaeche .kopf .untertitel { font-size: 12px; opacity: .85; margin-top: 2px; }
    .beamer-vorschau-flaeche .inhalt { padding: 24px 40px; }
    .beamer-vorschau-flaeche .abstimmung-banner { position: relative; background: #2e7d32; color: white; padding: 10px 30px; font-size: 20px; display: flex; align-items: center; gap: 16px; overflow: hidden; }
    .beamer-vorschau-flaeche .abstimmung-banner .zaehler { font-size: 26px; font-weight: 600; }
    .beamer-vorschau-flaeche .abstimmung-banner .ablaufbalken { position: absolute; left: 0; top: 0; bottom: 0; background: rgba(255,255,255,.22); z-index: 0; transition: width 1s linear; }
    .beamer-vorschau-flaeche .abstimmung-banner > * { position: relative; z-index: 1; }
    .beamer-vorschau-flaeche .abstimmung-ergebnis { margin: 10px 40px 0; padding: 10px 0 0; border-top: 2px solid #eee; color: mediumblue; font-weight: 700; }
    .beamer-vorschau-flaeche .abstimmung-ergebnis .titel { font-size: 20px; margin-bottom: 6px; }
    .beamer-vorschau-flaeche .abstimmung-ergebnis .zeile { font-size: 38px; line-height: 1.3; }
    .beamer-vorschau-flaeche .ecke { position: absolute; right: 0; text-align: right; font-size: 26px; padding: 4px 14px; color: white; }
    .beamer-vorschau-flaeche .wlan-ecke { position: absolute; left: 10px; bottom: 10px; background: white; border: 2px solid #005596; border-radius: 8px; padding: 8px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,.25); }
    .beamer-vorschau-flaeche .wlan-ecke img { display: block; width: 110px; height: 110px; }
    .beamer-vorschau-flaeche .wlan-ecke .wlan-titel { font-size: 13px; font-weight: 700; color: #005596; margin-bottom: 4px; }
    .beamer-vorschau-flaeche .wlan-ecke .wlan-ssid { font-size: 11px; color: #333; margin-top: 4px; max-width: 110px; overflow-wrap: break-word; }
    CSS;
}

/**
 * Rendert aus den Daten von beamer_vorschau_daten() das eigentliche
 * Beamerbild (als HTML-Fragment, ohne umgebendes <html>/<body> - siehe
 * beamerbild.php fuer die Vollbild-Einbettung und beamer_vorschau_status.php
 * fuer die JSON-Variante der kleinen Live-Vorschau). $zeigeKopf: false auf
 * abstimmen.php - die hat ihren eigenen Kopf mit Vereinsname/MV-Bezeichnung
 * schon oberhalb stehen, sonst erschiene er doppelt. $zeigeUhr: false auf
 * abstimmen.php - die Uhrzeit auf der Leinwand ist fuer den Saal gedacht,
 * auf dem eigenen Smartphone nur unnoetige Ablenkung. Aus demselben Grund
 * steuert $zeigeUhr auch die WLAN-Ecke mit (siehe unten) - wer schon auf
 * abstimmen.php gelandet ist, ist bereits verbunden.
 */
/**
 * $titelOverride: ersetzt die VERA-eigene Titelzusammensetzung
 * (Vereinsname/MV-Bezeichnung/Jahr) durch einen fertigen Text - für BEAM
 * (siehe dokumentation/BEAM.md), das statt dessen die einstellbare
 * Titelleiste eines Projekts zeigt. Bleibt er null (VERA, Vorgabe), ändert
 * sich am bisherigen Verhalten nichts.
 * $wlanQrPfad: Pfad zu wlan_qr.php relativ zur aufrufenden Seite - 'wlan_qr.php'
 * (Vorgabe) passt für alle VERA-eigenen Aufrufer (liegen direkt in neu/,
 * wie wlan_qr.php selbst); BEAM-Aufrufer (liegen in neu/beam/) müssen
 * '../wlan_qr.php' übergeben.
 */
function beamer_vorschau_render(array $d, int $jahr, bool $zeigeKopf = true, bool $zeigeUhr = true, ?string $titelOverride = null, string $wlanQrPfad = 'wlan_qr.php'): string {
    $mvtexte = $d['mvtexte'];
    $config = $d['config'];
    $logo_pfad = $d['logo_pfad'];

    $flaecheStyle = '';
    if (!empty($mvtexte['beamer_logo']) && $logo_pfad !== '') {
        $flaecheStyle = " background-image:url('" . h($logo_pfad) . "'); background-position:center; background-repeat:no-repeat; background-size:40%;";
    }

    $html = '<div class="beamer-vorschau-flaeche" style="' . $flaecheStyle . '">';

    if ($zeigeUhr && trim($config['wlan_ssid'] ?? '') !== '') {
        // Dauerhaft sichtbar (nicht nur beim Folienwechsel) - wichtig fuer
        // Nachzuegler oder bei Verbindungsabbruch waehrend laengerer
        // Diskussionen. Eigener, von vote_pdf_karte() unabhaengiger
        // QR-Code (hier aus wlan_qr.php, dort direkt in die PDF
        // eingebettet), gleicher Inhalt (wlan_qr_inhalt()).
        $html .= '<div class="wlan-ecke"><div class="wlan-titel">📶 WLAN</div>'
            . '<img src="' . h($wlanQrPfad) . '" alt="WLAN-QR-Code">'
            . '<div class="wlan-ssid">' . h((string)$config['wlan_ssid']) . '</div></div>';
    }

    if ($zeigeKopf) {
        $html .= '<div class="kopf">';
        if ($logo_pfad !== '') {
            $html .= '<img src="' . h($logo_pfad) . '" alt="Logo" style="height:36px; max-width:110px; object-fit:contain;">';
        }
        $titel = $titelOverride ?? ($config['vereinsname'] . ' – ' . $config['mv_bezeichnung'] . ' ' . $jahr);
        $html .= '<div><h1>' . h($titel) . '</h1>';
        if ($titelOverride === null && (($mvtexte['mvdatum'] ?? '') !== '' || ($mvtexte['mvort'] ?? '') !== '')) {
            $html .= '<div class="untertitel">' . h($mvtexte['mvdatum'] ?? '') . ((($mvtexte['mvdatum'] ?? '') !== '' && ($mvtexte['mvort'] ?? '') !== '') ? ' · ' : '') . h($mvtexte['mvort'] ?? '') . '</div>';
        }
        $html .= '</div></div>';
    }

    if ($d['vote_round']) {
        // Zweite Zeile bewusst knapp und ungegendert ("X haben abgestimmt"
        // statt "Teilnehmer:in(nen)") - die Restzeit steht schon in der
        // Timer-Ecke oben rechts, muss hier nicht nochmal wiederholt werden.
        $html .= '<div class="abstimmung-banner">';
        if ($d['vote_timer_prozent'] !== null) {
            $html .= '<div class="ablaufbalken" style="width:' . $d['vote_timer_prozent'] . '%;"></div>';
        }
        $html .= '<span>🗳️ ' . ($d['vote_round_ist_wahl'] ? 'Wahl' : 'Abstimmung') . ' läuft: <span class="zaehler">' . $d['vote_zaehler'] . '</span> haben abgestimmt.</span>';
        $html .= '</div>';
    }

    // Ecken-Badges (Uhrzeit, allgemeiner Timer, Abstimmungs-Timer/Stoppuhr) -
    // werden gestapelt, je nachdem, welche gerade zutreffen.
    $eckeTop = 0;
    if ($d['uhr_an'] && $zeigeUhr) {
        $html .= '<div class="ecke" style="top:' . $eckeTop . 'px; background:#FAAA00;">' . h(date('H:i')) . ' Uhr</div>';
        $eckeTop += 46;
    }
    if ($d['timer_rest'] !== null) {
        $timerRest = $d['timer_rest'];
        $farbe = $timerRest < 0 ? '#d32f2f' : ($timerRest > 59 ? '#4caf50' : '#FAC828');
        $textfarbe = ($timerRest > 0 && $timerRest <= 59) ? '#333' : 'white';
        $minuten = (int)floor(abs($timerRest) / 60);
        $sekunden = abs($timerRest) % 60;
        $html .= '<div class="ecke" style="top:' . $eckeTop . 'px; background:' . $farbe . '; color:' . $textfarbe . ';">'
            . ($timerRest >= 0 ? 'Timer' : 'Zeitüberschreitung') . '<br>' . sprintf('%02d:%02d', $minuten, $sekunden) . '</div>';
        $eckeTop += 46;
    }
    if ($d['vote_timer_rest'] !== null) {
        $timerRest = $d['vote_timer_rest'];
        $farbe = $timerRest < 0 ? '#d32f2f' : ($timerRest > 59 ? '#4caf50' : '#FAC828');
        $textfarbe = ($timerRest > 0 && $timerRest <= 59) ? '#333' : 'white';
        $minuten = (int)floor(abs($timerRest) / 60);
        $sekunden = abs($timerRest) % 60;
        $html .= '<div class="ecke" style="top:' . $eckeTop . 'px; background:' . $farbe . '; color:' . $textfarbe . ';">'
            . ($timerRest >= 0 ? 'Abstimmung endet in' : 'Zeitüberschreitung') . '<br>' . sprintf('%02d:%02d', $minuten, $sekunden) . '</div>';
        $eckeTop += 46;
    } elseif ($d['vote_timer_elapsed'] !== null) {
        $minuten = (int)floor($d['vote_timer_elapsed'] / 60);
        $sekunden = $d['vote_timer_elapsed'] % 60;
        $html .= '<div class="ecke" style="top:' . $eckeTop . 'px; background:#005596; color:white;">Abstimmung läuft<br>' . sprintf('%02d:%02d', $minuten, $sekunden) . '</div>';
        $eckeTop += 46;
    }

    $html .= '<div class="inhalt">' . ($mvtexte['beamer'] ?? '') . '</div>';

    if ($d['vote_ergebnis']) {
        [$vote_zeile1, $vote_zeile2] = vote_ergebnis_zeilen($d['vote_ergebnis'], $config['enthaltung_zaehlt_mit'] === '1');
        $html .= '<div class="abstimmung-ergebnis"><div class="titel">Abstimmungsergebnis:</div><div class="zeile">' . h($vote_zeile1) . '</div><div class="zeile">' . h($vote_zeile2) . '</div></div>';
    } elseif ($d['wahl_ergebnis_zeilen']) {
        $html .= '<div class="abstimmung-ergebnis"><div class="titel">Wahlergebnis - ' . h($d['letzter_wahlgang']['bezeichnung']) . ':</div>';
        foreach ($d['wahl_ergebnis_zeilen'] as $zeile) {
            $html .= '<div class="zeile" style="font-size:22px;">' . h($zeile) . '</div>';
        }
        $html .= '</div>';
    } elseif (!empty($d['akklamation'])) {
        $html .= '<div class="abstimmung-ergebnis"><div class="zeile">Zustimmung per Akklamation</div></div>';
    }

    $html .= '</div>';
    return $html;
}

/** Ob für dieses Jahr überhaupt schon ein Rohprotokoll existiert (nicht leer). */
function beamer_protokoll_existiert(int $jahr): bool {
    $pfad = beamer_protokoll_pfad($jahr);
    return is_file($pfad) && filesize($pfad) > 0;
}

/** Die letzten $max Zeilen des Rohprotokolls, chronologisch (älteste zuerst, neueste zuletzt) - für die Vorschau in der Admin-Protokollseite (siehe tab_protokoll.php), genau wie die vollständige Datei (rohprotokoll.php). */
function beamer_protokoll_lesen(int $jahr, int $max = 100): array {
    $pfad = beamer_protokoll_pfad($jahr);
    if (!is_file($pfad)) {
        return [];
    }
    $zeilen = file($pfad, FILE_IGNORE_NEW_LINES) ?: [];
    return array_slice($zeilen, -$max);
}

/**
 * Startet den Beamer-Timer. mvtexte.timer speichert - genau wie im
 * Altsystem (mvifolien.php: date("H:i:s", $timer)) - nur die Zieluhrzeit
 * als reine Uhrzeit ("H:i:s"), kein vollstaendiges Datum: Die Spalte ist
 * dafuer in vielen Bestandsinstallationen zu schmal typisiert (TIME bzw.
 * CHAR(8)), ein volles Datetime fuehrt dort zu einem Datenbankfehler.
 * beamerbild.php liest den Wert wieder ueber strtotime(), das eine reine
 * Uhrzeit automatisch auf den heutigen Tag bezieht.
 */
function beamer_timer_starten(PDO $pdo, int $jahr, int $minuten): void {
    mvtexte_spalten_sicherstellen($pdo);
    $ziel = (new DateTime())->modify("+{$minuten} minutes")->format('H:i:s');
    $stmt = $pdo->prepare('UPDATE mvtexte SET timer = ?, vorgabe = ? WHERE jahr = ?');
    $stmt->execute([$ziel, $minuten, $jahr]);
}

function beamer_timer_stoppen(PDO $pdo, int $jahr): void {
    mvtexte_spalten_sicherstellen($pdo);
    $pdo->prepare('UPDATE mvtexte SET timer = NULL WHERE jahr = ?')->execute([$jahr]);
}

function beamer_uhr_setzen(PDO $pdo, int $jahr, bool $an): void {
    mvtexte_spalten_sicherstellen($pdo);
    $pdo->prepare('UPDATE mvtexte SET uhr = ? WHERE jahr = ?')->execute([$an ? 'j' : 'n', $jahr]);
}

/**
 * Rendert die "Editierbare Folie": freier, von der Regie eingegebener Text
 * fuer Mitteilungen, die keine der festen Folien abdeckt. Zeilenumbrueche
 * aus der Textarea bleiben erhalten (white-space:pre-line), kein Logo-
 * Hintergrund (anders als die festen Sonderfolien), damit auch laengere
 * Texte lesbar bleiben.
 */
function beamer_rendern_text(string $text): string {
    $farbe = BEAMER_FARBE;
    $groesse = beamer_schriftgroesse_optimieren($text);
    return '<p style="font-size:' . $groesse . 'px; color:' . $farbe . '; text-align:left; white-space:pre-line;">' . h($text) . '</p>';
}

/** Rendert eine der festen Sonderfolien (willkommen/pause/stimm/leer/unter). Alle mit Logo-Hintergrund. */
function beamer_rendern_spezial(string $art, string $mv_bezeichnung, string $pausebis = ''): ?string {
    $farbe = BEAMER_FARBE;
    switch ($art) {
        case 'willkommen':
            // Leeres $mv_bezeichnung (BEAM, siehe dokumentation/BEAM.md: dort
            // gibt es keine "Mitgliederversammlung", nur ein Projekt mit
            // einstellbarer Titelleiste) zeigt nur die verallgemeinerte
            // Begrüßung ohne "zur ..." - VERA übergibt hier immer einen
            // nicht-leeren Text (siehe beamer_zeige_verarbeiten()), Verhalten
            // bleibt dort unverändert.
            return $mv_bezeichnung !== ''
                ? '<p style="font-size:30px; color:' . $farbe . ';">Herzlich willkommen zur ' . h($mv_bezeichnung) . '</p>'
                : '<p style="font-size:40px; color:' . $farbe . ';">Herzlich willkommen</p>';
        case 'pause':
            $zusatz = $pausebis !== '' ? ' bis ' . h($pausebis) . ' Uhr' : '';
            return '<p style="font-size:70px; color:' . $farbe . ';">Pause' . $zusatz . '</p>';
        case 'stimm':
            return '<p style="font-size:50px; color:' . $farbe . ';">Bitte an der Abstimmung teilnehmen</p>';
        case 'unter':
            return '<p style="font-size:50px; color:' . $farbe . ';">Die Sitzung wurde unterbrochen</p>';
        case 'leer':
            return '';
        default:
            return null;
    }
}

/** Rendert die Tagesordnung (aus mvtop, nicht mehr hartkodiert wie im Altsystem). */
function beamer_rendern_tagesordnung(PDO $pdo, int $jahr): string {
    $tops = array_values(array_filter(get_tagesordnung($pdo, $jahr), fn($t) => !empty($t['aktiv'])));
    $farbe = BEAMER_FARBE;
    $html = '<p style="font-size:28px; color:' . $farbe . '; text-align:left;">Tagesordnung</p>';
    $html .= '<p style="font-size:24px; color:' . $farbe . '; text-align:left;">';
    foreach ($tops as $i => $top) {
        $html .= ($i + 1) . '&nbsp;&nbsp;' . h($top['titel']) . '<br>';
    }
    $html .= '</p>';
    return $html;
}

/** Rendert einen einzelnen Tagesordnungspunkt (mvtop.id). */
/**
 * Folie für den Aufruf eines TOP durch die Versammlungsleitung. Nutzt die im
 * Vorfeld vom Redaktionsteam hinterlegte TOP-Folie (siehe
 * top_inhalte_speichern()), falls vorhanden - sonst die automatisch erzeugte
 * schlichte Titel-Folie wie bisher.
 */
function beamer_rendern_top(PDO $pdo, int $jahr, int $top_id): ?string {
    foreach (get_tagesordnung($pdo, $jahr) as $i => $top) {
        if ((int)$top['id'] === $top_id) {
            $farbe = BEAMER_FARBE;
            $folie = trim((string)($top['top_folie'] ?? ''));
            if ($folie !== '') {
                return '<p style="font-size:36px; color:' . $farbe . '; text-align:left; white-space:pre-line;">' . h($folie) . '</p>';
            }
            return '<p style="font-size:36px; color:' . $farbe . ';"><br><br>Tagesordnungspunkt ' . ($i + 1) . ':<br><br>' . h($top['titel']) . '</p>';
        }
    }
    return null;
}

/** Folie mit dem vom Redaktionsteam im Vorfeld hinterlegten Beschlussentwurf eines TOP (siehe top_inhalte_speichern()). */
function beamer_rendern_beschlussentwurf(PDO $pdo, int $jahr, int $top_id): ?string {
    foreach (get_tagesordnung($pdo, $jahr) as $top) {
        if ((int)$top['id'] === $top_id) {
            $farbe = BEAMER_FARBE;
            $text = trim((string)($top['beschlussentwurf'] ?? ''));
            if ($text === '') {
                return '<p style="font-size:28px; color:' . $farbe . ';">Für diesen Tagesordnungspunkt liegt noch kein Beschlussentwurf vor.</p>';
            }
            return '<p style="font-size:30px; color:' . $farbe . '; text-align:left; white-space:pre-line;"><strong>Beschlussentwurf:</strong><br><br>' . h($text) . '</p>';
        }
    }
    return null;
}

/** Rendert die Ankündigung einer gemeinsamen Behandlung mehrerer Hauptanträge (siehe Gem-Gruppierung, antraege_functions.php). */
function beamer_rendern_gemeinsam(PDO $pdo, int $jahr, int $gem): ?string {
    $antraege = get_gem_gruppe($pdo, $jahr, $gem);
    if (!$antraege) {
        return null;
    }
    $farbe = BEAMER_FARBE;
    $html = '<p style="font-size:28px; color:' . $farbe . '; text-align:left;">Gemeinsame Beratung geplant</p>';
    foreach ($antraege as $a) {
        $sizetitel = mb_strlen($a['Titeltext'] ?? '') > 100 ? 22 : 26;
        $html .= '<p style="font-size:' . $sizetitel . 'px; color:' . $farbe . '; text-align:left;">';
        $html .= h($a['Nr'] . ': ' . ($a['Titeltext'] ?? ''));
        $html .= '<br><span style="font-size:16px;">(Antragsteller:in ' . h(antragsteller_name($a)) . ')</span></p>';
    }
    return $html;
}

/** Rendert die Liste der zulässigen GO-Anträge (siehe GO_ANTRAEGE, antraege_functions.php). */
function beamer_rendern_go_liste(): string {
    $farbe = BEAMER_FARBE;
    $html = '<p style="font-size:32px; color:' . $farbe . '; text-align:left;">Geschäftsordnungsanträge</p>';
    $html .= '<p style="font-size:22px; color:' . $farbe . '; text-align:left;">';
    foreach (GO_ANTRAEGE as $i => $go) {
        $html .= ($i + 1) . '&nbsp;&nbsp;' . h($go) . '<br>';
    }
    $html .= '</p>';
    return $html;
}

/** Rendert einen einzelnen GO-Antrag (1-basierter Index in GO_ANTRAEGE). */
function beamer_rendern_go_einzeln(int $nr): ?string {
    if ($nr < 1 || $nr > count(GO_ANTRAEGE)) {
        return null;
    }
    $farbe = BEAMER_FARBE;
    return '<p style="font-size:30px; color:' . $farbe . ';"><br><br>Geschäftsordnungsantrag: <br><br>' . h(GO_ANTRAEGE[$nr - 1]) . '</p>';
}

/**
 * Rendert einen Antrag (Haupt-, Änderungs- oder Verfahrensantrag, per Nr) -
 * mit automatisch optimierter Schriftgroesse fuer den Antragstext
 * (siehe beamer_schriftgroesse_optimieren()).
 */
function beamer_rendern_antrag(PDO $pdo, int $jahr, string $nr, bool $komplett = false): ?string {
    $tabelle = antrag_tabelle($jahr);
    $stmt = $pdo->prepare("SELECT * FROM `$tabelle` WHERE Nr = ?");
    $stmt->execute([$nr]);
    $antrag = $stmt->fetch();
    if (!$antrag) {
        return null;
    }

    $farbe = BEAMER_FARBE;
    // Archivfassungen aus der Übernahme eines Änderungsantrags (siehe
    // antraege_functions.php) sind per Nr-Suffix erkennbar: "o" =
    // Ursprungsantrag (Fassung bei Antragsschluss), "x" = der dabei final
    // übernommene Änderungsantrag. Beide haben Art=ANTRAG_ART_ARCHIVIERT und
    // erscheinen bewusst nur hier auf der Beamerliste, in keiner normalen Liste.
    $archiv_hinweis = null;
    if ((int)$antrag['Art'] === ANTRAG_ART_ARCHIVIERT) {
        if (str_ends_with($nr, 'o')) {
            $archiv_hinweis = 'Ursprungsantrag (Fassung bei Antragsschluss)';
        } elseif (str_ends_with($nr, 'x')) {
            $archiv_hinweis = 'Übernommener Änderungsantrag';
        }
    }
    $art_label = match ((int)$antrag['Art']) {
        ANTRAG_ART_AENDERUNG => 'Änderungsantrag ',
        ANTRAG_ART_VERFAHREN => 'Verfahrensantrag ',
        default => 'Antrag ',
    };
    $sizetitel = mb_strlen($antrag['Titeltext'] ?? '') > 100 ? 24 : 28;

    $html = '<p style="font-size:' . $sizetitel . 'px; color:' . $farbe . '; text-align:left; margin:0;">';
    $html .= h($art_label . $nr . ': ' . ($antrag['Titeltext'] ?? '')) . '</p>';

    if ($archiv_hinweis !== null) {
        $html .= '<p style="font-size:18px; color:red; text-align:left;">' . h($archiv_hinweis) . '</p>';
    }

    if ($antrag['Ast']) {
        $html .= '<p style="font-size:16px; color:' . $farbe . '; text-align:left;">(Antragsteller:in ' . h(antragsteller_name($antrag)) . ')</p>';
    }

    if (antrag_wurde_abgestimmt($antrag)) {
        $html .= '<div style="font-size:26px; background:#eee;">Ergebnis: ' . h($antrag['Ergebnis']);
        if ($antrag['Ja'] || $antrag['Nein'] || $antrag['Enthaltung']) {
            $html .= '&nbsp;&nbsp;&nbsp;(' . (int)$antrag['Ja'] . ' : ' . (int)$antrag['Nein'] . ' : ' . (int)$antrag['Enthaltung'] . ')';
        }
        $html .= '</div>';
    }

    $text = (string)($antrag['Text'] ?? '');
    $groesse = beamer_schriftgroesse_optimieren($text);
    // Naeherungsweises Zeichenbudget bei der kleinsten getesteten Schrift - jenseits davon lieber kuerzen als winzig anzeigen.
    $budget = (int)round((60 + (36 - $groesse) * 2) * (18 + (36 - $groesse)) * 1.3);

    $html .= '<p style="font-size:' . $groesse . 'px; color:black; text-align:left;">';
    // h_mit_basis_html() statt h(): Antragstexte enthalten haeufig von
    // Hand eingegebene "<br>" (siehe format_antragstext()) - mit reinem h()
    // wuerden diese escaped und erschienen als sichtbarer Text "<br>" statt
    // als Zeilenumbruch.
    if ($komplett || mb_strlen($text) <= $budget) {
        $html .= nl2br(h_mit_basis_html($text));
    } else {
        $html .= nl2br(h_mit_basis_html(mb_substr($text, 0, $budget))) . ' …';
        $html .= '<br><span style="font-size:16px; color:red;">Antragstext gekürzt - vollständiger Text im MV-Tool.</span>';
    }
    $html .= '</p>';

    return $html;
}

/**
 * Zentrale Weiche: setzt fuer einen "zeige"-Wert aus der Beamersteuerung
 * (siehe tab_beamersteuerung.php) die passende Folie. Gibt bei unbekanntem
 * Wert einen Fehlertext zurueck (Wert existiert nicht/wurde geloescht),
 * sonst null.
 */
function beamer_zeige_verarbeiten(PDO $pdo, int $jahr, string $zeige, string $pausebis = '', string $freitext = ''): ?string {
    if (in_array($zeige, ['willkommen', 'pause', 'stimm', 'unter', 'leer'], true)) {
        $mv_bezeichnung = get_config($pdo)['mv_bezeichnung'] ?? 'Mitgliederversammlung';
        beamer_inhalt_setzen($pdo, $jahr, beamer_rendern_spezial($zeige, $mv_bezeichnung, $pausebis), true, null, $zeige);
        return null;
    }
    if ($zeige === 'text') {
        if (trim($freitext) === '') {
            return 'Bitte einen Text eingeben.';
        }
        beamer_inhalt_setzen($pdo, $jahr, beamer_rendern_text($freitext), false, null, $zeige);
        return null;
    }
    if ($zeige === 'tagesordnung') {
        beamer_inhalt_setzen($pdo, $jahr, beamer_rendern_tagesordnung($pdo, $jahr), false, null, $zeige);
        return null;
    }
    if ($zeige === 'go') {
        beamer_inhalt_setzen($pdo, $jahr, beamer_rendern_go_liste(), false, null, $zeige);
        return null;
    }
    if (str_starts_with($zeige, 'go:')) {
        $html = beamer_rendern_go_einzeln((int)substr($zeige, 3));
        if ($html === null) {
            return 'GO-Antrag nicht gefunden.';
        }
        beamer_inhalt_setzen($pdo, $jahr, $html, false, null, $zeige);
        return null;
    }
    if (str_starts_with($zeige, 'top:')) {
        $html = beamer_rendern_top($pdo, $jahr, (int)substr($zeige, 4));
        if ($html === null) {
            return 'Tagesordnungspunkt nicht gefunden.';
        }
        beamer_inhalt_setzen($pdo, $jahr, $html, false, null, $zeige);
        return null;
    }
    if (str_starts_with($zeige, 'beschluss:')) {
        $html = beamer_rendern_beschlussentwurf($pdo, $jahr, (int)substr($zeige, 10));
        if ($html === null) {
            return 'Tagesordnungspunkt nicht gefunden.';
        }
        beamer_inhalt_setzen($pdo, $jahr, $html, false, null, $zeige);
        return null;
    }
    if (str_starts_with($zeige, 'wahl:')) {
        $wahlgangId = (int)substr($zeige, 5);
        $html = wahl_rendern_beamer($pdo, $wahlgangId);
        if ($html === null) {
            return 'Wahlgang nicht gefunden.';
        }
        beamer_inhalt_setzen($pdo, $jahr, $html, false, $wahlgangId, $zeige);
        return null;
    }
    if (str_starts_with($zeige, 'gem:')) {
        $html = beamer_rendern_gemeinsam($pdo, $jahr, (int)substr($zeige, 4));
        if ($html === null) {
            return 'Diese Gem-Gruppe ist leer oder existiert nicht (mehr).';
        }
        beamer_inhalt_setzen($pdo, $jahr, $html, false, null, $zeige);
        return null;
    }

    // Sonst: Antrags-Nummer.
    $komplett = str_ends_with($zeige, ':komplett');
    $nr = $komplett ? substr($zeige, 0, -9) : $zeige;
    $html = beamer_rendern_antrag($pdo, $jahr, $nr, $komplett);
    if ($html === null) {
        return 'Antrag ' . $nr . ' nicht gefunden.';
    }
    beamer_inhalt_setzen($pdo, $jahr, $html, false, null, $zeige);
    return null;
}
