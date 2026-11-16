<?php
/**
 * tab_abstimmung.php - Admin-Funktion "Stimmkarten erzeugen" (Abstimmungsmaschine,
 * Phase 1). Siehe dokumentation/Abstimmungsmaschine.md für den Gesamtplan.
 * Die laufende Abstimmungsrunde selbst wird nicht hier, sondern auf der
 * eigenen Beamersteuerung-Seite bedient (tab_beamersteuerung.php, Abschnitt
 * "Abstimmungsmaschine") - dieselbe Stelle, an der auch das Beamerbild
 * bedient wird, und deshalb wie dort auf Technik/Beamer-Team und
 * Redaktionsteam eingegrenzt (ist_beamersteuerung_berechtigt()), nicht nur
 * die Redaktion wie die übrigen Admin-Funktionen hier.
 */

require_once __DIR__ . '/abstimmung_functions.php';

$basisUrl = trim($config['abstimmung_basis_url'] ?? '');
$anzahlBisher = vote_tokens_anzahl($pdo, $jahr);
?>
<h3>Stimmkarten erzeugen</h3>
<p style="color:var(--text-secondary); font-size:13.5px;">
    Erzeugt eine PDF-Sammeldatei mit vier Stimmkarten (QR-Code) je DIN-A4-Seite zum Ausdrucken und
    Zerschneiden - eine Karte je erwarteter Teilnehmer:in. Die Karten sind rein anonym (kein
    Bezug zu einer Mitgliedsnummer); wer welche Karte am Einlass bekommt, weiß das System nicht
    und muss es auch nicht. Bisher insgesamt erzeugt: <strong><?= $anzahlBisher ?></strong>.
    Die laufende Abstimmung selbst wird über die <a href="?tab=beamersteuerung">Beamersteuerung</a> gestartet/beendet.
</p>

<?php if ($basisUrl === ''): ?>
    <div class="info-box" style="border-left-color:var(--danger); color:var(--danger);">
        Bitte zuerst unter „Vereinsspezifische Vorgaben“ die Basis-URL der Abstimmungsmaschine eintragen.
    </div>
<?php else: ?>
    <form method="post" style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; margin-bottom:20px;">
        <?= csrf_field() ?>
        <input type="hidden" name="bereich" value="abstimmung">
        <div class="form-group">
            <label>Anzahl Stimmkarten</label>
            <input type="number" name="anzahl" value="100" min="1" max="2000" style="width:120px; padding:7px 10px;">
        </div>
        <button type="submit" class="btn">Stimmkarten erzeugen</button>
    </form>
<?php endif; ?>

<?php $pdfs = vote_pdf_liste(); if ($pdfs): ?>
    <h3>Bisher erzeugte Sammeldateien</h3>
    <ul>
        <?php foreach ($pdfs as $datei): ?>
            <li><a href="abstimmung_pdf.php?datei=<?= h(urlencode($datei)) ?>"><?= h($datei) ?></a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($anzahlBisher > 0): ?>
    <h3>Stimmkarten-Pool löschen</h3>
    <p style="color:var(--text-secondary); font-size:13.5px;">
        Löscht alle <?= $anzahlBisher ?> bisher erzeugten Stimmkarten endgültig, samt der dafür
        erzeugten PDF-Sammeldateien oben (z. B. wenn der Pool "verbraucht" ist und für die nächste
        Abstimmung/Wahl frische Karten ausgegeben werden sollen). Geht nur, solange gerade keine
        Abstimmung/Wahl läuft oder auf Auswertung wartet. Bereits ausgezählte Ergebnisse/Protokolle
        bleiben davon unberührt.
    </p>
    <form method="post" onsubmit="return confirm('Wirklich alle ' + <?= $anzahlBisher ?> + ' Stimmkarten samt PDF-Sammeldateien endgültig löschen?');">
        <?= csrf_field() ?>
        <input type="hidden" name="bereich" value="abstimmung">
        <input type="hidden" name="aktion" value="stimmkarten_loeschen">
        <button type="submit" class="btn btn-secondary" style="color:var(--danger);">Alle Stimmkarten löschen</button>
    </form>
<?php endif; ?>
