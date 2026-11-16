<?php
/**
 * tab_wahlgang_felder.php - Gemeinsame Formularfelder für "Wahlgang
 * anlegen"/"Wahlgang bearbeiten" (siehe tab_wahlgaenge.php), damit beide
 * garantiert gleich aussehen und sich gleich verhalten. Erwartet $w mit den
 * Schlüsseln bezeichnung/modus/anzahl_aemter/kandidaten (Array von Zeilen
 * mit 'name', wie von get_wahlgaenge()/get_wahlgang() zurückgegeben).
 */

$suffix = 'wg' . ($w['id'] ?? 'neu');
$kandidatenText = implode("\n", array_column($w['kandidaten'], 'name'));
?>
<div class="form-group" style="margin-bottom:12px;">
    <label>Bezeichnung</label>
    <input type="text" name="bezeichnung" value="<?= h($w['bezeichnung']) ?>" required
           placeholder="z. B. Wahl des Vorstands" style="width:100%; max-width:420px; padding:7px 10px;">
</div>

<div class="form-group" style="margin-bottom:12px;">
    <label>Modus</label><br>
    <select name="modus" id="modus_<?= $suffix ?>" onchange="wahlModusAendern_<?= $suffix ?>()" style="padding:6px 10px;">
        <option value="A" <?= $w['modus'] === 'A' ? 'selected' : '' ?>>A - jede:r Kandidat:in einzeln Ja/Nein/Enthaltung</option>
        <option value="B" <?= $w['modus'] === 'B' ? 'selected' : '' ?>>B - eine Stimme, ein Amt</option>
        <option value="C" <?= $w['modus'] === 'C' ? 'selected' : '' ?>>C - mehrere Stimmen, mehrere Ämter</option>
    </select>
</div>

<div class="form-group" style="margin-bottom:12px;" id="aemter_feld_<?= $suffix ?>">
    <label>Zu besetzende Ämter <span style="font-weight:400; color:var(--text-secondary);">(Modus A/C: Pflicht - gewählt sind nur so viele Kandidat:innen wie Ämter, nicht automatisch alle mit positivem Ergebnis)</span></label>
    <input type="number" name="anzahl_aemter" min="1" max="<?= WAHL_MAX_KANDIDATEN ?>" value="<?= h((string)($w['anzahl_aemter'] ?? '')) ?>" style="width:100px; padding:7px 10px;">
</div>

<div class="form-group" style="margin-bottom:12px;">
    <label>Kandidat:innen <span style="font-weight:400; color:var(--text-secondary);">(eine Zeile je Name, höchstens <?= WAHL_MAX_KANDIDATEN ?>)</span></label>
    <textarea name="kandidaten" rows="8" style="width:100%; max-width:420px; padding:8px; font-family:inherit;" placeholder="Vorname Nachname&#10;Vorname Nachname …"><?= h($kandidatenText) ?></textarea>
</div>

<script>
function wahlModusAendern_<?= $suffix ?>() {
    var modus = document.getElementById('modus_<?= $suffix ?>').value;
    document.getElementById('aemter_feld_<?= $suffix ?>').style.display = (modus === 'B') ? 'none' : 'block';
}
wahlModusAendern_<?= $suffix ?>();
</script>
