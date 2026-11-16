<?php
/**
 * tab_tagesordnung_bearbeiten.php - Redaktionsmaske fuer die Tagesordnung.
 * Nur erreichbar fuer ist_redaktion() (siehe tab_tagesordnung.php).
 *
 * Dokumente und Beamerinhalte je TOP werden auf eigenen Unterseiten verwaltet
 * (tab_top_dokumente.php, tab_top_beamerinhalte.php), da HTML-Formulare sich
 * nicht verschachteln lassen und jede Zeile hier schon ein eigenes Formular
 * ist.
 */

if (isset($_GET['top_id']) && ($_GET['ansicht'] ?? '') === 'beamer') {
    include __DIR__ . '/tab_top_beamerinhalte.php';
    return;
}
if (isset($_GET['top_id'])) {
    include __DIR__ . '/tab_top_dokumente.php';
    return;
}

$top_liste = get_tagesordnung($pdo, $jahr);
$vorjahr = $jahr - 1;
?>

<div class="mv-redaktion-aktiv">
<div style="display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:8px;">
    <h2>Tagesordnung bearbeiten</h2>
    <a class="btn btn-secondary" href="?tab=tagesordnung" style="padding:7px 14px; font-size:12.5px;">👁️ Ansicht</a>
</div>

<?php if (!$top_liste): ?>
    <div class="info-box">Für <?= h((string)$jahr) ?> ist noch keine Tagesordnung angelegt.</div>
    <form method="post" style="display:inline-block; margin-right:10px;">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="standard_laden">
        <button type="submit" class="btn">Standard-Tagesordnung laden</button>
    </form>
    <form method="post" style="display:inline-block;">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="vorjahr_uebernehmen">
        <button type="submit" class="btn btn-secondary">Aus <?= h((string)$vorjahr) ?> übernehmen</button>
    </form>

<?php else: ?>

    <!-- Gemeinsames verstecktes Formular fuer alle Loeschen-Buttons unten
         (Formulare duerfen in HTML nicht verschachtelt werden, daher ein
         zentrales Formular statt eines pro Zeile). -->
    <form method="post" onsubmit="return confirm('Diesen Tagesordnungspunkt wirklich löschen? (mit allen zugeordneten Dokumenten)');" id="loeschform">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="top_loeschen">
        <input type="hidden" name="id" id="loeschform_id" value="">
    </form>

    <div class="top-list">
        <?php foreach ($top_liste as $i => $top): ?>
            <div class="top-row" style="flex-wrap:wrap; gap:6px 10px;">
                <form method="post" style="display:contents;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aktion" value="top_speichern">
                    <input type="hidden" name="id" value="<?= (int)$top['id'] ?>">

                    <input type="checkbox" name="aktiv" <?= $top['aktiv'] ? 'checked' : '' ?> title="Erscheint auf der Tagesordnung">
                    <input type="text" name="titel" value="<?= h($top['titel']) ?>" style="flex:1 1 200px; padding:5px 8px; font-size:13.5px;">
                    <button type="submit" class="btn" style="padding:5px 10px; font-size:12px;">Speichern</button>
                </form>

                <form method="post" style="display:contents;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$top['id'] ?>">
                    <input type="hidden" name="richtung" value="hoch">
                    <button type="submit" name="aktion" value="top_verschieben" class="btn btn-secondary" style="padding:5px 8px; font-size:12px;" <?= $i === 0 ? 'disabled' : '' ?>>▲</button>
                </form>
                <form method="post" style="display:contents;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$top['id'] ?>">
                    <input type="hidden" name="richtung" value="runter">
                    <button type="submit" name="aktion" value="top_verschieben" class="btn btn-secondary" style="padding:5px 8px; font-size:12px;" <?= $i === count($top_liste) - 1 ? 'disabled' : '' ?>>▼</button>
                </form>

                <a class="btn btn-secondary" style="padding:5px 10px; font-size:12px;" href="?tab=tagesordnung&modus=bearbeiten&top_id=<?= (int)$top['id'] ?>">
                    📎 Dokumente (<?= count($top['dokumente']) ?>)
                </a>
                <a class="btn btn-secondary" style="padding:5px 10px; font-size:12px;" href="?tab=tagesordnung&modus=bearbeiten&top_id=<?= (int)$top['id'] ?>&ansicht=beamer">
                    📽️ Beamerinhalte
                </a>

                <button type="submit" form="loeschform" class="btn-secondary" style="padding:5px 10px; font-size:12px; border-radius:6px; border:none; color:white; cursor:pointer;"
                        onclick="document.getElementById('loeschform_id').value='<?= (int)$top['id'] ?>';">🗑️</button>
            </div>
        <?php endforeach; ?>
    </div>

    <h3>Neuen Punkt hinzufügen</h3>
    <form method="post" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px;">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="top_hinzufuegen">
        <input type="text" name="titel" placeholder="Titel des neuen Punkts" style="flex:1 1 220px; padding:7px 10px;">
        <button type="submit" class="btn">Hinzufügen</button>
    </form>

<?php endif; ?>
</div>
