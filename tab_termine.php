<?php
/**
 * tab_termine.php - Termine des aktuellen Jahres (Tabelle mvtexte).
 */
?>
<h3>Termine <?= h((string)$jahr) ?></h3>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="bereich" value="termine">

    <div class="form-group" style="margin-bottom:14px;">
        <label>Versammlungsdatum</label>
        <input type="text" name="mvdatum" placeholder="TT.MM.JJJJ"
               value="<?= h($mvtexte['mvdatum'] ?? '') ?>" style="max-width:160px;">
    </div>

    <div class="form-group" style="margin-bottom:14px;">
        <label>Beginnzeit (Stunde)</label>
        <input type="text" name="mvbeginn" placeholder="z. B. 10"
               value="<?= h($mvtexte['mvbeginn'] ?? '') ?>" style="max-width:100px;">
    </div>

    <div class="form-group" style="margin-bottom:14px;">
        <label>Ort</label>
        <input type="text" name="mvort" value="<?= h($mvtexte['mvort'] ?? '') ?>" style="max-width:280px;">
    </div>

    <div class="form-group" style="margin-bottom:14px;">
        <label>Antragsschluss</label>
        <input type="text" name="antragsende" placeholder="TT.MM.JJJJ"
               value="<?= h($mvtexte['antragsende'] ?? '') ?>" style="max-width:160px;">
    </div>

    <div class="form-group" style="margin-bottom:14px;">
        <label>Enddatum Unterstützungs-Voten (optional)</label>
        <input type="text" name="unterstuetzungsende" placeholder="TT.MM.JJJJ"
               value="<?= h($mvtexte['unterstuetzungsende'] ?? '') ?>" style="max-width:160px;">
        <p style="font-size:12.5px; color:var(--text-secondary); margin-top:4px;">
            Wenn gesetzt, gilt dieses feste Datum statt der unter „Vereinsspezifische Vorgaben“ eingestellten Frist (Antragsschluss + X Wochen). Leer lassen, um bei der berechneten Frist zu bleiben.
        </p>
    </div>

    <div class="form-group" style="margin-bottom:14px;">
        <label>Wahl-Info: Link Wahlverfahren</label>
        <input type="url" name="wahllink" placeholder="https://..."
               value="<?= h($mvtexte['wahllink'] ?? '') ?>" style="max-width:420px;">
    </div>

    <div class="form-group" style="margin-bottom:18px;">
        <label>Wahl-Info: Link Info &amp; Kandidierende</label>
        <input type="url" name="wahlinfo" placeholder="https://..."
               value="<?= h($mvtexte['wahlinfo'] ?? '') ?>" style="max-width:420px;">
    </div>

    <button type="submit" class="btn">Speichern</button>
</form>
