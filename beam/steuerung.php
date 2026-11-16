<?php
/**
 * steuerung.php - Projekt-Steuerung von BEAM (siehe dokumentation/BEAM.md):
 * Titelleiste, Standardfolien (wie VERA, ohne Tagesordnung), editierbare
 * Folie, Spontan-Abstimmungsfragen, die Abstimmungsmaschine selbst, bis zu
 * 25 vorbereitete Inhalte und die Stimmkarten-Verwaltung - alles für genau
 * ein Projekt. Modelliert nach neu/tab_beamersteuerung.php, aber ohne
 * Anträge/Tagesordnungspunkte, die es in BEAM nicht gibt.
 */

require_once __DIR__ . '/../session_config.php';
session_start();
require_once __DIR__ . '/beam_functions.php';

$pdo = get_pdo();
$rechte = beam_aktuelle_rechte($pdo);
$projektId = (int)($_GET['projekt'] ?? 0);
$projekt = beam_projekt_get($pdo, $projektId);

if (!$rechte['eingeloggt'] || !$rechte['darf_steuern'] || !$projekt || !beam_projekt_berechtigt($projekt, $rechte)) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

$mvtexte = get_mvtexte($pdo, $projektId) ?? [];
$inhalte = beam_inhalte_liste($pdo, $projektId);
$inhalteNachPosition = [];
foreach ($inhalte as $i) {
    $inhalteNachPosition[(int)$i['position']] = $i;
}

$bs_daten = beamer_vorschau_daten($pdo, $projektId);
$vote_round = $bs_daten['vote_round'];
$vote_wartend = $vote_round ? null : vote_round_wartet_auf_auswertung($pdo, $projektId);
$vote_letztes = ($vote_round || $vote_wartend) ? null : beamer_aktuelles_ergebnis($pdo, $mvtexte);
$aktueller_wahlgang = !empty($mvtexte['aktueller_wahlgang_id']) ? get_wahlgang($pdo, (int)$mvtexte['aktueller_wahlgang_id']) : null;
$vote_timer_vorgabe_sek_gesamt = isset($mvtexte['abstimmung_timer_vorgabe_sek']) && $mvtexte['abstimmung_timer_vorgabe_sek'] !== null ? (int)$mvtexte['abstimmung_timer_vorgabe_sek'] : 20;
$vote_timer_vorgabe_min = intdiv($vote_timer_vorgabe_sek_gesamt, 60);
$vote_timer_vorgabe_restsek = $vote_timer_vorgabe_sek_gesamt % 60;
$vote_timer_auto_beenden_an = (int)($mvtexte['abstimmung_timer_auto_beenden'] ?? 0) === 1;
$anzahlStimmkarten = vote_tokens_anzahl($pdo, $projektId);
$basisUrl = beam_basis_url();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Steuerung - <?= h($projekt['titel']) ?></title>
<link rel="stylesheet" href="beam_style.css">
<style><?= beamer_vorschau_css() ?></style>
</head>
<body>
<header class="beam-kopf">
    <h1>📽️ <?= h($projekt['titel']) ?></h1>
    <a href="index.php">← Zu den Projekten</a>
</header>
<main class="beam-inhalt">

<?php if (!empty($_SESSION['beam_erfolg'])): ?>
    <div class="info-box"><?= h($_SESSION['beam_erfolg']) ?></div>
    <?php unset($_SESSION['beam_erfolg']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['beam_erfolg_html'])): ?>
    <div class="info-box"><?= $_SESSION['beam_erfolg_html'] ?></div>
    <?php unset($_SESSION['beam_erfolg_html']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['beam_fehler'])): ?>
    <div class="info-box fehler"><?= h($_SESSION['beam_fehler']) ?></div>
    <?php unset($_SESSION['beam_fehler']); ?>
<?php endif; ?>

<div class="card">
    <form method="post" action="process_steuerung.php" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="projekt" value="<?= $projektId ?>">
        <input type="hidden" name="aktion" value="titel_setzen">
        <label style="font-size:13px;">Titelleiste:</label>
        <input type="text" name="titel" value="<?= h($projekt['titel']) ?>" style="flex:1 1 300px; padding:7px 10px;">
        <button type="submit" class="btn btn-secondary" style="padding:7px 12px;">Speichern</button>
    </form>
    <p style="font-size:12.5px; color:var(--text-secondary); margin:10px 0 0;">
        <a href="beamerbild.php?projekt=<?= $projektId ?>" target="beamer" class="btn btn-secondary" style="padding:4px 10px; font-size:12px;">🖥️ Beamer-Fenster öffnen</a>
        &nbsp; Teilnahme-Link-Basis: <code><?= h($basisUrl) ?></code> (steht automatisch auch auf den Stimmkarten-QR-Codes)
    </p>
</div>

<div class="card">
    <!-- Gemeinsames verstecktes Formular fuer alle "Folie zeigen"-Buttons. -->
    <form method="post" action="process_steuerung.php" id="beamerform">
        <?= csrf_field() ?>
        <input type="hidden" name="projekt" value="<?= $projektId ?>">
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
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button type="button" class="btn btn-secondary" style="padding:6px 10px; font-size:12.5px;" onclick="beamerZeigen('willkommen')">Willkommen</button>
        <span>
            <button type="button" class="btn btn-secondary" style="padding:6px 10px; font-size:12.5px;" onclick="beamerZeigen('pause', 'pausebis_eingabe')">Pause</button>
            <input type="text" id="pausebis_eingabe" placeholder="bis HH:MM" size="6" style="padding:5px;">
        </span>
        <button type="button" class="btn btn-secondary" style="padding:6px 10px; font-size:12.5px;" onclick="beamerZeigen('stimm')">Stimmabgabe</button>
        <button type="button" class="btn btn-secondary" style="padding:6px 10px; font-size:12.5px;" onclick="beamerZeigen('unter')">Unterbrochen</button>
        <button type="button" class="btn btn-secondary" style="padding:6px 10px; font-size:12.5px;" onclick="beamerZeigen('leer')">Leere Folie</button>
        <button type="button" class="btn btn-secondary" style="padding:6px 10px; font-size:12.5px;" onclick="beamerZeigen('go')">GO-Anträge (Liste)</button>
    </div>
</div>

<div class="bs-layout">
<div style="grid-area: freitext;">

<div class="card">
    <form method="post" action="process_steuerung.php">
        <?= csrf_field() ?>
        <input type="hidden" name="projekt" value="<?= $projektId ?>">
        <input type="hidden" name="aktion" value="beamer_zeigen">
        <input type="hidden" name="zeige" value="text">
        <label style="display:block; font-size:12.5px; color:var(--text-secondary); margin-bottom:4px;">Editierbare Folie - eigener Text für den Beamer:</label>
        <textarea name="freitext" rows="3" style="width:100%; padding:6px 8px; font-family:inherit; font-size:13px;" placeholder="Eigenen Text eingeben …"></textarea><br>
        <button type="submit" class="btn btn-secondary" style="margin-top:6px; padding:6px 10px; font-size:12.5px;">📽️ Anzeigen</button>
    </form>
</div>

<div class="card">
    <form method="post" action="process_steuerung.php" id="abstimmungsvarianteform">
        <?= csrf_field() ?>
        <input type="hidden" name="projekt" value="<?= $projektId ?>">
        <input type="hidden" name="aktion" value="beamer_abstimmungsvariante">
        <label style="display:block; font-size:12.5px; color:var(--text-secondary); margin-bottom:4px;">Spontan-Abstimmung vorbereiten und sofort anzeigen:</label>
        <div style="display:flex; gap:14px; flex-wrap:wrap; font-size:12.5px; margin:4px 0 8px;">
            <label><input type="radio" name="typ" value="ja_nein" checked onchange="abstimmungsvarianteTypUmschalten()"> Ja/Nein/Enthaltung</label>
            <label><input type="radio" name="typ" value="auswahl" onchange="abstimmungsvarianteTypUmschalten()"> Auswahlfrage (eine Variante)</label>
            <label><input type="radio" name="typ" value="praeferenz" onchange="abstimmungsvarianteTypUmschalten()"> Präferenz (mehrere Varianten)</label>
        </div>
        <input type="text" name="frage" placeholder="Frage / Text …" style="width:100%; padding:6px 8px; font-size:13px;"><br>
        <div id="abstimmungsvariante-varianten-wrap" style="display:none; margin-top:6px;">
            <label style="display:block; font-size:12.5px; color:var(--text-secondary); margin-bottom:4px;">Varianten (eine je Zeile, max. 15):</label>
            <textarea name="varianten" rows="4" style="width:100%; padding:6px 8px; font-family:inherit; font-size:13px;" placeholder="Variante 1&#10;Variante 2&#10;…"></textarea>
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

<div class="card">
    <h3 style="margin-top:0; font-size:15px;">Vorbereitete Inhalte (bis zu <?= BEAM_MAX_INHALTE ?>)</h3>
    <table class="beam-tabelle">
        <tr><th>#</th><th>Inhalt</th><th></th></tr>
        <?php for ($pos = 1; $pos <= BEAM_MAX_INHALTE; $pos++): $i = $inhalteNachPosition[$pos] ?? null; ?>
            <tr>
                <td><?= $pos ?></td>
                <td>
                    <?php if (!$i): ?>
                        <span style="color:var(--text-secondary);">(leer)</span>
                    <?php elseif ($i['typ'] === 'text'): ?>
                        <?= h(mb_substr((string)$i['text'], 0, 60)) ?><?= mb_strlen((string)$i['text']) > 60 ? ' …' : '' ?>
                    <?php else: ?>
                        🗳️ <?= h($i['frage_bezeichnung']) ?> (Modus <?= h($i['frage_modus']) ?>)
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                    <?php if ($i): ?>
                        <form method="post" action="process_steuerung.php" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="projekt" value="<?= $projektId ?>">
                            <input type="hidden" name="aktion" value="beamer_zeigen">
                            <input type="hidden" name="zeige" value="inhalt:<?= $pos ?>">
                            <button type="submit" class="btn" style="padding:3px 8px; font-size:11.5px;">Abrufen</button>
                        </form>
                        <form method="post" action="process_steuerung.php" style="display:inline;" onsubmit="return confirm('Diesen Inhalt wirklich löschen?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="projekt" value="<?= $projektId ?>">
                            <input type="hidden" name="aktion" value="inhalt_loeschen">
                            <input type="hidden" name="position" value="<?= $pos ?>">
                            <button type="submit" class="btn btn-secondary" style="padding:3px 8px; font-size:11.5px;">Löschen</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endfor; ?>
    </table>

    <details style="margin-top:10px;">
        <summary style="cursor:pointer; color:var(--primary); font-size:13px;">Inhalt an einer Position speichern/ersetzen</summary>
        <form method="post" action="process_steuerung.php" id="inhaltform" style="margin-top:10px;">
            <?= csrf_field() ?>
            <input type="hidden" name="projekt" value="<?= $projektId ?>">
            <div style="margin-bottom:8px;">
                Position:
                <select name="position" style="padding:5px;">
                    <?php for ($pos = 1; $pos <= BEAM_MAX_INHALTE; $pos++): ?>
                        <option value="<?= $pos ?>"><?= $pos ?><?= isset($inhalteNachPosition[$pos]) ? ' (belegt)' : '' ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div style="display:flex; gap:14px; flex-wrap:wrap; font-size:12.5px; margin-bottom:8px;">
                <label><input type="radio" name="inhalt_typ" value="text" checked onchange="beamInhaltTypUmschalten()"> Text</label>
                <label><input type="radio" name="inhalt_typ" value="B" onchange="beamInhaltTypUmschalten()"> Wahl/Auswahl (eine Stimme)</label>
                <label><input type="radio" name="inhalt_typ" value="A" onchange="beamInhaltTypUmschalten()"> Wahl (je Kandidat:in Ja/Nein/Enth.)</label>
                <label><input type="radio" name="inhalt_typ" value="C" onchange="beamInhaltTypUmschalten()"> Wahl (mehrere Ämter)</label>
                <label><input type="radio" name="inhalt_typ" value="P" onchange="beamInhaltTypUmschalten()"> Präferenz (beliebig viele)</label>
            </div>

            <div id="inhalt-text-wrap">
                <textarea name="text" rows="3" style="width:100%; padding:6px 8px; font-family:inherit; font-size:13px;" placeholder="Text / Frage …"></textarea>
            </div>
            <div id="inhalt-frage-wrap" style="display:none;">
                <input type="text" name="bezeichnung" placeholder="Bezeichnung …" style="width:100%; padding:6px 8px; font-size:13px; margin-bottom:6px;">
                <textarea name="varianten" rows="5" style="width:100%; padding:6px 8px; font-family:inherit; font-size:13px;" placeholder="Eine Kandidatin/Variante je Zeile, max. 15 …"></textarea>
            </div>
            <button type="submit" class="btn btn-secondary" style="margin-top:8px; padding:6px 10px; font-size:12.5px;">Speichern</button>
        </form>
        <script>
        function beamInhaltTypUmschalten() {
            var typ = document.querySelector('#inhaltform input[name="inhalt_typ"]:checked').value;
            var istText = (typ === 'text');
            document.getElementById('inhalt-text-wrap').style.display = istText ? 'block' : 'none';
            document.getElementById('inhalt-frage-wrap').style.display = istText ? 'none' : 'block';
            document.getElementById('inhaltform').setAttribute('data-typ', typ);
        }
        document.getElementById('inhaltform').addEventListener('submit', function (ev) {
            var typ = document.querySelector('#inhaltform input[name="inhalt_typ"]:checked').value;
            var aktionFeld = document.createElement('input');
            aktionFeld.type = 'hidden';
            aktionFeld.name = 'aktion';
            if (typ === 'text') {
                aktionFeld.value = 'inhalt_text_speichern';
            } else {
                aktionFeld.value = 'inhalt_frage_speichern';
                var modusFeld = document.createElement('input');
                modusFeld.type = 'hidden';
                modusFeld.name = 'modus';
                modusFeld.value = typ;
                this.appendChild(modusFeld);
            }
            this.appendChild(aktionFeld);
        });
        </script>
    </details>
</div>

<div class="card">
    <h3 style="margin-top:0; font-size:15px;">Stimmkarten</h3>
    <p style="font-size:12.5px; color:var(--text-secondary);">Bisher insgesamt erzeugt: <strong><?= $anzahlStimmkarten ?></strong>.</p>
    <form method="post" action="process_steuerung.php" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:8px;">
        <?= csrf_field() ?>
        <input type="hidden" name="projekt" value="<?= $projektId ?>">
        <input type="hidden" name="aktion" value="stimmkarten_erzeugen">
        <input type="number" name="anzahl" value="50" min="1" max="2000" style="width:100px; padding:6px;">
        <button type="submit" class="btn btn-secondary" style="padding:6px 12px; font-size:12.5px;">PDF erzeugen</button>
    </form>
    <form method="post" action="process_steuerung.php" onsubmit="return confirm('Wirklich alle Stimmkarten dieses Projekts samt PDF-Sammeldateien löschen?');">
        <?= csrf_field() ?>
        <input type="hidden" name="projekt" value="<?= $projektId ?>">
        <input type="hidden" name="aktion" value="stimmkarten_loeschen">
        <button type="submit" class="btn btn-secondary" style="padding:6px 12px; font-size:12.5px;">Stimmkarten-Pool löschen</button>
    </form>
</div>

<div class="card">
    <div style="display:flex; gap:20px; flex-wrap:wrap; align-items:center; font-size:13px;">
        <form method="post" action="process_steuerung.php" style="display:flex; align-items:center; gap:6px;">
            <?= csrf_field() ?>
            <input type="hidden" name="projekt" value="<?= $projektId ?>">
            <input type="hidden" name="aktion" value="beamer_uhr">
            <label><input type="checkbox" name="uhr" value="1" onchange="this.form.submit()" <?= ($mvtexte['uhr'] ?? 'n') === 'j' ? 'checked' : '' ?>> Uhrzeit auf der Folie</label>
        </form>
        <form method="post" action="process_steuerung.php" style="display:flex; align-items:center; gap:6px;">
            <?= csrf_field() ?>
            <input type="hidden" name="projekt" value="<?= $projektId ?>">
            <input type="hidden" name="aktion" value="beamer_timer_start">
            Timer für <input type="number" min="1" name="minuten" value="<?= h((string)($mvtexte['vorgabe'] ?? '')) ?>" style="width:60px; padding:4px;"> Minuten
            <button type="submit" class="btn" style="padding:4px 10px; font-size:12px;">Starten</button>
        </form>
        <form method="post" action="process_steuerung.php">
            <?= csrf_field() ?>
            <input type="hidden" name="projekt" value="<?= $projektId ?>">
            <input type="hidden" name="aktion" value="beamer_timer_stop">
            <button type="submit" class="btn btn-secondary" style="padding:4px 10px; font-size:12px;">Timer stoppen</button>
        </form>
    </div>
</div>

</div>

<div class="bs-preview">
<!-- Spontane Korrektur des GERADE projizierten Bilds waehrend der
     laufenden Sitzung - siehe neu/tab_beamersteuerung.php, dieselbe Box
     (process_steuerung.php, Aktion beamer_bild_bearbeiten). -->
<div class="card" style="margin-bottom:12px;">
<h3 style="margin:0 0 8px; font-size:15px;">✏️ Aktuelles Bild bearbeiten</h3>
<p style="font-size:12px; color:var(--text-secondary); margin:0 0 8px;">Für spontane Korrekturen am gerade projizierten Bild - der Inhalt lässt sich hier direkt anpassen und erneut senden.</p>
<form method="post" action="process_steuerung.php">
    <?= csrf_field() ?>
    <input type="hidden" name="projekt" value="<?= $projektId ?>">
    <input type="hidden" name="aktion" value="beamer_bild_bearbeiten">
    <textarea name="html" rows="8" style="width:100%; padding:6px 8px; font-family:monospace; font-size:12px;"><?= h(beamer_html_lesbar_machen((string)($mvtexte['beamer'] ?? ''))) ?></textarea><br>
    <button type="submit" class="btn" style="margin-top:6px; padding:6px 12px; font-size:12.5px;">📽️ Übernehmen &amp; projizieren</button>
</form>
</div>

<div class="card">
<h3 style="margin:0 0 8px; font-size:15px;">🗳️ Abstimmungsmaschine</h3>

<div style="width:100%; max-width:480px; aspect-ratio:16/9; overflow:hidden; border:1px solid var(--border-color); border-radius:6px;">
    <div id="beamer-vorschau-inner" style="width:960px; height:540px; transform:scale(.5); transform-origin:0 0; pointer-events:none;">
        <?= beamer_vorschau_render($bs_daten, $projektId, true, true, $projekt['titel'], '../wlan_qr.php') ?>
    </div>
</div>
<script>
(function () {
    var inner = document.getElementById('beamer-vorschau-inner');
    function aktualisieren() {
        fetch('beamer_vorschau_status.php?projekt=<?= $projektId ?>').then(function (r) { return r.json(); }).then(function (d) {
            if (d.html) { inner.innerHTML = d.html; }
        }).catch(function () {});
    }
    setInterval(aktualisieren, 3000);
})();
</script>

<div style="margin-top:10px; padding-top:10px; border-top:1px dotted var(--border-color);">
    <form method="post" action="process_steuerung.php" id="abstimmung-timer-form" style="display:flex; gap:6px; align-items:center; flex-wrap:wrap; font-size:12.5px;">
        <?= csrf_field() ?>
        <input type="hidden" name="projekt" value="<?= $projektId ?>">
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
            <?= $ist_wahl ? 'Wahl' : 'Abstimmung' ?> läuft (gestartet <?= h(format_datum_zeit($vote_round['started_at'])) ?>).
        </p>
        <div id="vote-ablaufbalken-wrap" style="display:none; height:8px; background:var(--bg-secondary); border-radius:4px; margin:8px 0; overflow:hidden;">
            <div id="vote-ablaufbalken" style="height:100%; width:0%; background:#4caf50; transition:width 1s linear;"></div>
        </div>
        <p id="vote-timer-rest" style="display:none; font-size:12px; color:var(--text-secondary); margin:4px 0;"></p>
        <p style="font-size:24px; font-weight:600; margin:10px 0;" id="vote-zaehler">…</p>
        <p id="vote-warnung" style="display:none; color:var(--danger); font-size:12.5px;">⚠️ Die Antworten kommen merklich langsamer an als sonst.</p>
        <form method="post" action="process_steuerung.php" style="display:inline-block; margin-right:8px;">
            <?= csrf_field() ?>
            <input type="hidden" name="projekt" value="<?= $projektId ?>">
            <input type="hidden" name="aktion" value="abstimmung_beenden">
            <input type="hidden" name="round_id" value="<?= (int)$vote_round['round_id'] ?>">
            <button type="submit" class="btn" style="padding:6px 12px; font-size:12.5px;"><?= $ist_wahl ? 'Wahl' : 'Abstimmung' ?> beenden</button>
        </form>
        <?php if ($vote_timer_auto_beenden_an && $bs_daten['vote_timer_rest'] !== null): ?>
            <form method="post" action="process_steuerung.php" style="display:inline-block;">
                <?= csrf_field() ?>
                <input type="hidden" name="projekt" value="<?= $projektId ?>">
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
                fetch('abstimmung_status.php?projekt=<?= $projektId ?>').then(function (r) { return r.json(); }).then(function (d) {
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
                        timerRest.textContent = (d.vote_timer_rest >= 0 ? 'Endet in ' : 'Zeit abgelaufen, seit ') + String(mm).padStart(2, '0') + ':' + String(ss).padStart(2, '0');
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
            <?= $ist_wahl ? 'Wahl' : 'Abstimmung' ?> beendet (<?= $ist_wahl ? wahl_round_zaehler($pdo, (int)$vote_wartend['round_id']) : vote_round_zaehler($pdo, (int)$vote_wartend['round_id']) ?> Teilnehmer:in(nen)) - jetzt auswerten.
        </p>
        <form method="post" action="process_steuerung.php">
            <?= csrf_field() ?>
            <input type="hidden" name="projekt" value="<?= $projektId ?>">
            <input type="hidden" name="aktion" value="abstimmung_auswerten">
            <input type="hidden" name="round_id" value="<?= (int)$vote_wartend['round_id'] ?>">
            <button type="submit" class="btn" style="padding:6px 12px; font-size:12.5px;">Auswerten</button>
        </form>
    <?php else: ?>
        <p style="font-size:12.5px; color:var(--text-secondary);">
            <?php if ($aktueller_wahlgang): ?>
                Startet eine Wahl mit den gerade auf dem Beamer gezeigten Kandidat:innen (Modus <?= h($aktueller_wahlgang['modus']) ?>).
            <?php else: ?>
                Startet eine Abstimmung mit dem aktuell auf dem Beamer gezeigten Inhalt als Fragetext (Ja/Nein/Enthaltung).
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
        <form method="post" action="process_steuerung.php" style="display:inline-block; margin-right:8px;">
            <?= csrf_field() ?>
            <input type="hidden" name="projekt" value="<?= $projektId ?>">
            <input type="hidden" name="aktion" value="abstimmung_starten">
            <button type="submit" class="btn" style="padding:6px 12px; font-size:12.5px;"><?= $aktueller_wahlgang ? 'Wahl mit diesen Kandidat:innen starten' : 'Abstimmung mit diesem Inhalt starten' ?></button>
        </form>
        <form method="post" action="process_steuerung.php" style="display:inline-block;">
            <?= csrf_field() ?>
            <input type="hidden" name="projekt" value="<?= $projektId ?>">
            <input type="hidden" name="aktion" value="akklamation_setzen">
            <button type="submit" class="btn btn-secondary" style="padding:6px 12px; font-size:12.5px;">🤝 Zustimmung per Akklamation</button>
        </form>
    <?php endif; ?>
</div>
</div>
</div>

</main>
</body>
</html>
