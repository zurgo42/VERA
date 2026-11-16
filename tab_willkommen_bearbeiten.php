<?php
/**
 * tab_willkommen_bearbeiten.php - Redaktionsmaske fuer den Willkommenstext.
 * Nur erreichbar fuer ist_redaktion() (siehe tab_willkommen.php).
 */
$text = (string)($mvtexte['willkommenstext'] ?? '');
?>

<div class="mv-redaktion-aktiv">
<div style="display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:8px;">
    <h2>Willkommenstext bearbeiten</h2>
    <a class="btn btn-secondary" href="?tab=willkommen" style="padding:7px 14px; font-size:12.5px;">👁️ Ansicht</a>
</div>

<form method="post" style="margin-bottom:14px;">
    <?= csrf_field() ?>
    <input type="hidden" name="feld" value="willkommenstext">
    <input type="hidden" name="rueck_tab" value="willkommen">
    <div class="form-group" style="margin-bottom:12px;">
        <label>Text für <?= h((string)$jahr) ?> (leer lassen für den Standardtext „<?= h(WILLKOMMEN_DEFAULTTEXT) ?>“)</label>
        <textarea name="text" rows="10" style="width:100%; padding:8px 10px; font-family:inherit;"><?= h($text) ?></textarea>
    </div>
    <button type="submit" class="btn">Speichern</button>
</form>

<form method="post" onsubmit="return confirm('Vorhandenen Text für <?= h((string)$jahr) ?> mit dem Text aus dem Vorjahr überschreiben?');">
    <?= csrf_field() ?>
    <input type="hidden" name="feld" value="willkommenstext">
    <input type="hidden" name="rueck_tab" value="willkommen">
    <input type="hidden" name="aktion" value="vorjahr_uebernehmen">
    <button type="submit" class="btn btn-secondary">Aus Vorjahr übernehmen</button>
</form>
</div>
