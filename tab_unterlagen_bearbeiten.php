<?php
/**
 * tab_unterlagen_bearbeiten.php - Redaktionsmaske fuer "Unterlagen
 * <Jahr>": Titel, Kurzbeschreibung, Link, "zeigen" (schonda), Farbe
 * (Gruppierung) und Spaltenumbruch je Eintrag, plus Reihenfolge (lfdnr)
 * und "Aus Vorjahr übernehmen" (erscheint erst, solange <2 Einträge
 * vorliegen). Verwaltet zusaetzlich die allgemeinen, jahresuebergreifenden
 * Dokumente (frueher eigener Tab "Weitere Unterlagen", jahr=0 als Sentinel -
 * siehe unterlagen_functions.php) in einem eigenen Abschnitt auf derselben
 * Seite. Nur erreichbar fuer ist_redaktion() (siehe tab_unterlagen.php).
 */

weitere_unterlagen_seed_falls_leer($pdo);
$dateien_jahr = get_unterlagen($pdo, $jahr);
$dateien_allgemein = get_unterlagen($pdo, 0);
$vorjahr = vorjahr_von($pdo, $jahr);
?>

<div class="mv-redaktion-aktiv">
<div style="display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:8px;">
    <h2>Unterlagen <?= h((string)$jahr) ?> bearbeiten</h2>
    <a class="btn btn-secondary" href="?tab=unterlagen" style="padding:7px 14px; font-size:12.5px;">👁️ Ansicht</a>
</div>

<!-- Gemeinsames verstecktes Formular fuer alle Loeschen-Buttons unten (beide
     Gruppen) - Formulare duerfen in HTML nicht verschachtelt werden. -->
<form method="post" onsubmit="return confirm('Diese Unterlage wirklich löschen?');" id="loeschform">
    <?= csrf_field() ?>
    <input type="hidden" name="aktion" value="loeschen">
    <input type="hidden" name="id" id="loeschform_id" value="">
    <input type="hidden" name="ziel_jahr" id="loeschform_ziel_jahr" value="">
</form>

<h3 style="margin-top:0;">Jahresspezifisch (<?= h((string)$jahr) ?>)</h3>

<?php if (count($dateien_jahr) < 2 && $vorjahr !== null): ?>
    <form method="post" style="margin-bottom:16px;" onsubmit="return confirm('Unterlagen aus <?= h((string)$vorjahr) ?> übernehmen (Links werden auf <?= h((string)$jahr) ?> angepasst, „zeigen“ startet deaktiviert)?');">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="vorjahr_uebernehmen">
        <button type="submit" class="btn btn-secondary">📋 Aus Vorjahr (<?= h((string)$vorjahr) ?>) übernehmen</button>
    </form>
<?php endif; ?>

<?php if (!$dateien_jahr): ?>
    <div class="info-box">Für <?= h((string)$jahr) ?> sind noch keine Unterlagen hinterlegt.</div>
<?php else: ?>
    <div class="top-list" style="margin-bottom:16px;">
        <?php render_unterlagen_bearbeiten_zeilen($dateien_jahr, $jahr); ?>
    </div>
<?php endif; ?>

<h4>Neue jahresspezifische Unterlage hinzufügen</h4>
<form method="post" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:24px;">
    <?= csrf_field() ?>
    <input type="hidden" name="aktion" value="hinzufuegen">
    <input type="hidden" name="ziel_jahr" value="<?= (int)$jahr ?>">
    <input type="text" name="titel" placeholder="Titel" style="flex:1 1 160px; padding:7px 10px;">
    <input type="text" name="beschreibung" placeholder="Kurzbeschreibung" style="flex:1 1 180px; padding:7px 10px;">
    <input type="text" name="link" placeholder="Link (URL)" style="flex:1 1 180px; padding:7px 10px;">
    <button type="submit" class="btn">Hinzufügen</button>
</form>

<h3>Allgemein (jahresübergreifend, erscheinen bei allen Jahren mit)</h3>
<p style="font-size:12.5px; color:var(--text-secondary); margin-top:-8px;">
    Diese Dokumente sind nicht an ein bestimmtes MV-Jahr gebunden und werden auf der Unterlagen-Seite jedes Jahres zusätzlich angezeigt - z. B. allgemeine Vereinsinformationen. Frühere Version: eigener Tab „Weitere Unterlagen“.
</p>

<?php if (!$dateien_allgemein): ?>
    <div class="info-box">Noch keine allgemeinen Unterlagen hinterlegt.</div>
<?php else: ?>
    <div class="top-list" style="margin-bottom:16px;">
        <?php render_unterlagen_bearbeiten_zeilen($dateien_allgemein, 0); ?>
    </div>
<?php endif; ?>

<h4>Neue allgemeine Unterlage hinzufügen</h4>
<form method="post" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px;">
    <?= csrf_field() ?>
    <input type="hidden" name="aktion" value="hinzufuegen">
    <input type="hidden" name="ziel_jahr" value="0">
    <input type="text" name="titel" placeholder="Titel" style="flex:1 1 160px; padding:7px 10px;">
    <input type="text" name="beschreibung" placeholder="Kurzbeschreibung" style="flex:1 1 180px; padding:7px 10px;">
    <input type="text" name="link" placeholder="Link (URL)" style="flex:1 1 180px; padding:7px 10px;">
    <button type="submit" class="btn">Hinzufügen</button>
</form>

<p style="color:var(--text-muted); font-size:12.5px; margin-top:-14px;">
    Neue Einträge starten mit „zeigen“ deaktiviert und werden nach dem Anlegen oben in der jeweiligen Liste sichtbar. „Neue Spalte ab hier“ markiert den Beginn der rechten Spalte in der zweispaltigen Ansicht (ab Laptop-Breite) - nur bei einem Eintrag insgesamt sinnvoll gesetzt.
</p>
</div>
