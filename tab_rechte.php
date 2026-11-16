<?php
/**
 * tab_rechte.php - Kleine Rechteverwaltung: wer hat welche Rolle
 * (mvmitglieder.Red) und/oder ist Vorstandsmitglied (mvmitglieder.Vorstand)?
 * Ersetzt den bisherigen direkten Datenbankeintrag.
 *
 * Red und Vorstand sind im Bestandsschema zwei unabhängige Felder (siehe
 * mvantraege.php: "if ($r['Red'] OR $r['Vorstand'])") - eine Person kann
 * z. B. gleichzeitig Protokollführung UND Vorstandsmitglied sein. Deshalb
 * getrennt als Rollen-Auswahl (exklusiv) und Vorstand-Haken (unabhängig).
 *
 * Zeigt bewusst nur Mitglieder MIT einer Rolle/Vorstandsamt plus eine
 * Suche, statt alle Mitglieder (mehrere hundert) auf einmal zu laden.
 */

$tabelle = 'mvmitglieder_' . (int)$jahr;
$rollen = get_rollen();

$stmt = $pdo->prepare("SELECT MNr, Vorname, Name, Red, Vorstand FROM `$tabelle` WHERE Red <> 0 OR Vorstand <> 0 ORDER BY Vorstand DESC, Red, Name");
$stmt->execute();
$inhaber = $stmt->fetchAll();

$suche = trim($_GET['suche'] ?? '');
$treffer = [];
if ($suche !== '') {
    $stmt = $pdo->prepare(
        "SELECT MNr, Vorname, Name, Red, Vorstand FROM `$tabelle`
         WHERE MNr LIKE ? OR Name LIKE ? OR Vorname LIKE ?
         ORDER BY Name LIMIT 20"
    );
    $wie = '%' . $suche . '%';
    $stmt->execute([$wie, $wie, $wie]);
    $treffer = $stmt->fetchAll();
}

/** Ein Formular-Zeile fuer ein Mitglied - fuer die Inhaber-Liste und die Suchtreffer identisch. */
function rechte_zeile(array $m): void {
    global $rollen;
    ?>
    <form method="post" class="top-row" style="flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="bereich" value="rechte">
        <input type="hidden" name="mnr" value="<?= h($m['MNr']) ?>">
        <span class="top-title" style="flex:1 1 auto;"><?= h($m['Vorname'] . ' ' . $m['Name']) ?> <span style="color:var(--text-muted); font-weight:400;">(<?= h($m['MNr']) ?>)</span></span>
        <select name="rolle">
            <?php foreach ($rollen as $wert => $label): ?>
                <option value="<?= $wert ?>" <?= (int)$m['Red'] === $wert ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
        <label style="font-weight:400; white-space:nowrap;">
            <input type="checkbox" name="vorstand" value="1" <?= !empty($m['Vorstand']) ? 'checked' : '' ?>> Vorstand
        </label>
        <button type="submit" class="btn" style="padding:6px 12px; font-size:12.5px;">Speichern</button>
        <?php if ((int)$m['Red'] !== 0 || !empty($m['Vorstand'])): ?>
            <button type="submit" name="entfernen" value="1" class="btn-secondary" style="padding:6px 10px; font-size:12.5px; border-radius:6px; border:none; color:white; cursor:pointer;"
                    onclick="return confirm('Rolle und Vorstandsamt von <?= h(addslashes($m['Vorname'] . ' ' . $m['Name'])) ?> wirklich entfernen?');">🗑️ Entfernen</button>
        <?php endif; ?>
    </form>
    <?php
}
?>
<h3>Aktuell vergebene Rollen &amp; Vorstandsamt</h3>

<?php if (!$inhaber): ?>
    <div class="info-box">Noch niemandem ist eine Rolle oder ein Vorstandsamt zugewiesen.</div>
<?php else: ?>
<div class="top-list">
    <?php foreach ($inhaber as $m): rechte_zeile($m); endforeach; ?>
</div>
<?php endif; ?>

<h3>Rolle / Vorstandsamt zuweisen</h3>
<form method="get" style="margin-bottom:14px;">
    <input type="hidden" name="tab" value="admin">
    <input type="hidden" name="bereich" value="rechte">
    <input type="text" name="suche" placeholder="Name oder Mitgliedsnummer" value="<?= h($suche) ?>" style="max-width:280px; display:inline-block;">
    <button type="submit" class="btn btn-secondary">Suchen</button>
</form>

<?php if ($suche !== ''): ?>
    <?php if (!$treffer): ?>
        <div class="info-box">Keine Treffer für „<?= h($suche) ?>“.</div>
    <?php else: ?>
        <div class="top-list">
            <?php foreach ($treffer as $m): rechte_zeile($m); endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
