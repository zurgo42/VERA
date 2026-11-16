<?php
/**
 * tab_beschluesse.php - MV-Beschluss-Sammlung: durchsuchbares Archiv aller
 * Beschluesse der Mitgliederversammlung (nicht jahr-gebunden, siehe
 * beschluesse_functions.php).
 *
 * War vorher nur ein Link auf das gleichnamige Modul der
 * Sitzungsverwaltung - wird jetzt hier nativ gepflegt, da dieser Bereich
 * inhaltlich zur MV gehört. Die Sitzungsverwaltung soll künftig hierher
 * verlinken statt umgekehrt.
 */

require_once __DIR__ . '/beschluesse_functions.php';

if (!empty($_SESSION['admin_erfolg'])) {
    echo '<div class="info-box">' . h($_SESSION['admin_erfolg']) . '</div>';
    unset($_SESSION['admin_erfolg']);
}
if (!empty($_SESSION['admin_fehler'])) {
    echo '<div class="info-box" style="border-left-color:var(--danger); color:var(--danger);">' . h($_SESSION['admin_fehler']) . '</div>';
    unset($_SESSION['admin_fehler']);
}

if ($ist_redaktion && ($_GET['modus'] ?? '') === 'bearbeiten') {
    include __DIR__ . '/tab_beschluesse_bearbeiten.php';
    return;
}

$gueltig = isset($_GET['gueltig']) ? (int)$_GET['gueltig'] : 1;
$stichwort = trim($_GET['stichwort'] ?? '');
$order = $_GET['order'] ?? 'neu';

$beschluesse = get_beschluesse($pdo, $gueltig, $stichwort, $order);
?>

<div style="display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:8px;">
    <h2>MV-Beschluss-Sammlung</h2>
    <?php if ($ist_redaktion): ?>
        <a class="btn btn-secondary" href="?tab=beschluesse&modus=bearbeiten" style="padding:7px 14px; font-size:12.5px;">➕ Neuer Beschluss</a>
    <?php endif; ?>
</div>

<form method="get" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:16px;">
    <input type="hidden" name="tab" value="beschluesse">
    <select name="gueltig" style="padding:6px 8px;">
        <option value="1" <?= $gueltig === 1 ? 'selected' : '' ?>>Gültige</option>
        <option value="0" <?= $gueltig === 0 ? 'selected' : '' ?>>Aufhebungen</option>
        <option value="-1" <?= $gueltig === -1 ? 'selected' : '' ?>>Nicht beschlossen</option>
        <option value="9" <?= $gueltig === 9 ? 'selected' : '' ?>>Alle</option>
    </select>
    <select name="order" style="padding:6px 8px;">
        <option value="neu" <?= $order === 'neu' ? 'selected' : '' ?>>Neueste zuerst</option>
        <option value="alt" <?= $order === 'alt' ? 'selected' : '' ?>>Älteste zuerst</option>
    </select>
    <input type="text" name="stichwort" placeholder="Suche in Nummer, Titel, Text, Kategorie" value="<?= h($stichwort) ?>" style="flex:1 1 220px; padding:6px 10px;">
    <button type="submit" class="btn btn-secondary">Filtern</button>
</form>

<?php if (!$beschluesse): ?>
    <div class="info-box">Keine Beschlüsse gefunden.</div>
<?php else: ?>
    <?php foreach ($beschluesse as $b): ?>
        <?php
            $status = (int)$b['Relevant'];
            $status_klasse = $status === 0 ? 'beschluss-aufhebung' : ($status === -1 ? 'beschluss-abgelehnt' : '');
        ?>
        <div class="top-item beschluss-card <?= $status_klasse ?>">
            <div style="display:flex; justify-content:space-between; gap:10px; font-size:12px; color:var(--text-muted); margin-bottom:6px;">
                <strong style="color:var(--primary);"><?= h($b['antrnr']) ?></strong>
                <span><?= h($b['kategorie'] ?? '') ?></span>
            </div>
            <div class="top-title" style="margin-bottom:8px;"><?= beschluss_highlight(h($b['titel']), $stichwort) ?></div>
            <div style="margin-bottom:8px;"><?= beschluss_darstellen($b['text'], $stichwort) ?></div>
            <?php if (!empty($b['begr'])): ?>
                <div style="font-size:12.5px; color:var(--text-secondary); border-top:1px dotted var(--border-color); padding-top:8px; margin-bottom:8px;">
                    <strong>Begründung:</strong><br>
                    <?= beschluss_darstellen($b['begr'], $stichwort) ?>
                </div>
            <?php endif; ?>
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; font-size:12.5px; border-top:1px solid var(--border-color); padding-top:8px;">
                <span>Ergebnis: <strong><?= h($b['ergebnis']) ?></strong> (<?= (int)$b['dafuer'] ?>/<?= (int)$b['dagegen'] ?>/<?= (int)$b['enth'] ?>)</span>
                <?php if ($ist_redaktion): ?>
                    <a href="?tab=beschluesse&modus=bearbeiten&antrnr=<?= urlencode($b['antrnr']) ?>">✏️ Bearbeiten</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
