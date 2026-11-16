<?php
/**
 * tab_wahlinfo.php - Wahl-Information: kurzer, jahresabhängiger
 * Redaktionstext (mvtexte.wahltext) plus Sprung zum bestehenden
 * Wahltool/Wahlausschuss. Nutzt die je Jahr in mvtexte hinterlegten
 * Felder wahllink/wahlinfo - beides Links, genau wie im Altsystem
 * (mv.php: "Wahlverfahren" / "Info und Kandidierende") -, damit hier
 * keine Wahl-Logik dupliziert werden muss. Die Links selbst werden unter
 * Admin > Termine gepflegt, der Text hier direkt auf dieser Seite.
 */

const WAHLTEXT_DEFAULT = 'Zu wählen sind diesmal X Vorstandsmitglieder';

if (!empty($_SESSION['admin_erfolg'])) {
    echo '<div class="info-box">' . h($_SESSION['admin_erfolg']) . '</div>';
    unset($_SESSION['admin_erfolg']);
}
if (!empty($_SESSION['admin_fehler'])) {
    echo '<div class="info-box" style="border-left-color:var(--danger); color:var(--danger);">' . h($_SESSION['admin_fehler']) . '</div>';
    unset($_SESSION['admin_fehler']);
}

$wahllink = $mvtexte['wahllink'] ?? null;
$wahlinfo = $mvtexte['wahlinfo'] ?? null;
$wahltext = trim((string)($mvtexte['wahltext'] ?? ''));
?>
<h2>Wahl-Information</h2>

<div class="top-item">
    <?= format_antragstext($wahltext !== '' ? $wahltext : WAHLTEXT_DEFAULT) ?>
</div>

<?php if ($ist_redaktion): ?>
    <details style="margin-bottom:16px;">
        <summary style="cursor:pointer; color:var(--primary); font-weight:600;">✏️ Diesen einleitenden Text verändern</summary>
        <form method="post" style="margin-top:10px;">
            <?= csrf_field() ?>
            <input type="hidden" name="feld" value="wahltext">
            <input type="hidden" name="rueck_tab" value="wahlinfo">
            <div class="form-group" style="margin-bottom:12px;">
                <label>Text für <?= h((string)$jahr) ?> (leer lassen für den Standardtext „<?= h(WAHLTEXT_DEFAULT) ?>“)</label>
                <textarea name="text" rows="4" style="width:100%; padding:8px 10px; font-family:inherit;"><?= h($wahltext) ?></textarea>
            </div>
            <button type="submit" class="btn">Speichern</button>
        </form>
    </details>
<?php endif; ?>

<?php if (!$wahllink && !$wahlinfo): ?>
    <div class="info-box">Für <?= h((string)$jahr) ?> sind noch keine Wahl-Links hinterlegt.</div>
<?php else: ?>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <?php if ($wahllink): ?>
            <a class="btn" href="<?= h($wahllink) ?>" target="_blank" rel="noopener">Wahlverfahren</a>
        <?php endif; ?>
        <?php if ($wahlinfo): ?>
            <a class="btn" href="<?= h($wahlinfo) ?>" target="_blank" rel="noopener">Info und Kandidierende</a>
        <?php endif; ?>
    </div>
<?php endif; ?>
