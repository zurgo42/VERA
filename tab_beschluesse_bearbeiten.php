<?php
/**
 * tab_beschluesse_bearbeiten.php - Redaktionsformular fuer einen einzelnen
 * MV-Beschluss (neu anlegen oder bestehenden bearbeiten). Nur erreichbar
 * fuer ist_redaktion() (siehe tab_beschluesse.php).
 */

$antrnr = trim($_GET['antrnr'] ?? '');
$beschluss = $antrnr !== '' ? get_beschluss($pdo, $antrnr) : null;

$werte = $beschluss ?? [
    'antrnr' => '', 'titel' => '', 'text' => '', 'begr' => '', 'ergebnis' => '',
    'dafuer' => '', 'dagegen' => '', 'enth' => '', 'Relevant' => 1, 'kategorie' => '',
];
?>

<div class="mv-redaktion-aktiv">
<div style="display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:8px;">
    <h2><?= $beschluss ? 'Beschluss bearbeiten' : 'Neuen Beschluss anlegen' ?></h2>
    <a class="btn btn-secondary" href="?tab=beschluesse" style="padding:7px 14px; font-size:12.5px;">👁️ Zur Sammlung</a>
</div>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="aktion" value="speichern">
    <input type="hidden" name="alte_nr" value="<?= h($werte['antrnr']) ?>">

    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px;">
        <div class="form-group" style="flex:0 0 140px;">
            <label>Nummer</label>
            <input type="text" name="antrnr" value="<?= h($werte['antrnr']) ?>" required style="width:100%; padding:7px 10px;">
        </div>
        <div class="form-group" style="flex:0 0 200px;">
            <label>Kategorie</label>
            <input type="text" name="kategorie" value="<?= h($werte['kategorie'] ?? '') ?>" style="width:100%; padding:7px 10px;">
        </div>
        <div class="form-group" style="flex:1 1 240px;">
            <label>Titel</label>
            <input type="text" name="titel" value="<?= h($werte['titel']) ?>" required style="width:100%; padding:7px 10px;">
        </div>
    </div>

    <div class="form-group" style="margin-bottom:14px;">
        <label>Wortlaut des Beschlusses</label>
        <textarea name="text" rows="8" required style="width:100%; padding:8px 10px; font-family:inherit;"><?= h($werte['text']) ?></textarea>
    </div>

    <div class="form-group" style="margin-bottom:14px;">
        <label>Begründung (optional)</label>
        <textarea name="begr" rows="4" style="width:100%; padding:8px 10px; font-family:inherit;"><?= h($werte['begr'] ?? '') ?></textarea>
    </div>

    <div style="display:flex; gap:14px; flex-wrap:wrap; margin-bottom:18px;">
        <div class="form-group" style="flex:1 1 200px;">
            <label>Ergebnis (Freitext, z. B. „einstimmig angenommen“)</label>
            <input type="text" name="ergebnis" value="<?= h($werte['ergebnis'] ?? '') ?>" style="width:100%; padding:7px 10px;">
        </div>
        <div class="form-group" style="flex:0 0 auto;">
            <label>Dafür / Dagegen / Enthaltungen</label>
            <div style="display:flex; gap:6px;">
                <input type="number" min="0" name="dafuer" value="<?= h((string)($werte['dafuer'] ?? '')) ?>" style="width:70px; padding:7px 8px;">
                <input type="number" min="0" name="dagegen" value="<?= h((string)($werte['dagegen'] ?? '')) ?>" style="width:70px; padding:7px 8px;">
                <input type="number" min="0" name="enth" value="<?= h((string)($werte['enth'] ?? '')) ?>" style="width:70px; padding:7px 8px;">
            </div>
        </div>
        <div class="form-group" style="flex:0 0 auto;">
            <label>Status</label>
            <select name="relevant" style="padding:7px 10px;">
                <?php foreach (MV_BESCHLUSS_STATUS as $wert => $label): ?>
                    <option value="<?= $wert ?>" <?= (int)$werte['Relevant'] === $wert ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div style="display:flex; gap:10px; align-items:center;">
        <button type="submit" class="btn">💾 Speichern</button>
        <?php if ($beschluss): ?>
            <button type="submit" form="loeschform" class="btn-secondary" style="border-radius:6px; padding:10px 18px; border:none; color:white; cursor:pointer;"
                    onclick="return confirm('Beschluss <?= h(addslashes($werte['antrnr'])) ?> wirklich löschen?');">🗑️ Löschen</button>
        <?php endif; ?>
        <a href="?tab=beschluesse" style="color:var(--text-secondary);">Abbrechen</a>
    </div>
</form>

<?php if ($beschluss): ?>
    <form method="post" id="loeschform" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="loeschen">
        <input type="hidden" name="antrnr" value="<?= h($werte['antrnr']) ?>">
    </form>
<?php endif; ?>
</div>
