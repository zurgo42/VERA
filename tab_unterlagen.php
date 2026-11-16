<?php
/**
 * tab_unterlagen.php - "Unterlagen <Jahr>" (frueher "Allgemeine
 * Unterlagen"; wie mv.php-Akkordeon, gespeist aus der Tabelle mvdateien).
 * Zeigt zusaetzlich die jahresunabhaengigen, allgemeinen Dokumente (frueher
 * eigener Tab "Weitere Unterlagen", jahr=0 als Sentinel - siehe
 * unterlagen_functions.php) mit an, dahinter angehaengt.
 *
 * Ab Laptop-Breite zweispaltig: der Umbruch zur rechten Spalte steht am
 * ersten Dokument mit gesetztem "spaltenumbruch"-Haekchen (siehe
 * unterlagen_in_spalten()) - typischerweise das erste der allgemeinen
 * Dokumente, so dass rechts eher die jahresuebergreifenden Unterlagen
 * stehen. Je Dokument waehlbare Hintergrundfarbe (weiß/hellblau/hellgrau)
 * zur freien Gruppierung.
 *
 * Lese-Ansicht fuer alle (auch Gaeste, nur Eintraege mit "zeigen"), plus
 * Einstieg in die Redaktionsmaske (?modus=bearbeiten) fuer das
 * Redaktionsteam.
 */

require_once __DIR__ . '/unterlagen_functions.php';

if (!empty($_SESSION['admin_erfolg'])) {
    echo '<div class="info-box">' . h($_SESSION['admin_erfolg']) . '</div>';
    unset($_SESSION['admin_erfolg']);
}
if (!empty($_SESSION['admin_fehler'])) {
    echo '<div class="info-box" style="border-left-color:var(--danger); color:var(--danger);">' . h($_SESSION['admin_fehler']) . '</div>';
    unset($_SESSION['admin_fehler']);
}

if ($ist_redaktion && ($_GET['modus'] ?? '') === 'bearbeiten') {
    include __DIR__ . '/tab_unterlagen_bearbeiten.php';
    return;
}

weitere_unterlagen_seed_falls_leer($pdo);
$dateien = array_merge(get_unterlagen($pdo, $jahr, true), get_unterlagen($pdo, 0, true));
[$spalte1, $spalte2] = unterlagen_in_spalten($dateien);
$unterlagentext = trim((string)($mvtexte['unterlagentext'] ?? ''));
$unterlagentext_default = 'Zur ' . $config['mv_bezeichnung'] . ' ' . $jahr . ' stehen folgende Unterlagen zur Verfügung';
?>

<div style="display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:8px;">
    <h2>Unterlagen <?= h((string)$jahr) ?></h2>
    <?php if ($ist_redaktion): ?>
        <a class="btn btn-secondary" href="?tab=unterlagen&modus=bearbeiten" style="padding:7px 14px; font-size:12.5px;">✏️ Bearbeiten</a>
    <?php endif; ?>
</div>

<div class="top-item">
    <?= format_antragstext($unterlagentext !== '' ? $unterlagentext : $unterlagentext_default) ?>
</div>

<?php if ($ist_redaktion): ?>
    <details style="margin-bottom:16px;">
        <summary style="cursor:pointer; color:var(--primary); font-weight:600;">✏️ Diesen einleitenden Text verändern</summary>
        <form method="post" style="margin-top:10px;">
            <?= csrf_field() ?>
            <input type="hidden" name="feld" value="unterlagentext">
            <input type="hidden" name="rueck_tab" value="unterlagen">
            <div class="form-group" style="margin-bottom:12px;">
                <label>Text für <?= h((string)$jahr) ?> (leer lassen für den Standardtext „<?= h($unterlagentext_default) ?>“)</label>
                <textarea name="text" rows="3" style="width:100%; padding:8px 10px; font-family:inherit;"><?= h($unterlagentext) ?></textarea>
            </div>
            <button type="submit" class="btn">Speichern</button>
        </form>
    </details>
<?php endif; ?>

<?php if (!$dateien): ?>
    <div class="info-box">
        Für <?= h((string)$jahr) ?> sind noch keine Unterlagen hinterlegt.
        <?php if ($ist_redaktion): ?> Als Redaktionsteam-Mitglied kannst du sie unter „Bearbeiten“ anlegen.<?php endif; ?>
    </div>
<?php else: ?>
    <div class="unterlagen-spalten">
        <div class="unterlagen-spalte top-list">
            <?php render_unterlagen_zeilen($spalte1); ?>
        </div>
        <?php if ($spalte2): ?>
            <div class="unterlagen-spalte top-list">
                <?php render_unterlagen_zeilen($spalte2); ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
