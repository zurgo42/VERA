<?php
/**
 * tab_tagesordnung.php - Startseiten-Inhalt: die Tagesordnung.
 *
 * Lese-Ansicht fuer alle (auch Gaeste), plus Einstieg in die
 * Redaktionsmaske (?modus=bearbeiten) fuer das Redaktionsteam.
 */

require_once __DIR__ . '/tagesordnung_functions.php';

if (!empty($_SESSION['admin_erfolg'])) {
    echo '<div class="info-box">' . h($_SESSION['admin_erfolg']) . '</div>';
    unset($_SESSION['admin_erfolg']);
}
if (!empty($_SESSION['admin_fehler'])) {
    echo '<div class="info-box" style="border-left-color:var(--danger); color:var(--danger);">' . h($_SESSION['admin_fehler']) . '</div>';
    unset($_SESSION['admin_fehler']);
}

if ($ist_redaktion && ($_GET['modus'] ?? '') === 'bearbeiten') {
    include __DIR__ . '/tab_tagesordnung_bearbeiten.php';
    return;
}

$top_liste = get_tagesordnung($pdo, $jahr);
$nr = 0;
?>

<div style="display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:8px;">
    <h2>Tagesordnung</h2>
    <?php if ($ist_redaktion): ?>
        <a class="btn btn-secondary" href="?tab=tagesordnung&modus=bearbeiten" style="padding:7px 14px; font-size:12.5px;">✏️ Bearbeiten</a>
    <?php endif; ?>
</div>

<?php if (!$top_liste): ?>
    <div class="info-box">
        Für <?= h((string)$jahr) ?> ist noch keine Tagesordnung hinterlegt.
        <?php if ($ist_redaktion): ?> Als Redaktionsteam-Mitglied kannst du sie unter „Bearbeiten“ anlegen.<?php endif; ?>
    </div>
<?php else: ?>
    <div class="top-list">
        <?php foreach ($top_liste as $top): ?>
            <?php if (!$top['aktiv']) continue; ?>
            <?php $nr++; ?>
            <div class="top-row" style="flex-wrap:wrap;">
                <span class="top-nr"><?= $nr ?></span>
                <span class="top-title"><?= h($top['titel']) ?></span>
                <span class="top-docs-inline">
                    <?php foreach ($top['dokumente'] as $doc): ?>
                        <?php if ($doc['status'] === 'abrufbar'): ?>
                            <a href="<?= h($doc['url']) ?>" target="_blank" rel="noopener">📎 <?= h($doc['titel']) ?></a>
                        <?php elseif ($doc['status'] === 'vorbereitung'): ?>
                            <span style="color:var(--text-muted);">📎 <?= h($doc['titel']) ?> (in Vorbereitung)</span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
