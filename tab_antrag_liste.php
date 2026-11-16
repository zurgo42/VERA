<?php
/**
 * tab_antrag_liste.php - Anträge zeigen/bearbeiten (Ablösung von mvliste.php).
 * Liste aller Hauptanträge; Klick auf einen Antrag zeigt Details und -
 * solange noch nicht abgestimmt - die Möglichkeit, dazu einen
 * Änderungs- oder Verfahrensantrag zu stellen (siehe tab_antrag_detail.php).
 *
 * Änderungs-/Verfahrensanträge erscheinen bewusst nur hier, direkt beim
 * betroffenen Hauptantrag, nicht unter "Antrag stellen" - dort gibt es ja
 * noch keinen Antrag, auf den sie sich beziehen könnten.
 */

require_once __DIR__ . '/antraege_functions.php';

if (!empty($_SESSION['admin_erfolg'])) {
    echo '<div class="info-box">' . h($_SESSION['admin_erfolg']) . '</div>';
    unset($_SESSION['admin_erfolg']);
}
if (!empty($_SESSION['admin_fehler'])) {
    echo '<div class="info-box" style="border-left-color:var(--danger); color:var(--danger);">' . h($_SESSION['admin_fehler']) . '</div>';
    unset($_SESSION['admin_fehler']);
}

$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    include __DIR__ . '/tab_antrag_detail.php';
    return;
}

$sortierung = antrag_sortierung_aktuell();
$hauptantraege = get_hauptantraege_mit_antragsteller($pdo, $jahr, $sortierung);
?>

<h2>Anträge zeigen/bearbeiten</h2>

<form method="get" style="display:flex; gap:8px; align-items:center; margin-bottom:16px; flex-wrap:wrap;">
    <input type="hidden" name="tab" value="antrag_liste">
    <label for="sortierung" style="font-size:13px; color:var(--text-secondary);">Sortierung</label>
    <select name="sortierung" id="sortierung" onchange="this.form.submit()" style="padding:6px 8px;">
        <?php foreach (ANTRAG_SORTIERUNGEN as $wert => $label): ?>
            <option value="<?= h($wert) ?>" <?= $sortierung === $wert ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<?php if (!$hauptantraege): ?>
    <div class="info-box">Für <?= h((string)$jahr) ?> liegen noch keine Anträge vor.</div>
<?php else: ?>
    <div class="top-list">
        <?php foreach ($hauptantraege as $a): ?>
            <a href="?tab=antrag_liste&id=<?= (int)$a['ID'] ?>" class="top-row" style="text-decoration:none; color:inherit; flex-wrap:wrap;">
                <span class="top-nr"><?= h($a['Nr']) ?></span>
                <span class="top-title"><?= h($a['Titeltext']) ?></span>
                <span style="font-size:12px; color:var(--text-muted); flex:none;">
                    <?= h(antragsteller_name($a)) ?><?php if (!empty($a['Datum'])): ?> · <?= h(format_datum_zeit($a['Datum'])) ?><?php endif; ?>
                </span>
                <span class="badge"><?= antrag_wurde_abgestimmt($a) ? h($a['Ergebnis']) : 'Offen' ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
