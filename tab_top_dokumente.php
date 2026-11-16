<?php
/**
 * tab_top_dokumente.php - Dokumente/Links eines einzelnen TOP verwalten.
 * Eingebunden von tab_tagesordnung_bearbeiten.php, wenn ?top_id=... gesetzt ist.
 */

$top_id = (int)($_GET['top_id'] ?? 0);
$top = get_top($pdo, $top_id);

if (!$top || (int)$top['jahr'] !== $jahr) {
    echo '<div class="info-box">Dieser Tagesordnungspunkt wurde nicht gefunden.</div>';
    echo '<a class="btn btn-secondary" href="?tab=tagesordnung&modus=bearbeiten">Zurück zur Tagesordnung</a>';
    return;
}

$dokumente = get_top_dokumente($pdo, $top_id);
$status_labels = [
    'abrufbar'     => 'Abrufbar',
    'vorbereitung' => 'Noch in Vorbereitung',
    'verborgen'    => 'Nicht zeigen',
];
?>

<div class="mv-redaktion-aktiv">
<div style="display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:8px;">
    <h2>Dokumente: <?= h($top['titel'] !== '' ? $top['titel'] : '(ohne Titel)') ?></h2>
    <a class="btn btn-secondary" href="?tab=tagesordnung&modus=bearbeiten" style="padding:7px 14px; font-size:12.5px;">← Zurück zur Tagesordnung</a>
</div>

<form method="post" onsubmit="return confirm('Dieses Dokument wirklich entfernen?');" id="dokloeschform">
    <?= csrf_field() ?>
    <input type="hidden" name="aktion" value="dokument_loeschen">
    <input type="hidden" name="top_id" value="<?= $top_id ?>">
    <input type="hidden" name="id" id="dokloeschform_id" value="">
</form>

<?php if (!$dokumente): ?>
    <div class="info-box">Diesem Punkt sind noch keine Dokumente zugeordnet.</div>
<?php else: ?>
    <div class="top-list">
        <?php foreach ($dokumente as $doc): ?>
            <div class="top-row" style="flex-wrap:wrap; gap:6px 10px;">
                <span class="top-title" style="flex:1 1 160px;">
                    <?= $doc['ist_datei'] ? '📁' : '🔗' ?> <?= h($doc['titel']) ?>
                </span>
                <?php if ($doc['url'] !== ''): ?>
                    <a href="<?= h($doc['url']) ?>" target="_blank" rel="noopener" style="font-size:12.5px;">öffnen</a>
                <?php endif; ?>

                <form method="post" style="display:contents;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aktion" value="dokument_status">
                    <input type="hidden" name="top_id" value="<?= $top_id ?>">
                    <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
                    <select name="status" onchange="this.form.submit()">
                        <?php foreach ($status_labels as $wert => $label): ?>
                            <option value="<?= $wert ?>" <?= $doc['status'] === $wert ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <button type="submit" form="dokloeschform" class="btn-secondary" style="padding:5px 10px; font-size:12px; border-radius:6px; border:none; color:white; cursor:pointer;"
                        onclick="document.getElementById('dokloeschform_id').value='<?= (int)$doc['id'] ?>';">🗑️</button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h3>Dokument hinzufügen</h3>
<form method="post" enctype="multipart/form-data" style="max-width:420px;">
    <?= csrf_field() ?>
    <input type="hidden" name="aktion" value="dokument_hinzufuegen">
    <input type="hidden" name="top_id" value="<?= $top_id ?>">

    <div class="form-group" style="margin-bottom:10px;">
        <label>Titel</label>
        <input type="text" name="titel" required>
    </div>

    <div class="form-group" style="margin-bottom:10px;">
        <label>Datei hochladen (PDF, Word, Excel, Bild, Text)</label>
        <input type="file" name="datei">
    </div>

    <div class="form-group" style="margin-bottom:10px;">
        <label>— oder externer Link —</label>
        <input type="text" name="url" placeholder="https://...">
    </div>

    <div class="form-group" style="margin-bottom:14px;">
        <label>Sichtbarkeit</label>
        <select name="status">
            <?php foreach ($status_labels as $wert => $label): ?>
                <option value="<?= $wert ?>" <?= $wert === 'vorbereitung' ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <button type="submit" class="btn">Hinzufügen</button>
</form>
</div>
