<?php
/**
 * tab_top_beamerinhalte.php - Die im Vorfeld vom Redaktionsteam vorbereiteten
 * Beamerinhalte eines einzelnen TOP bearbeiten: die Folie, die beim Aufruf
 * des TOP durch die Versammlungsleitung gezeigt wird, und den zugehörigen
 * Beschlussentwurf. Eingebunden von tab_tagesordnung_bearbeiten.php, wenn
 * ?top_id=...&ansicht=beamer gesetzt ist. Gezeigt werden sie während der
 * Versammlung über die Beamersteuerung, nicht von hier aus.
 */

$top_id = (int)($_GET['top_id'] ?? 0);
$top = get_top($pdo, $top_id);

if (!$top || (int)$top['jahr'] !== $jahr) {
    echo '<div class="info-box">Dieser Tagesordnungspunkt wurde nicht gefunden.</div>';
    echo '<a class="btn btn-secondary" href="?tab=tagesordnung&modus=bearbeiten">Zurück zur Tagesordnung</a>';
    return;
}
?>

<div class="mv-redaktion-aktiv">
<div style="display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:8px;">
    <h2>Beamerinhalte: <?= h($top['titel'] !== '' ? $top['titel'] : '(ohne Titel)') ?></h2>
    <a class="btn btn-secondary" href="?tab=tagesordnung&modus=bearbeiten" style="padding:7px 14px; font-size:12.5px;">← Zurück zur Tagesordnung</a>
</div>

<p style="font-size:13px; color:var(--text-secondary);">
    Gezeigt werden diese Inhalte während der Versammlung über die Beamersteuerung - dort erscheinen für jeden TOP eigene Links „TOP …“ und „Beschlussentwurf“.
</p>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="aktion" value="top_inhalte_speichern">
    <input type="hidden" name="top_id" value="<?= $top_id ?>">
    <input type="hidden" name="id" value="<?= $top_id ?>">
    <input type="hidden" name="ansicht" value="beamer">

    <div class="form-group" style="margin-bottom:18px;">
        <label>TOP-Folie <span style="font-weight:400; color:var(--text-secondary);">(beim Aufruf des TOP durch die Versammlungsleitung - leer: automatisch erzeugte schlichte Titel-Folie)</span></label>
        <textarea name="top_folie" rows="6" style="width:100%; max-width:600px; padding:8px; font-family:inherit;" placeholder="Eigenen Text für die TOP-Folie eingeben …"><?= h((string)($top['top_folie'] ?? '')) ?></textarea>
    </div>

    <div class="form-group" style="margin-bottom:18px;">
        <label>Beschlussentwurf <span style="font-weight:400; color:var(--text-secondary);">(leer: Hinweis "liegt noch kein Beschlussentwurf vor")</span></label>
        <textarea name="beschlussentwurf" rows="6" style="width:100%; max-width:600px; padding:8px; font-family:inherit;" placeholder="Text des Beschlussentwurfs eingeben …"><?= h((string)($top['beschlussentwurf'] ?? '')) ?></textarea>
    </div>

    <button type="submit" class="btn">Speichern</button>
</form>
</div>
