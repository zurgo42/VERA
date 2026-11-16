<?php
/**
 * tab_beamersteuerung.php - Eigene Seite für Beamer- und Abstimmungs-
 * steuerung (Ablösung von mvifolien.php), früher Teil der Kompaktliste
 * (siehe tab_kompaktliste.php). Mit der Erweiterung auf Beamerbilder für
 * TOPs, Beschlussentwürfe und Wahlgänge sowie die Abstimmungsmaschine
 * selbst (siehe dokumentation/Abstimmungsmaschine.md) wurde die
 * Beamersteuerung in der für alle Mitglieder lesbaren Kompaktliste zu
 * umfangreich - sie steht deshalb jetzt auf einer eigenen Seite, nur für
 * Technik/Beamer-Team und Redaktionsteam (siehe
 * ist_beamersteuerung_berechtigt() - enger als das bisherige
 * ist_funktionsteam(), das auch Versammlungsleitung und Protokollführung
 * einschloss). Die Markierung von Anträgen ohne ausreichende
 * Unterstützung bleibt dagegen in der Kompaktliste - das ist eine
 * inhaltliche Entscheidung der Antragsliste, keine Beamer-Bedienung.
 */

require_once __DIR__ . '/beamer_functions.php';
require_once __DIR__ . '/abstimmung_functions.php';

if (!empty($_SESSION['admin_erfolg'])) {
    echo '<div class="info-box">' . h($_SESSION['admin_erfolg']) . '</div>';
    unset($_SESSION['admin_erfolg']);
}
if (!empty($_SESSION['admin_fehler'])) {
    echo '<div class="info-box" style="border-left-color:var(--danger); color:var(--danger);">' . h($_SESSION['admin_fehler']) . '</div>';
    unset($_SESSION['admin_fehler']);
}
?>
<h2>📽️ Beamersteuerung</h2>

<div class="top-item">
    <p style="font-size:13px; color:var(--text-secondary);">
        Das Beamerbild läuft auf einer separaten Seite, die sich laufend aktualisiert:
        <a href="beamerbild.php?jahr=<?= h((string)$jahr) ?>" target="beamer" class="btn btn-secondary" style="padding:4px 10px; font-size:12px;">🖥️ Beamer-Fenster öffnen</a>
    </p>

    <!-- Gemeinsames verstecktes Formular fuer alle "Folie zeigen"-Buttons unten. -->
    <form method="post" id="beamerform">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="beamer_zeigen">
        <input type="hidden" name="zeige" id="beamerform_zeige" value="">
        <input type="hidden" name="pausebis" id="beamerform_pausebis" value="">
    </form>
    <script>
    function beamerZeigen(wert, pausebisId) {
        document.getElementById('beamerform_zeige').value = wert;
        document.getElementById('beamerform_pausebis').value = pausebisId ? (document.getElementById(pausebisId).value || '') : '';
        document.getElementById('beamerform').submit();
    }
    </script>

    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px;">
        <button type="button" class="btn btn-secondary" style="padding:6px 10px; font-size:12.5px;" onclick="beamerZeigen('willkommen')">Willkommen</button>
        <span>
            <button type="button" class="btn btn-secondary" style="padding:6px 10px; font-size:12.5px;" onclick="beamerZeigen('pause', 'pausebis_eingabe')">Pause</button>
            <input type="text" id="pausebis_eingabe" placeholder="bis HH:MM" size="6" style="padding:5px;">
        </span>
        <button type="button" class="btn btn-secondary" style="padding:6px 10px; font-size:12.5px;" onclick="beamerZeigen('stimm')">Stimmabgabe</button>
        <button type="button" class="btn btn-secondary" style="padding:6px 10px; font-size:12.5px;" onclick="beamerZeigen('unter')">Unterbrochen</button>
        <button type="button" class="btn btn-secondary" style="padding:6px 10px; font-size:12.5px;" onclick="beamerZeigen('leer')">Leere Folie</button>
        <button type="button" class="btn btn-secondary" style="padding:6px 10px; font-size:12.5px;" onclick="beamerZeigen('tagesordnung')">Tagesordnung</button>
        <button type="button" class="btn btn-secondary" style="padding:6px 10px; font-size:12.5px;" onclick="beamerZeigen('go')">GO-Anträge (Liste)</button>
    </div>

    <style>
    /* Zweispaltiges Layout ab den Standardfolien-Buttons: links Eingabe
       (Freitext-Folie + Abstimmungsfrage), rechts eine kleine, dauerhaft
       sichtbare Live-Vorschau des Beamerbilds (sticky - bleibt auch beim
       Scrollen durch eine lange Antragsliste stehen, siehe .bs-preview).
       Auf dem Smartphone (schmale Spalte) rutscht die Vorschau stattdessen
       direkt unter das Eingabefeld, noch vor die langen Listen. */
    .bs-layout {
        display: grid;
        grid-template-columns: 1fr 520px;
        grid-template-areas: "freitext preview" "lists preview";
        gap: 16px;
        align-items: start;
    }
    .bs-preview { grid-area: preview; position: sticky; top: 10px; }
    @media (max-width: 760px) {
        .bs-layout { grid-template-columns: 1fr; grid-template-areas: "freitext" "preview" "lists"; }
        .bs-preview { position: static; }
    }
<?= beamer_vorschau_css() ?>
    </style>
    <div class="bs-layout">
    <div style="grid-area: freitext;">
    <form method="post" style="margin-bottom:12px;">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="beamer_zeigen">
        <input type="hidden" name="zeige" value="text">
        <label style="display:block; font-size:12.5px; color:var(--text-secondary); margin-bottom:4px;">Editierbare Folie - eigener Text für den Beamer:</label>
        <textarea name="freitext" rows="3" style="width:100%; max-width:420px; padding:6px 8px; font-family:inherit; font-size:13px;" placeholder="Eigenen Text eingeben …"></textarea><br>
        <button type="submit" class="btn btn-secondary" style="margin-top:6px; padding:6px 10px; font-size:12.5px;">📽️ Anzeigen</button>
    </form>

    <!-- Abstimmungsfrage direkt aus der Beamersteuerung vorbereiten (siehe
         dokumentation/Abstimmungsmaschine.md) - "Ja/Nein/Enthaltung" ist
         nur die bisherige Freitext-Folie (die Abstimmungsmaschine fragt
         dafuer ohnehin automatisch Ja/Nein/Enthaltung ab); "Auswahlfrage"
         und "Präferenz" legen dafuer einen eigenen Wahlgang an (Modus B
         bzw. P, siehe wahl_functions.php), der sich wie ein Wahlgang
         starten/beenden/auswerten laesst, aber auf dem Beamer als Frage
         mit "-"-Varianten statt als Kandidat:innenliste erscheint. -->
    <form method="post" id="abstimmungsvarianteform" style="margin-bottom:12px; padding-top:10px; border-top:1px dotted var(--border-color);">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="beamer_abstimmungsvariante">
        <label style="display:block; font-size:12.5px; color:var(--text-secondary); margin-bottom:4px;">Abstimmungsfrage vorbereiten und anzeigen:</label>
        <div style="display:flex; gap:14px; flex-wrap:wrap; font-size:12.5px; margin:4px 0 8px;">
            <label><input type="radio" name="typ" value="ja_nein" checked onchange="abstimmungsvarianteTypUmschalten()"> Ja/Nein/Enthaltung</label>
            <label><input type="radio" name="typ" value="auswahl" onchange="abstimmungsvarianteTypUmschalten()"> Auswahlfrage (eine Variante)</label>
            <label><input type="radio" name="typ" value="praeferenz" onchange="abstimmungsvarianteTypUmschalten()"> Präferenz (mehrere Varianten)</label>
        </div>
        <input type="text" name="frage" placeholder="Frage / Text …" style="width:100%; max-width:420px; padding:6px 8px; font-size:13px;"><br>
        <div id="abstimmungsvariante-varianten-wrap" style="display:none; margin-top:6px;">
            <label style="display:block; font-size:12.5px; color:var(--text-secondary); margin-bottom:4px;">Varianten (eine je Zeile, max. 15):</label>
            <textarea name="varianten" rows="4" style="width:100%; max-width:420px; padding:6px 8px; font-family:inherit; font-size:13px;" placeholder="Variante 1&#10;Variante 2&#10;…"></textarea>
        </div>
        <button type="submit" class="btn btn-secondary" style="margin-top:6px; padding:6px 10px; font-size:12.5px;">📽️ Anzeigen</button>
    </form>
    <script>
    function abstimmungsvarianteTypUmschalten() {
        var typ = document.querySelector('#abstimmungsvarianteform input[name="typ"]:checked').value;
        document.getElementById('abstimmungsvariante-varianten-wrap').style.display = (typ === 'ja_nein') ? 'none' : 'block';
    }
    </script>
    </div>

    <?php
    // Daten fuer Live-Vorschau UND Abstimmungsmaschine-Box (siehe unten) -
    // beamer_vorschau_daten() liefert beides in einem Zug (inkl. Timer-
    // Ablauf-Pruefung, siehe beamer_abstimmung_timer_abgelaufen_pruefen()).
    $bs_daten = beamer_vorschau_daten($pdo, $jahr);
    $vote_round = $bs_daten['vote_round'];
    $vote_wartend = $vote_round ? null : vote_round_wartet_auf_auswertung($pdo, $jahr);
    // Ergebnis nur zeigen, solange seit der Auswertung kein Folienwechsel
    // stattgefunden hat (siehe beamer_aktuelles_ergebnis()) - sonst stuende
    // hier ein Ergebnis, das auf dem Beamerbild laengst nicht mehr zu sehen
    // ist (siehe dort), was beim Vergleich beider Bildschirme irritiert.
    $vote_letztes = ($vote_round || $vote_wartend) ? null : beamer_aktuelles_ergebnis($pdo, $mvtexte);
    // Zeigt der Beamer aktuell einen Wahlgang (siehe beamer_inhalt_setzen()),
    // startet "Abstimmung starten" automatisch eine Wahl statt einer einfachen
    // Ja/Nein/Enthaltung-Abstimmung (Phase 6, siehe wahl_functions.php).
    $kompakt_aktueller_wahlgang = !empty($mvtexte['aktueller_wahlgang_id']) ? get_wahlgang($pdo, (int)$mvtexte['aktueller_wahlgang_id']) : null;
    // Vorbelegung des Timer-Formulars mit der zuletzt genutzten Dauer/Checkbox.
    $vote_timer_vorgabe_sek_gesamt = isset($mvtexte['abstimmung_timer_vorgabe_sek']) && $mvtexte['abstimmung_timer_vorgabe_sek'] !== null ? (int)$mvtexte['abstimmung_timer_vorgabe_sek'] : 20;
    $vote_timer_vorgabe_min = intdiv($vote_timer_vorgabe_sek_gesamt, 60);
    $vote_timer_vorgabe_restsek = $vote_timer_vorgabe_sek_gesamt % 60;
    $vote_timer_auto_beenden_an = (int)($mvtexte['abstimmung_timer_auto_beenden'] ?? 0) === 1;
    ?>
    <div class="bs-preview">
    <!-- Spontane Korrektur des GERADE projizierten Bilds waehrend der
         laufenden Sitzung - anders als die "Editierbare Folie" links (die
         immer eine neue, generische Freitextfolie erzeugt) wird hier der
         bestehende Inhalt direkt als HTML bearbeitet: Logo-Status,
         aktueller Wahlgang und der "zeige"-Kontext (siehe
         beamer_zeige_aktuell, tab_beamersteuerung.php oben) bleiben dabei
         erhalten (process_beamersteuerung.php, Aktion
         beamer_bild_bearbeiten) - nicht alles laesst sich im Vorfeld
         vorbereiten. -->
    <div style="border:1px solid var(--border-color); border-radius:8px; padding:12px; margin-bottom:12px;">
    <h3 style="margin:0 0 8px; font-size:15px;">✏️ Aktuelles Bild bearbeiten</h3>
    <p style="font-size:12px; color:var(--text-secondary); margin:0 0 8px;">Für spontane Korrekturen am gerade projizierten Bild - der Inhalt lässt sich hier direkt anpassen und erneut senden.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="beamer_bild_bearbeiten">
        <textarea name="html" rows="8" style="width:100%; padding:6px 8px; font-family:monospace; font-size:12px;"><?= h(beamer_html_lesbar_machen((string)($mvtexte['beamer'] ?? ''))) ?></textarea><br>
        <button type="submit" class="btn" style="margin-top:6px; padding:6px 12px; font-size:12.5px;">📽️ Übernehmen &amp; projizieren</button>
    </form>
    </div>

    <div style="border:1px solid var(--border-color); border-radius:8px; padding:12px;">
    <h3 style="margin:0 0 8px; font-size:15px;">🗳️ Abstimmungsmaschine</h3>

    <!-- Live-Vorschau des Beamerbilds - dasselbe HTML wie beamerbild.php
         (beamer_vorschau_render()), nur herunterskaliert, damit niemand
         eigens das Beamer-Fenster aufrufen muss, um zu sehen, was gerade
         gezeigt wird. Aktualisiert per innerHTML-Ersetzen, kein iframe
         (das flackert beim Neuladen). Deshalb unten in den Status-Zeilen
         auch keine zusaetzliche Rohtext-Anzeige mehr - das wuerde den
         Inhalt nur doppelt zeigen. -->
    <div style="width:480px; max-width:100%; aspect-ratio:16/9; overflow:hidden; border:1px solid var(--border-color); border-radius:6px;">
        <div id="beamer-vorschau-inner" style="width:960px; height:540px; transform:scale(.5); transform-origin:0 0; pointer-events:none;">
            <?= beamer_vorschau_render($bs_daten, $jahr) ?>
        </div>
    </div>
    <script>
    (function () {
        var inner = document.getElementById('beamer-vorschau-inner');
        function aktualisieren() {
            fetch('beamer_vorschau_status.php').then(function (r) { return r.json(); }).then(function (d) {
                if (d.html) { inner.innerHTML = d.html; }
            }).catch(function () {});
        }
        setInterval(aktualisieren, 3000);
    })();
    </script>

    <!-- Eigener Timer der Abstimmungsmaschine (getrennt vom allgemeinen
         Timer unten in der Listen-Spalte, der fuer Redezeiten/Pausen
         gedacht ist und keine Abstimmung mehr beendet). Minuten+Sekunden,
         weil kurze Abstimmungen auch mal nur 20 Sekunden brauchen. Kein
         Start-Button - ein eigener Button neben Checkbox/Feldern war
         verwirrend (was genau loest er aus?); stattdessen sendet sich das
         Formular wie die Uhrzeit-Checkbox unten selbst ab, sobald eines der
         drei Felder geaendert wird (jede Aenderung uebertraegt den
         vollstaendigen, dann gueltigen Stand). Kein separater "Stoppen"-
         Button - sobald die Runde endet, verschwindet die Anzeige ohnehin
         (siehe beamer_vorschau_render()). Der rote Abbruch-Button bei
         technischen Problemen steht weiter unten bei der laufenden
         Abstimmung. -->
    <div style="margin-top:10px; padding-top:10px; border-top:1px dotted var(--border-color);">
        <form method="post" id="abstimmung-timer-form" style="display:flex; gap:6px; align-items:center; flex-wrap:wrap; font-size:12.5px;">
            <?= csrf_field() ?>
            <input type="hidden" name="aktion" value="abstimmung_timer_start">
            Abstimmungs-Timer:
            <input type="number" min="0" name="minuten" value="<?= $vote_timer_vorgabe_min ?>" style="width:46px; padding:4px;" onchange="this.form.submit()"> Min
            <input type="number" min="0" max="59" name="sekunden" value="<?= $vote_timer_vorgabe_restsek ?>" style="width:46px; padding:4px;" onchange="this.form.submit()"> Sek
            <label><input type="checkbox" name="auto_beenden" <?= $vote_timer_auto_beenden_an ? 'checked' : '' ?> onchange="this.form.submit()"> automatisch beenden</label>
        </form>
    </div>

    <div style="margin-top:10px; padding-top:10px; border-top:1px dotted var(--border-color);">
        <?php if ($vote_round): $ist_wahl = !empty($vote_round['wahlgang_id']); ?>
            <p style="font-size:12.5px; color:var(--text-secondary);">
                <?= $ist_wahl ? 'Wahl' : 'Abstimmung' ?> läuft (gestartet <?= h(format_datum_zeit($vote_round['started_at'])) ?>) - Teilnehmer:innen stimmen gerade über ihre Stimmkarte ab.
            </p>
            <div id="vote-ablaufbalken-wrap" style="display:none; height:8px; background:var(--bg-secondary, #eee); border-radius:4px; margin:8px 0; overflow:hidden;">
                <div id="vote-ablaufbalken" style="height:100%; width:0%; background:#4caf50; transition:width 1s linear;"></div>
            </div>
            <p id="vote-timer-rest" style="display:none; font-size:12px; color:var(--text-secondary); margin:4px 0;"></p>
            <p style="font-size:24px; font-weight:600; margin:10px 0;" id="vote-zaehler">…</p>
            <p id="vote-warnung" style="display:none; color:var(--danger); font-size:12.5px;">⚠️ Die Antworten kommen merklich langsamer an als sonst - ggf. noch etwas Zeit lassen, bevor <?= $ist_wahl ? 'die Wahl' : 'die Abstimmung' ?> beendet wird.</p>
            <form method="post" style="display:inline-block; margin-right:8px;">
                <?= csrf_field() ?>
                <input type="hidden" name="aktion" value="abstimmung_beenden">
                <input type="hidden" name="round_id" value="<?= (int)$vote_round['round_id'] ?>">
                <button type="submit" class="btn" style="padding:6px 12px; font-size:12.5px;"><?= $ist_wahl ? 'Wahl' : 'Abstimmung' ?> beenden</button>
            </form>
            <?php if ($vote_timer_auto_beenden_an && $bs_daten['vote_timer_rest'] !== null): ?>
                <!-- Nur fuer den Fall technischer Probleme: beendet NICHT die
                     Abstimmung, sondern nur die Automatik - der Timer laeuft
                     zur Information weiter, zwingt aber nicht mehr zum Ende. -->
                <form method="post" style="display:inline-block;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aktion" value="abstimmung_timer_auto_beenden_aufheben">
                    <button type="submit" class="btn" style="padding:6px 12px; font-size:12.5px; background:var(--danger);">Diese Abstimmung jetzt nicht automatisch beenden</button>
                </form>
            <?php endif; ?>
            <script>
            (function () {
                var zaehler = document.getElementById('vote-zaehler');
                var warnung = document.getElementById('vote-warnung');
                var balkenWrap = document.getElementById('vote-ablaufbalken-wrap');
                var balken = document.getElementById('vote-ablaufbalken');
                var timerRest = document.getElementById('vote-timer-rest');
                function aktualisieren() {
                    fetch('abstimmung_status.php').then(function (r) { return r.json(); }).then(function (d) {
                        // Der Abstimmungs-Timer beendet (und wertet aus) die Abstimmung
                        // serverseitig automatisch, sofern "automatisch beenden" gesetzt
                        // war (siehe abstimmung_status.php) - ist sie inzwischen vorbei,
                        // zeigt ein Neuladen das Ergebnis.
                        if (!d.laeuft) { location.reload(); return; }
                        zaehler.textContent = d.anzahl + ' Teilnehmer:in(nen)';
                        warnung.style.display = d.auffaellig ? 'block' : 'none';
                        if (d.vote_timer_rest !== null && d.vote_timer_vorgabe_sek) {
                            var gesamt = d.vote_timer_vorgabe_sek;
                            var prozent = Math.max(0, Math.min(100, 100 * (1 - d.vote_timer_rest / gesamt)));
                            balkenWrap.style.display = 'block';
                            balken.style.width = prozent + '%';
                            balken.style.background = d.vote_timer_rest < 0 ? '#d32f2f' : (d.vote_timer_rest <= 59 ? '#FAC828' : '#4caf50');
                            var rest = Math.abs(d.vote_timer_rest);
                            var mm = Math.floor(rest / 60), ss = rest % 60;
                            timerRest.style.display = 'block';
                            timerRest.textContent = (d.vote_timer_rest >= 0 ? 'Abstimmungs-Timer - endet in ' : 'Zeit abgelaufen, seit ') + String(mm).padStart(2, '0') + ':' + String(ss).padStart(2, '0');
                        } else {
                            balkenWrap.style.display = 'none';
                            timerRest.style.display = 'none';
                        }
                    }).catch(function () {});
                }
                aktualisieren();
                setInterval(aktualisieren, 4000);
            })();
            </script>
        <?php elseif ($vote_wartend): $ist_wahl = !empty($vote_wartend['wahlgang_id']); ?>
            <p style="font-size:12.5px; color:var(--text-secondary);">
                <?= $ist_wahl ? 'Wahl' : 'Abstimmung' ?> beendet (<?= $ist_wahl ? wahl_round_zaehler($pdo, (int)$vote_wartend['round_id']) : vote_round_zaehler($pdo, (int)$vote_wartend['round_id']) ?> Teilnehmer:in(nen)) - jetzt auswerten, um das Ergebnis zu sehen und das Protokoll zu erzeugen.
            </p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="aktion" value="abstimmung_auswerten">
                <input type="hidden" name="round_id" value="<?= (int)$vote_wartend['round_id'] ?>">
                <button type="submit" class="btn" style="padding:6px 12px; font-size:12.5px;">Auswerten</button>
            </form>
        <?php else: ?>
            <p style="font-size:12.5px; color:var(--text-secondary);">
                <?php if ($kompakt_aktueller_wahlgang): ?>
                    Startet eine Wahl mit den gerade auf dem Beamer gezeigten Kandidat:innen - Teilnehmer:innen können danach sofort über ihre Stimmkarte abstimmen (Modus <?= h($kompakt_aktueller_wahlgang['modus']) ?>).
                <?php else: ?>
                    Startet eine Abstimmung mit dem aktuell auf dem Beamer gezeigten Inhalt als Fragetext - Teilnehmer:innen können danach sofort über ihre Stimmkarte mit Ja/Nein/Enthaltung abstimmen.
                <?php endif; ?>
            </p>
            <?php if ($vote_letztes): ?>
                <p style="font-size:11.5px; color:var(--text-secondary); margin-top:6px;">
                    Letztes Ergebnis (<?= h(format_datum_zeit($vote_letztes['closed_at'])) ?>) steht oben in der Vorschau.
                    <?php if (!empty($vote_letztes['csv_datei'])): ?>
                        <a href="abstimmung_csv.php?datei=<?= h(urlencode($vote_letztes['csv_datei'])) ?>">CSV-Protokoll herunterladen</a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <form method="post" style="display:inline-block; margin-right:8px;">
                <?= csrf_field() ?>
                <input type="hidden" name="aktion" value="abstimmung_starten">
                <button type="submit" class="btn" style="padding:6px 12px; font-size:12.5px;"><?= $kompakt_aktueller_wahlgang ? 'Wahl mit diesen Kandidat:innen starten' : 'Abstimmung mit diesem Inhalt starten' ?></button>
            </form>
            <!-- Nicht immer ist eine foermliche Abstimmung noetig - fuer
                 einstimmige/unwidersprochene Beschluesse (z. B. die Wahl der
                 Versammlungsleitung per Handzeichen) vermerkt dieser Button
                 "Zustimmung per Akklamation" statt eines Abstimmungsergebnisses
                 unter dem Beamerbild und im Rohprotokoll, ganz ohne
                 Stimmkarten-Abfrage (siehe beamer_akklamation_setzen()). -->
            <form method="post" style="display:inline-block;">
                <?= csrf_field() ?>
                <input type="hidden" name="aktion" value="akklamation_setzen">
                <button type="submit" class="btn btn-secondary" style="padding:6px 12px; font-size:12.5px;">🤝 Zustimmung per Akklamation</button>
            </form>
        <?php endif; ?>
    </div>
    </div>
    </div>

    <div style="grid-area: lists;">
    <?php
    // Welcher "zeige"-Wert gerade auf dem Beamer steht (siehe
    // beamer_inhalt_setzen()) - steuert, welches Akkordion offen bleibt
    // (nicht bei jedem Klick wieder zuklappt) und welcher Link fett
    // erscheint, damit der Bedienende auf einen Blick sieht, wo er/sie
    // gerade ist.
    $bs_aktuell = (string)($mvtexte['beamer_zeige_aktuell'] ?? '');
    $bs_ist = fn(string ...$werte): bool => in_array($bs_aktuell, $werte, true);
    $bs_fett = fn(bool $treffer): string => $treffer ? 'font-weight:bold;' : '';
    $bs_top_offen = str_starts_with($bs_aktuell, 'top:') || str_starts_with($bs_aktuell, 'beschluss:');
    $bs_go_offen = str_starts_with($bs_aktuell, 'go:');
    $bs_wahl_offen = str_starts_with($bs_aktuell, 'wahl:');
    // Hauptanträge bleiben offen, solange keine der anderen drei Listen
    // dran ist - deckt sowohl den Normalfall (ein Antrag wird gezeigt) als
    // auch den Seitenaufruf ganz ohne bekannten Stand ab (Vorgabe wie
    // bisher: diese Liste ist die meistgenutzte).
    $bs_antraege_offen = !$bs_top_offen && !$bs_go_offen && !$bs_wahl_offen;
    ?>
    <details style="margin-bottom:12px;" <?= $bs_top_offen ? 'open' : '' ?>>
        <summary style="cursor:pointer; color:var(--primary); font-size:13px;">Einzelne Tagesordnungspunkte</summary>
        <div style="margin-top:8px; font-size:13px;">
            <?php foreach (get_tagesordnung($pdo, $jahr) as $i => $top): ?>
                <?php if (empty($top['aktiv'])) continue; ?>
                <a href="#" onclick="beamerZeigen('top:<?= (int)$top['id'] ?>'); return false;" style="margin-right:10px; <?= $bs_fett($bs_ist('top:' . $top['id'])) ?>">TOP <?= $i + 1 ?>: <?= h($top['titel']) ?></a>
                <a href="#" onclick="beamerZeigen('beschluss:<?= (int)$top['id'] ?>'); return false;" style="margin-right:10px; color:var(--text-secondary); <?= $bs_fett($bs_ist('beschluss:' . $top['id'])) ?>">Beschlussentwurf</a><br>
            <?php endforeach; ?>
        </div>
    </details>

    <details style="margin-bottom:12px;" <?= $bs_go_offen ? 'open' : '' ?>>
        <summary style="cursor:pointer; color:var(--primary); font-size:13px;">GO-Anträge</summary>
        <div style="margin-top:8px; font-size:13px;">
            <?php foreach (GO_ANTRAEGE as $i => $go): ?>
                <a href="#" onclick="beamerZeigen('go:<?= $i + 1 ?>'); return false;" style="margin-right:10px; <?= $bs_fett($bs_ist('go:' . ($i + 1))) ?>">GO <?= $i + 1 ?>: <?= h($go) ?></a><br>
            <?php endforeach; ?>
        </div>
    </details>

    <?php $kompakt_wahlgaenge = get_wahlgaenge($pdo, $jahr); if ($kompakt_wahlgaenge): ?>
    <details style="margin-bottom:12px;" <?= $bs_wahl_offen ? 'open' : '' ?>>
        <summary style="cursor:pointer; color:var(--primary); font-size:13px;">Wahlgänge</summary>
        <div style="margin-top:8px; font-size:13px;">
            <?php foreach ($kompakt_wahlgaenge as $w): ?>
                <a href="#" onclick="beamerZeigen('wahl:<?= (int)$w['id'] ?>'); return false;" style="margin-right:10px; <?= $bs_fett($bs_ist('wahl:' . $w['id'])) ?>">
                    🗳️ <?= h($w['bezeichnung']) ?> (Modus <?= h($w['modus']) ?>, <?= count($w['kandidaten']) ?> Kandidat:in(nen))
                </a><br>
            <?php endforeach; ?>
        </div>
    </details>
    <?php endif; ?>

    <details style="margin-bottom:12px;" <?= $bs_antraege_offen ? 'open' : '' ?>>
        <summary style="cursor:pointer; color:var(--primary); font-size:13px;">Hauptanträge / Änderungs- und Verfahrensanträge</summary>
        <div style="margin-top:8px; font-size:13px;">
            <?php
            $beamer_gezeigte_gem_gruppen = [];
            foreach (get_hauptantraege_mit_antragsteller($pdo, $jahr, antrag_sortierung_aktuell()) as $a):
                $gem = $a['Gem'] !== null ? (int)$a['Gem'] : null;
            ?>
                <div style="margin-bottom:4px;">
                    <?php if ($gem !== null && !isset($beamer_gezeigte_gem_gruppen[$gem])): $beamer_gezeigte_gem_gruppen[$gem] = true; ?>
                        <a href="#" onclick="beamerZeigen('gem:<?= $gem ?>'); return false;" style="margin-right:8px; <?= $bs_fett($bs_ist('gem:' . $gem)) ?>">📽️ Gruppe <?= $gem ?></a>
                    <?php endif; ?>
                    <a href="#" onclick="beamerZeigen('<?= h($a['Nr']) ?>'); return false;" style="<?= $bs_fett($bs_ist($a['Nr'], $a['Nr'] . ':komplett')) ?>">📽️ <?= h($a['Nr']) ?> <?= h($a['Titeltext']) ?></a>
                    <?php if ($gem !== null): ?><span class="badge" style="margin-left:4px;">Gruppe <?= $gem ?></span><?php endif; ?>
                </div>
                <?php foreach (get_folgeantraege($pdo, $jahr, (int)$a['ID']) as $f): ?>
                    <div style="margin:2px 0 4px 18px; color:var(--text-secondary);">
                        <a href="#" onclick="beamerZeigen('<?= h($f['Nr']) ?>'); return false;" style="<?= $bs_fett($bs_ist($f['Nr'], $f['Nr'] . ':komplett')) ?>">📽️ <?= h($f['Nr']) ?></a>
                        — <?= (int)$f['Art'] === ANTRAG_ART_AENDERUNG ? 'Änderungsantrag' : 'Verfahrensantrag' ?> — <?= h($f['Titeltext']) ?>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </details>

    <div style="display:flex; gap:20px; flex-wrap:wrap; align-items:center; font-size:13px; border-top:1px dotted var(--border-color); padding-top:10px;">
        <form method="post" style="display:flex; align-items:center; gap:6px;">
            <?= csrf_field() ?>
            <input type="hidden" name="aktion" value="beamer_uhr">
            <label><input type="checkbox" name="uhr" value="1" onchange="this.form.submit()" <?= ($mvtexte['uhr'] ?? 'n') === 'j' ? 'checked' : '' ?>> Uhrzeit auf der Folie</label>
        </form>

        <form method="post" style="display:flex; align-items:center; gap:6px;">
            <?= csrf_field() ?>
            <input type="hidden" name="aktion" value="beamer_timer_start">
            Timer für <input type="number" min="1" name="minuten" value="<?= h((string)($mvtexte['vorgabe'] ?? '')) ?>" style="width:60px; padding:4px;"> Minuten
            <button type="submit" class="btn" style="padding:4px 10px; font-size:12px;">Starten</button>
        </form>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="aktion" value="beamer_timer_stop">
            <button type="submit" class="btn btn-secondary" style="padding:4px 10px; font-size:12px;">Timer stoppen</button>
        </form>
    </div>
    </div>
    </div>
</div>
